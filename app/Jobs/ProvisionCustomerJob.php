<?php

namespace App\Jobs;

use App\Models\BulkProvisionRun;
use App\Services\CustomerProvisioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Provisiona UN cliente al MikroTik en segundo plano y acumula el resultado en
 * el registro de progreso (bulk_provision_runs). Se despacha uno por cliente,
 * así cada job dura ~17-34s (bajo cualquier timeout de worker) y el conjunto se
 * procesa sin tocar el límite de ~60s del gateway HTTP.
 */
class ProvisionCustomerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Un solo intento: el SSH no es idempotente de forma trivial y reintentar duplicaría el trabajo lento. */
    public int $tries = 1;

    /** Margen sobre el peor caso (~34s queue+secret). */
    public int $timeout = 120;

    public function __construct(
        public string $runId,
        public int $customerId,
        public int $tenantId,
    ) {
    }

    public function handle(CustomerProvisioningService $provisioner): void
    {
        try {
            $row = $provisioner->provisionOne($this->customerId, $this->tenantId);
        } catch (\Throwable $e) {
            \Log::error('[ProvisionCustomerJob] Excepción no controlada', [
                'run_id'      => $this->runId,
                'customer_id' => $this->customerId,
                'error'       => $e->getMessage(),
            ]);
            $row = [
                'customer_id' => $this->customerId,
                'success'     => false,
                'message'     => 'Error inesperado: ' . $e->getMessage(),
            ];
        }

        $this->recordResult($row);
    }

    /**
     * Si el job falla definitivamente (timeout, etc.), igual avanzamos el
     * contador para que el progreso llegue a 100% y el frontend no quede colgado.
     */
    public function failed(\Throwable $e): void
    {
        $this->recordResult([
            'customer_id' => $this->customerId,
            'success'     => false,
            'message'     => 'El aprovisionamiento falló: ' . $e->getMessage(),
        ]);
    }

    private function recordResult(array $row): void
    {
        DB::transaction(function () use ($row) {
            /** @var BulkProvisionRun|null $run */
            $run = BulkProvisionRun::whereKey($this->runId)->lockForUpdate()->first();
            if (!$run || $run->status === 'done') {
                return;
            }

            $results = $run->results ?? [];
            $results[] = $row;
            $run->results = $results;

            $run->processed += 1;
            if (!empty($row['success'])) {
                $run->success_count += 1;
            } else {
                $run->fail_count += 1;
            }
            if (!empty($row['pppoe_skipped'])) {
                $run->pppoe_skipped_count += 1;
            }

            if ($run->processed >= $run->total) {
                $run->status = 'done';
                $run->finished_at = now();
            }

            $run->save();
        });
    }
}
