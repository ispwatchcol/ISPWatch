<?php

namespace App\Console\Commands;

use App\Services\OverdueSuspensionService;
use Illuminate\Console\Command;

class ProcessOverdueInvoices extends Command
{
    protected $signature = 'billing:process-overdue';
    protected $description = 'Process overdue invoices and suspend customers based on router cut_type';

    public function __construct(protected OverdueSuspensionService $suspensionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info("Processing overdue invoices...");

        try {
            $stats = $this->suspensionService->processOverdueInvoices();

            $this->table(
                ['Action', 'Count'],
                [
                    ['Suspended (Auto)', $stats['suspended']],
                    ['Manual Pending', $stats['manual_pending']],
                    ['No Action (Sin Corte)', $stats['no_action']],
                    ['Errors', $stats['errors']],
                ]
            );

            $this->info("Overdue processing complete");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to process overdue invoices: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
