<?php

namespace App\Console\Commands;

use App\Services\BillingService;
use Illuminate\Console\Command;

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

            $this->info("Successfully generated {$count} invoices" . ($period ? " for {$period}" : ''));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to generate invoices: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
