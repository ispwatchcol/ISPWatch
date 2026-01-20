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
        $period = $this->argument('period') ?? now()->format('Y-m');

        $this->info("Generating monthly invoices for period: {$period}");

        try {
            $count = $this->billingService->generateMonthlyInvoices($period);

            $this->info("Successfully generated {$count} invoices for {$period}");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to generate invoices: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
