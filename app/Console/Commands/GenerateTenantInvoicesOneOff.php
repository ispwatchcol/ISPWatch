<?php

namespace App\Console\Commands;

use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Router;
use App\Models\UserService;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ONE-OFF ops command (safe to delete after use).
 *
 * Generates monthly plan invoices for EVERY active, billable customer of a
 * tenant, bypassing the per-router billing config gating (create day / hour).
 * Needed when a tenant's routers have no billing config yet but invoices must
 * still be issued for a period.
 *
 * - Idempotent: skips customers that already have an invoice for the period.
 * - Silent: never sends email/WhatsApp (no notifyInvoiceCreated call).
 * - Faithful: mirrors BillingService::createMonthlyInvoiceFor (number, item,
 *   credit application) minus the notification.
 */
class GenerateTenantInvoicesOneOff extends Command
{
    protected $signature = 'billing:generate-tenant {tenant} {period} {--dry-run}';
    protected $description = 'One-off: issue monthly invoices for all active billable customers of a tenant (no notifications).';

    public function __construct(protected BillingService $billing)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant');
        $period   = $this->argument('period'); // YYYY-MM
        $dry      = (bool) $this->option('dry-run');

        $periodMonth = Carbon::parse($period . '-01');
        $periodStart = $periodMonth->copy()->startOfMonth()->startOfDay();
        $periodEnd   = $periodMonth->copy()->endOfMonth()->startOfDay();
        $issueDate   = now()->startOfDay();
        $dueDate     = $issueDate->copy()->addDays(5);

        $routerIds = Router::where('tenant_id', $tenantId)->pluck('id')->all();
        if (empty($routerIds)) {
            $this->error("Tenant {$tenantId} has no routers.");
            return self::FAILURE;
        }

        $profiles = CustomerProfile::whereIn('router_id', $routerIds)
            ->where('status', true)
            ->get()
            ->keyBy('user_id');

        $userIds = $profiles->keys()->all();

        // Bulk-load active services grouped by user (first wins, mirrors service).
        $services = UserService::whereIn('user_id', $userIds)
            ->where('status', UserService::STATUS_ACTIVE)
            ->with('servicePlan')
            ->get()
            ->groupBy('user_id');

        // Bulk-load existing invoices for this period → idempotent skip.
        $existing = Invoice::where('tenant_id', $tenantId)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->pluck('customer_id')
            ->flip();

        $toCreate = [];
        $skipExisting = $skipNoService = $skipCourtesy = $skipNoPlan = 0;

        foreach ($profiles as $userId => $profile) {
            if ($existing->has($userId)) { $skipExisting++; continue; }
            $us = optional($services->get($userId))->first();
            if (!$us) { $skipNoService++; continue; }
            $plan = $us->servicePlan;
            if (!$plan) { $skipNoPlan++; continue; }
            if ($plan->is_courtesy) { $skipCourtesy++; continue; }
            $toCreate[] = [$profile, $plan];
        }

        $this->info("Tenant {$tenantId} | period {$period} | routers: " . implode(',', $routerIds));
        $this->info("Active profiles: {$profiles->count()}");
        $this->info("To create: " . count($toCreate));
        $this->info("Skip -> existing: {$skipExisting} | no active service: {$skipNoService} | courtesy: {$skipCourtesy} | service w/o plan: {$skipNoPlan}");

        if ($dry) {
            $this->warn('DRY RUN — no invoices created.');
            return self::SUCCESS;
        }

        $created = 0; $failed = 0; $creditApplied = 0;
        $bar = $this->output->createProgressBar(count($toCreate));
        $bar->start();

        foreach ($toCreate as [$profile, $plan]) {
            try {
                $subtotal = (float) ($plan->cost_product ?? 0);
                $number   = $this->billing->generateInvoiceNumber($tenantId);

                $invoice = Invoice::create([
                    'tenant_id'    => $tenantId,
                    'customer_id'  => $profile->user_id,
                    'service_id'   => $plan->id,
                    'number'       => $number,
                    'issue_date'   => $issueDate,
                    'due_date'     => $dueDate,
                    'period_start' => $periodStart,
                    'period_end'   => $periodEnd,
                    'currency'     => 'COP',
                    'subtotal'     => $subtotal,
                    'tax'          => 0,
                    'total'        => $subtotal,
                    'balance_due'  => $subtotal,
                    'status'       => 'issued',
                ]);

                InvoiceItem::create([
                    'invoice_id'  => $invoice->id,
                    'type'        => 'plan',
                    'description' => "Servicio mensual: {$plan->name}",
                    'quantity'    => 1,
                    'unit_price'  => $subtotal,
                    'amount'      => $subtotal,
                ]);

                // Apply available customer credit, mirroring BillingService.
                if ($profile->credit_balance > 0 && $invoice->balance_due > 0) {
                    $apply = min((float) $profile->credit_balance, (float) $invoice->balance_due);
                    $invoice->balance_due -= $apply;
                    $invoice->status = $invoice->balance_due <= 0
                        ? 'paid'
                        : ($invoice->balance_due < $invoice->total ? 'partial' : 'issued');
                    $invoice->save();

                    $profile->credit_balance -= $apply;
                    $profile->save();
                    $creditApplied++;
                }

                $created++;
            } catch (\Throwable $e) {
                $failed++;
                Log::error("one-off tenant billing: customer {$profile->user_id} failed: {$e->getMessage()}");
                $this->newLine();
                $this->error("customer {$profile->user_id}: {$e->getMessage()}");
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done. Created: {$created} | Failed: {$failed} | Credit applied on: {$creditApplied}");

        return self::SUCCESS;
    }
}
