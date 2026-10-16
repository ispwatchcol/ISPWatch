<?php

namespace App\Console\Commands;

use App\Models\BillingActionLog;
use App\Services\BillingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RetryFailedInvoices extends Command
{
    protected $signature = 'billing:retry-failed
                            {--limit=200 : Maximum rows to retry in one run}
                            {--log= : Retry a single specific log id (ignores backoff)}';

    protected $description = 'Retry monthly invoice creations that failed and are due for another attempt';

    public function __construct(protected BillingService $billingService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $singleLogId = $this->option('log');

        if ($singleLogId) {
            $log = BillingActionLog::find($singleLogId);
            if (!$log) {
                $this->error("Log id {$singleLogId} not found.");
                return Command::FAILURE;
            }

            // Force a retry even if next_retry_at hasn't elapsed (manual override).
            if ($log->status === BillingActionLog::STATUS_EXHAUSTED) {
                $log->update([
                    'status'        => BillingActionLog::STATUS_FAILED,
                    'attempts'      => max(0, BillingActionLog::MAX_ATTEMPTS - 1),
                    'next_retry_at' => null,
                ]);
                $log->refresh();
            } else {
                $log->update(['next_retry_at' => null]);
                $log->refresh();
            }

            if ($log->action !== BillingActionLog::ACTION_GENERATE_MONTHLY) {
                $this->error("Log id {$singleLogId} es de '{$log->action}': lo reintenta la corrida mensual, no este comando.");
                return Command::FAILURE;
            }

            try {
                $ok = $this->billingService->retryFailedInvoice($log);
            } catch (\Throwable $e) {
                $this->billingService->recordRetryFailure($log, $e);
                $ok = false;
            }

            $this->info("Retry log {$singleLogId}: " . ($ok ? 'SUCCESS' : 'FAILED'));
            return $ok ? Command::SUCCESS : Command::FAILURE;
        }

        $limit = (int) $this->option('limit');

        // Sólo mensualidades: las facturas de "sólo servicios adicionales" las
        // reintenta la corrida horaria (ver ACTION_GENERATE_ADDITIONAL_ONLY);
        // retryFailedInvoice() las agotaría por no tener plan.
        $logs = BillingActionLog::where('status', BillingActionLog::STATUS_FAILED)
            ->where('action', BillingActionLog::ACTION_GENERATE_MONTHLY)
            ->where('attempts', '<', BillingActionLog::MAX_ATTEMPTS)
            ->where(function ($q) {
                $q->whereNull('next_retry_at')
                  ->orWhere('next_retry_at', '<=', now());
            })
            ->orderBy('next_retry_at')
            ->limit($limit)
            ->get();

        if ($logs->isEmpty()) {
            $this->info('No failed invoices ready for retry.');
            return Command::SUCCESS;
        }

        $this->info("Retrying {$logs->count()} failed invoice(s)...");
        Log::info("Billing retry: starting batch of {$logs->count()} log(s).");

        $ok = 0;
        $ko = 0;
        foreach ($logs as $log) {
            // Un reintento que revienta no frena a los demás del lote.
            try {
                $success = $this->billingService->retryFailedInvoice($log);
            } catch (\Throwable $e) {
                $this->billingService->recordRetryFailure($log, $e);
                $success = false;
            }

            $success ? $ok++ : $ko++;
        }

        $this->info("Done. Success: {$ok}, Still failing: {$ko}.");
        Log::info("Billing retry: batch complete. ok={$ok} ko={$ko}.");
        return Command::SUCCESS;
    }
}
