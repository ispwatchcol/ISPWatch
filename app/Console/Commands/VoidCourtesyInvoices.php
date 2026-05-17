<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Voids invoices that were issued to courtesy-plan customers for a given
 * period. Needed for the transition: before the courtesy -> 'gratis' change,
 * the monthly job invoiced courtesy customers as if they were paying. Voided
 * invoices (status = 'void', balance_due = 0) drop out of collections,
 * overdue tracking and the auto-cut suspension logic.
 *
 * Idempotent: already-void/cancelled invoices are left untouched.
 */
class VoidCourtesyInvoices extends Command
{
    protected $signature = 'billing:void-courtesy {period? : YYYY-MM, defaults to current month}';

    protected $description = 'Void invoices issued to courtesy-plan customers for a period';

    public function handle(): int
    {
        $period = $this->argument('period') ?: now()->format('Y-m');
        $periodStart = Carbon::parse($period . '-01')->startOfDay()->toDateString();

        $courtesyPlanIds = DB::table('service_plan')
            ->where('is_courtesy', true)
            ->pluck('id');

        if ($courtesyPlanIds->isEmpty()) {
            $this->info('No courtesy plans found. Nothing to void.');
            return self::SUCCESS;
        }

        $affected = DB::table('invoices')
            ->whereIn('service_id', $courtesyPlanIds)
            ->whereDate('period_start', $periodStart)
            ->whereNotIn('status', ['void', 'cancelled'])
            ->update([
                'status' => 'void',
                'balance_due' => 0,
                'updated_at' => now(),
            ]);

        $this->info("Voided {$affected} courtesy invoice(s) for {$period}.");

        return self::SUCCESS;
    }
}
