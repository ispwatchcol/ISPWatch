<?php

namespace App\Console\Commands;

use App\Services\BillingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateMonthlyInvoices extends Command
{
    protected $signature = 'billing:generate-monthly {period?}';
    protected $description = 'Generate monthly invoices for all active customers with active services';

    public function __construct(protected BillingService $billingService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Sin argumento el periodo lo resuelve cada router (modo anticipado /
        // vencido) y se respeta su HORA de creación. Pasar siempre un periodo
        // explícito — como se hacía antes — lo trataba como backfill manual y
        // saltaba el gate horario: las facturas salían a cualquier hora.
        $period = $this->argument('period');

        $this->info('Generating monthly invoices for period: ' . ($period ?? 'automático (según la config de cada router)'));

        try {
            $count = $this->billingService->generateMonthlyInvoices($period);
        } catch (\Throwable $e) {
            // El scheduler descarta la salida de consola: sin este log, una
            // corrida abortada no dejaba rastro en ninguna parte.
            Log::error("Billing: Failed to generate invoices: {$e->getMessage()}", ['exception' => $e]);
            $this->error("Failed to generate invoices: {$e->getMessage()}");

            return Command::FAILURE;
        }

        $failures = $this->billingService->lastRunFailures();

        if ($failures) {
            // Los demás clientes y routers sí se procesaron; éstos no. Quedan en
            // el log y, los clientes, en billing_action_logs para reintento.
            $this->warn("Generated {$count} invoices with " . count($failures) . ' error(s):');
            foreach ($failures as $f) {
                $this->warn('  router ' . $f['router_id']
                    . ($f['customer_id'] !== null ? ", customer {$f['customer_id']}" : ' (router entero)')
                    . ": {$f['error']}");
            }

            return Command::FAILURE;
        }

        $this->info("Successfully generated {$count} invoices" . ($period ? " for {$period}" : ''));

        return Command::SUCCESS;
    }
}
