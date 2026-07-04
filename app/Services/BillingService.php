<?php

namespace App\Services;

use App\Mail\InvoiceCreatedMail;
use App\Models\Billing;
use App\Models\BillingActionLog;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Plan;
use App\Models\Router;
use App\Models\SuspensionActionLog;
use App\Models\User;
use App\Models\UserService;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class BillingService
{
    public function __construct(protected WhatsAppService $whatsAppService)
    {
    }

    /**
     * Get the active service plan for a customer via user_services.
     *
     * @param int $userId
     * @return \App\Models\Plan|null
     */
    public function getActivePlan(int $userId)
    {
        $activeService = UserService::with('servicePlan')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->first();

        return $activeService?->servicePlan;
    }

    /**
     * Generate the next invoice number for a tenant (concurrency-safe).
     *
     * @param int $tenantId
     * @return string
     */
    public function generateInvoiceNumber(int $tenantId): string
    {
        return DB::transaction(function () use ($tenantId) {
            $tenant = Tenant::where('id', $tenantId)->lockForUpdate()->first();

            if (!$tenant) {
                throw new \Exception("Tenant not found: {$tenantId}");
            }

            $nextNumber = $tenant->next_invoice_number ?? 1;
            $invoiceNumber = str_pad($nextNumber, 8, '0', STR_PAD_LEFT);

            // Increment for next invoice
            $tenant->next_invoice_number = $nextNumber + 1;
            $tenant->save();

            return $invoiceNumber;
        });
    }

    /**
     * Generate monthly invoices based on each router's billing configuration.
     *
     * For each router that has a billing config (billing_router_id):
     *   1. Check if today's day-of-month >= the billing's create_invoice day
     *   2. Find all active customers assigned to that router
     *   3. Create an invoice for each customer with an active (non-gratis) service plan
     *
     * Idempotent: safe to run multiple times — duplicate invoices are skipped.
     *
     * The covered period depends on each router's billing_mode:
     *   - 'anticipado' (default): the month the job runs (cobro adelantado)
     *   - 'vencido'             : the previous month (cobro vencido)
     * An explicit $period overrides the mode for ALL routers (manual backfill).
     *
     * @param string|null $period   Format: YYYY-MM. Null = derive per router.
     * @param int|null    $routerId Limit to a specific router (null = all). Used by the
     *                              simulator/manual ops to focus a single tenant.
     * @return int Number of invoices created
     */
    public function generateMonthlyInvoices(?string $period = null, ?int $routerId = null): int
    {
        $periodExplicit = $period !== null;
        $today          = now();
        $created        = 0;

        // ── Iterate routers that have a billing config ──────────────────────
        $routerQuery = Router::with(['billingConfig', 'customers'])
            ->whereNotNull('billing_router_id');

        if ($routerId !== null) {
            $routerQuery->where('id', $routerId);
        }

        $routers = $routerQuery->get();

        Log::info("Billing: Checking {$routers->count()} router(s) with billing config.");

        foreach ($routers as $router) {
            $billingConfig = $router->billingConfig;
            if (!$billingConfig) {
                continue;
            }

            // ── Check create_invoice day (clamped to this month's length) ───
            // A configured day 31 becomes 30 in April / 28 in February so
            // "último día" configs still fire; other days stay as set.
            $rawCreateDay = Billing::dayOf($billingConfig->create_invoice);
            $createDay    = Billing::clampDayToMonth($rawCreateDay, $today);

            if ($createDay === null) {
                Log::info("Billing: Router {$router->id} ({$router->name}) has no create_invoice day. Skipping.");
                continue;
            }

            // Only generate if today's day >= the (clamped) creation day.
            // This allows recovery if the system was down on the exact day.
            if ($today->day < $createDay) {
                Log::info("Billing: Router {$router->id} ({$router->name}) — create day is {$createDay}, today is {$today->day}. Not yet.");
                continue;
            }

            // ── Check create_invoice_time (hour of day) ─────────────────────
            // The scheduler runs this command hourly; gate on the configured
            // time exactly like the auto-cut does, so the operator can pick the
            // hour invoices go out. Default '00:00:00' = fire at the first run
            // of the day (unchanged date-only behaviour). An explicit $period
            // (manual backfill) bypasses the hour gate — the operator asked for
            // it right now.
            if (!$periodExplicit) {
                $createDateTime = Billing::applyTimeOfDay($today, $billingConfig->create_invoice_time);
                if ($today->lt($createDateTime)) {
                    Log::info("Billing: Router {$router->id} ({$router->name}) — create time is "
                        . ($billingConfig->create_invoice_time ?: '00:00:00')
                        . ", current time is {$today->format('H:i:s')}. Not yet.");
                    continue;
                }
            }

            // ── Resolve the period this invoice covers ──────────────────────
            if ($periodExplicit) {
                $periodMonth = Carbon::parse($period . '-01');
            } else {
                $mode = $billingConfig->billing_mode ?: Billing::MODE_ANTICIPADO;
                $periodMonth = $mode === Billing::MODE_VENCIDO
                    ? $today->copy()->subMonthNoOverflow()
                    : $today->copy();
            }
            $periodStart = $periodMonth->copy()->startOfMonth()->startOfDay();
            $periodEnd   = $periodMonth->copy()->endOfMonth()->startOfDay();

            // ── Determine due date from payment_day config ──────────────────
            $dueDay = $billingConfig->payment_day
                ? Carbon::parse($billingConfig->payment_day)->day
                : null;

            $issueDate = $today->copy()->startOfDay();

            if ($dueDay !== null) {
                // Clamp due day to last day of current month
                $lastDayOfMonth = $today->copy()->endOfMonth()->day;
                $clampedDueDay  = min($dueDay, $lastDayOfMonth);
                $dueDate = $today->copy()->setDay($clampedDueDay)->startOfDay();
                // If due date is before issue date, push to next month
                if ($dueDate->lt($issueDate)) {
                    $dueDate = $dueDate->addMonth();
                }
            } else {
                $dueDate = $issueDate->copy()->addDays(5);
            }

            // ── Get active customers assigned to this router ────────────────
            // NOTE: customer_profile.status is a BOOLEAN column (true = active).
            // Comparing it to the string 'active' throws on PostgreSQL
            // (SQLSTATE 22P02) and silently matches nothing on SQLite.
            // exclude_from_billing = clientes marcados como "no facturar": quedan
            // fuera del ciclo automático (sin factura, recordatorio ni corte).
            $customerProfiles = CustomerProfile::where('router_id', $router->id)
                ->where('status', true)
                ->where('exclude_from_billing', false)
                ->get();

            Log::info("Billing: Router {$router->id} ({$router->name}) — {$customerProfiles->count()} active customer(s) to check.");

            foreach ($customerProfiles as $profile) {
                $customerId = $profile->user_id;

                // Find the customer's active (billable) service
                $userService = UserService::where('user_id', $customerId)
                    ->where('status', UserService::STATUS_ACTIVE)
                    ->with('servicePlan')
                    ->first();

                if (!$userService) {
                    Log::info("Billing: Customer {$customerId} has no active service. Skipping.");
                    continue;
                }

                $servicePlan = $userService->servicePlan;
                if (!$servicePlan) {
                    Log::warning("Billing: Customer {$customerId} active service has no plan. Skipping.");
                    continue;
                }

                // Skip courtesy plans (they should be 'gratis' but double-check)
                if ($servicePlan->is_courtesy) {
                    Log::info("Billing: Customer {$customerId} has courtesy plan '{$servicePlan->name}'. Skipping.");
                    continue;
                }

                $tenantId = $router->tenant_id;

                // Idempotency check: skip if invoice already exists for this period
                $exists = Invoice::where('tenant_id', $tenantId)
                    ->where('customer_id', $customerId)
                    ->where('period_start', $periodStart)
                    ->where('period_end', $periodEnd)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $subtotal = $servicePlan->cost_product ?? 0;
                $tax      = 0;
                $total    = $subtotal + $tax;

                try {
                    $invoice = $this->createMonthlyInvoiceFor(
                        tenantId:    $tenantId,
                        customerId:  $customerId,
                        router:      $router,
                        profile:     $profile,
                        servicePlan: $servicePlan,
                        issueDate:   $issueDate,
                        dueDate:     $dueDate,
                        periodStart: $periodStart,
                        periodEnd:   $periodEnd,
                        billingConfig: $billingConfig,
                    );
                    $created++;
                    $this->markActionLogSuccess($tenantId, $router->id, $customerId, $periodStart, $periodEnd, $invoice->id);
                } catch (\Throwable $e) {
                    Log::error("Billing: Failed to create invoice for customer {$customerId}: {$e->getMessage()}");
                    $this->markActionLogFailed($tenantId, $router->id, $customerId, $periodStart, $periodEnd, $e->getMessage());
                }
            }
        }

        Log::info("Billing: Generation complete. {$created} invoice(s) created for period {$period}.");

        return $created;
    }

    /**
     * Audit (read-only) whether the monthly run actually happened for every
     * router that was due to invoice. This closes the blind spot the failover
     * cannot see: the failover only retries per-customer creation exceptions
     * that were recorded in billing_action_logs, so a router that was skipped
     * entirely — or a monthly job that never ran at all — leaves NO trace and
     * triggers NO retry. This method reconstructs the SAME gating as
     * generateMonthlyInvoices() and compares "expected" vs "actually created".
     *
     * Per router it reports:
     *   - due         : whether today's day has reached the (clamped) create day
     *   - expected    : active customers with an active, non-courtesy service
     *   - actual      : monthly invoices that exist for the resolved period
     *   - failed_logs : FAILED/EXHAUSTED action-log rows for the period
     *   - status      : pending | ok | partial | no_show
     *
     * Writes nothing. Never creates invoices.
     *
     * @param string|null $period   YYYY-MM. Null = derive per router (same as generation).
     * @param int|null    $routerId Limit to a specific router (null = all).
     * @return array<int,array<string,mixed>>
     */
    public function auditMonthlyBilling(?string $period = null, ?int $routerId = null): array
    {
        $periodExplicit = $period !== null;
        $today          = now();
        $rows           = [];

        $routerQuery = Router::with('billingConfig')->whereNotNull('billing_router_id');
        if ($routerId !== null) {
            $routerQuery->where('id', $routerId);
        }

        foreach ($routerQuery->get() as $router) {
            $billingConfig = $router->billingConfig;
            if (!$billingConfig) {
                continue;
            }

            $createDay = Billing::clampDayToMonth(Billing::dayOf($billingConfig->create_invoice), $today);
            if ($createDay === null) {
                // No create day configured: not a no-show, nothing to audit.
                continue;
            }

            // Resolve the covered period EXACTLY like generateMonthlyInvoices().
            if ($periodExplicit) {
                $periodMonth = Carbon::parse($period . '-01');
            } else {
                $mode = $billingConfig->billing_mode ?: Billing::MODE_ANTICIPADO;
                $periodMonth = $mode === Billing::MODE_VENCIDO
                    ? $today->copy()->subMonthNoOverflow()
                    : $today->copy();
            }
            $periodStart = $periodMonth->copy()->startOfMonth()->startOfDay();
            $periodEnd   = $periodMonth->copy()->endOfMonth()->startOfDay();

            $due = $today->day >= $createDay;

            // Don't flag a no-show until the configured create hour has had its
            // chance to run. Generation is gated on create_invoice_time and the
            // scheduler ticks hourly, so we mirror the cut audit's 1h grace: the
            // gap between the configured hour and the next hourly run must not be
            // mistaken for a missing invoice. Skipped for an explicit (past) period.
            if ($due && !$periodExplicit) {
                $createMoment = Billing::applyTimeOfDay($today, $billingConfig->create_invoice_time);
                $due = $today->gte($createMoment->copy()->addHour());
            }

            // Expected: active customers on this router with an active, billable
            // (non-courtesy) service — the same set generation would invoice.
            // Excluded ("no facturar") customers are not expected to be invoiced,
            // so they must not count toward the no-show/partial audit either.
            $custIds = CustomerProfile::where('router_id', $router->id)
                ->where('status', true)
                ->where('exclude_from_billing', false)
                ->pluck('user_id');

            $expected = $custIds->isEmpty() ? 0 : UserService::whereIn('user_id', $custIds->all())
                ->where('status', UserService::STATUS_ACTIVE)
                ->whereHas('servicePlan', function ($q) {
                    $q->where('is_courtesy', false)->orWhereNull('is_courtesy');
                })
                ->distinct('user_id')
                ->count('user_id');

            // Actual: monthly invoices for the resolved period, scoped to this
            // router's customers (so multi-router tenants compare apples to apples).
            $actual = $custIds->isEmpty() ? 0 : Invoice::where('tenant_id', $router->tenant_id)
                ->where('period_start', $periodStart)
                ->where('period_end', $periodEnd)
                ->whereIn('customer_id', $custIds->all())
                ->count();

            $failedLogs = BillingActionLog::where('tenant_id', $router->tenant_id)
                ->where('period_start', $periodStart->toDateString())
                ->whereIn('status', [BillingActionLog::STATUS_FAILED, BillingActionLog::STATUS_EXHAUSTED])
                ->count();

            if (!$due) {
                $status = 'pending';
            } elseif ($expected > 0 && $actual === 0) {
                $status = 'no_show';
            } elseif ($actual < $expected) {
                $status = 'partial';
            } else {
                $status = 'ok';
            }

            $rows[] = [
                'router_id'   => $router->id,
                'router_name' => $router->name,
                'tenant_id'   => $router->tenant_id,
                'period'      => $periodStart->format('Y-m'),
                'create_day'  => $createDay,
                'due'         => $due,
                'expected'    => $expected,
                'actual'      => $actual,
                'failed_logs' => $failedLogs,
                'status'      => $status,
            ];
        }

        return $rows;
    }

    /**
     * Shared invoice-creation block used by the monthly run AND by failover retries.
     * Throws on failure so the caller can log it.
     */
    protected function createMonthlyInvoiceFor(
        int $tenantId,
        int $customerId,
        Router $router,
        CustomerProfile $profile,
        \App\Models\Plan $servicePlan,
        Carbon $issueDate,
        Carbon $dueDate,
        Carbon $periodStart,
        Carbon $periodEnd,
        Billing $billingConfig,
    ): Invoice {
        $subtotal = $servicePlan->cost_product ?? 0;
        $tax      = 0;
        $total    = $subtotal + $tax;

        $invoiceNumber = $this->generateInvoiceNumber($tenantId);

        $invoice = Invoice::create([
            'tenant_id'    => $tenantId,
            'customer_id'  => $customerId,
            'service_id'   => $servicePlan->id,
            'number'       => $invoiceNumber,
            'issue_date'   => $issueDate,
            'due_date'     => $dueDate,
            'period_start' => $periodStart,
            'period_end'   => $periodEnd,
            'currency'     => 'COP',
            'subtotal'     => $subtotal,
            'tax'          => $tax,
            'total'        => $total,
            'balance_due'  => $total,
            'status'       => 'issued',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'type'       => 'plan',
            'description'=> "Servicio mensual: {$servicePlan->name}",
            'quantity'   => 1,
            'unit_price' => $subtotal,
            'amount'     => $subtotal,
        ]);

        $profile->refresh();
        $this->applyCreditToInvoice($invoice, $profile);

        Log::info("Billing: Invoice {$invoiceNumber} created for customer {$customerId} (router {$router->id}).");

        // Notification failure must NOT roll back the invoice.
        try {
            $invoice->refresh()->load('tenant');
            $this->notifyInvoiceCreated($invoice, $profile, $billingConfig);
        } catch (\Throwable $e) {
            Log::error("Billing: notify-on-create failed for invoice {$invoiceNumber}: {$e->getMessage()}");
        }

        return $invoice;
    }

    /**
     * Retry a previously-failed monthly invoice from its action log row.
     * Increments attempts, recomputes next_retry_at, and marks success/exhausted.
     *
     * @return bool true if the invoice was created (or already existed), false otherwise
     */
    public function retryFailedInvoice(BillingActionLog $log): bool
    {
        if (!$log->isReadyToRetry()) {
            return false;
        }

        $router = Router::with('billingConfig')->find($log->router_id);
        if (!$router || !$router->billingConfig) {
            $log->update([
                'status'     => BillingActionLog::STATUS_EXHAUSTED,
                'last_error' => 'Router or billing config no longer exists',
                'attempts'   => $log->attempts + 1,
            ]);
            return false;
        }

        $profile = CustomerProfile::where('user_id', $log->customer_id)->first();
        if (!$profile || !$profile->status || $profile->exclude_from_billing) {
            $log->update([
                'status'     => BillingActionLog::STATUS_EXHAUSTED,
                'last_error' => $profile && $profile->exclude_from_billing
                    ? 'Customer excluded from billing'
                    : 'Customer profile inactive or missing',
                'attempts'   => $log->attempts + 1,
            ]);
            return false;
        }

        $userService = UserService::where('user_id', $log->customer_id)
            ->where('status', UserService::STATUS_ACTIVE)
            ->with('servicePlan')
            ->first();

        if (!$userService || !$userService->servicePlan || $userService->servicePlan->is_courtesy) {
            $log->update([
                'status'     => BillingActionLog::STATUS_EXHAUSTED,
                'last_error' => 'No active billable service plan',
                'attempts'   => $log->attempts + 1,
            ]);
            return false;
        }

        // Idempotency: if the invoice already exists, just mark success.
        $existing = Invoice::where('tenant_id', $log->tenant_id)
            ->where('customer_id', $log->customer_id)
            ->where('period_start', $log->period_start)
            ->where('period_end', $log->period_end)
            ->first();

        if ($existing) {
            $log->update([
                'status'     => BillingActionLog::STATUS_SUCCESS,
                'invoice_id' => $existing->id,
                'attempts'   => $log->attempts + 1,
                'last_error' => null,
            ]);
            return true;
        }

        // Recompute due date from billing config (mirror of main loop).
        $today      = now();
        $billingConfig = $router->billingConfig;
        $dueDay     = $billingConfig->payment_day
            ? Carbon::parse($billingConfig->payment_day)->day
            : null;
        $issueDate  = $today->copy()->startOfDay();
        if ($dueDay !== null) {
            $lastDayOfMonth = $today->copy()->endOfMonth()->day;
            $clampedDueDay  = min($dueDay, $lastDayOfMonth);
            $dueDate = $today->copy()->setDay($clampedDueDay)->startOfDay();
            if ($dueDate->lt($issueDate)) {
                $dueDate = $dueDate->addMonth();
            }
        } else {
            $dueDate = $issueDate->copy()->addDays(5);
        }

        try {
            $invoice = $this->createMonthlyInvoiceFor(
                tenantId:    $log->tenant_id,
                customerId:  $log->customer_id,
                router:      $router,
                profile:     $profile,
                servicePlan: $userService->servicePlan,
                issueDate:   $issueDate,
                dueDate:     $dueDate,
                periodStart: Carbon::parse($log->period_start)->startOfDay(),
                periodEnd:   Carbon::parse($log->period_end)->startOfDay(),
                billingConfig: $billingConfig,
            );

            $log->update([
                'status'        => BillingActionLog::STATUS_SUCCESS,
                'invoice_id'    => $invoice->id,
                'attempts'      => $log->attempts + 1,
                'last_error'    => null,
                'next_retry_at' => null,
            ]);
            return true;
        } catch (\Throwable $e) {
            $attempts = $log->attempts + 1;
            $exhausted = $attempts >= BillingActionLog::MAX_ATTEMPTS;

            $log->update([
                'status'        => $exhausted ? BillingActionLog::STATUS_EXHAUSTED : BillingActionLog::STATUS_FAILED,
                'attempts'      => $attempts,
                'last_error'    => $e->getMessage(),
                'next_retry_at' => $exhausted ? null : now()->addSeconds(
                    BillingActionLog::RETRY_BACKOFF_SECONDS[$attempts] ?? 3600
                ),
            ]);
            Log::error("Billing: Retry attempt {$attempts} failed for log {$log->id} (customer {$log->customer_id}): {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Upsert an action log row marking a failed invoice creation attempt.
     * Increments attempts and computes next_retry_at via backoff.
     */
    protected function markActionLogFailed(int $tenantId, ?int $routerId, int $customerId, Carbon $periodStart, Carbon $periodEnd, string $errorMessage): void
    {
        $existing = BillingActionLog::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->where('period_start', $periodStart->toDateString())
            ->where('action', BillingActionLog::ACTION_GENERATE_MONTHLY)
            ->first();

        if ($existing) {
            $attempts = $existing->attempts + 1;
            $exhausted = $attempts >= BillingActionLog::MAX_ATTEMPTS;
            $existing->update([
                'router_id'     => $routerId,
                'status'        => $exhausted ? BillingActionLog::STATUS_EXHAUSTED : BillingActionLog::STATUS_FAILED,
                'attempts'      => $attempts,
                'last_error'    => $errorMessage,
                'next_retry_at' => $exhausted ? null : now()->addSeconds(
                    BillingActionLog::RETRY_BACKOFF_SECONDS[$attempts] ?? 3600
                ),
            ]);
        } else {
            BillingActionLog::create([
                'tenant_id'     => $tenantId,
                'router_id'     => $routerId,
                'customer_id'   => $customerId,
                'action'        => BillingActionLog::ACTION_GENERATE_MONTHLY,
                'period_start'  => $periodStart->toDateString(),
                'period_end'    => $periodEnd->toDateString(),
                'status'        => BillingActionLog::STATUS_FAILED,
                'attempts'      => 1,
                'last_error'    => $errorMessage,
                'next_retry_at' => now()->addSeconds(BillingActionLog::RETRY_BACKOFF_SECONDS[1]),
            ]);
        }
    }

    /**
     * Close a previously-failed log row when the invoice finally succeeds.
     * No-op if there's no prior failed row — we keep the log lean and focused
     * on trouble cases (failed / exhausted), not on every successful invoice.
     */
    protected function markActionLogSuccess(int $tenantId, ?int $routerId, int $customerId, Carbon $periodStart, Carbon $periodEnd, int $invoiceId): void
    {
        $existing = BillingActionLog::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->where('period_start', $periodStart->toDateString())
            ->where('action', BillingActionLog::ACTION_GENERATE_MONTHLY)
            ->first();

        if (!$existing) {
            return;
        }

        $existing->update([
            'router_id'     => $routerId,
            'period_end'    => $periodEnd->toDateString(),
            'invoice_id'    => $invoiceId,
            'status'        => BillingActionLog::STATUS_SUCCESS,
            'last_error'    => null,
            'next_retry_at' => null,
        ]);
    }

    /**
     * Generate a service charge invoice (from a ticket or as a standalone additional charge).
     *
     * @param array $data {tenant_id, customer_id, items[], ticket_id?, invoice_type?, due_date?, notes?}
     * @return Invoice
     */
    public function generateServiceChargeInvoice(array $data): Invoice
    {
        return DB::transaction(function () use ($data) {
            $tenantId   = $data['tenant_id'];
            $customerId = $data['customer_id'];
            $ticketId   = $data['ticket_id'] ?? null;
            $items      = $data['items'];
            $issueDate  = now()->startOfDay();
            $dueDate    = isset($data['due_date'])
                ? \Carbon\Carbon::parse($data['due_date'])->startOfDay()
                : $issueDate->copy()->addDays(5);
            $notes      = $data['notes'] ?? null;
            $type       = $data['invoice_type'] ?? ($ticketId ? Invoice::TYPE_SERVICE_CHARGE : Invoice::TYPE_ADDITIONAL);

            $subtotal = collect($items)->sum(fn($item) => (float) $item['quantity'] * (float) $item['unit_price']);

            $invoiceNumber = $this->generateInvoiceNumber($tenantId);

            $invoice = Invoice::create([
                'tenant_id'    => $tenantId,
                'customer_id'  => $customerId,
                'ticket_id'    => $ticketId,
                'invoice_type' => $type,
                'number'       => $invoiceNumber,
                'issue_date'   => $issueDate,
                'due_date'     => $dueDate,
                'period_start' => $issueDate,
                'period_end'   => $dueDate,
                'currency'     => 'COP',
                'subtotal'     => $subtotal,
                'tax'          => 0,
                'total'        => $subtotal,
                'balance_due'  => $subtotal,
                'status'       => 'issued',
                'notes'        => $notes,
            ]);

            foreach ($items as $item) {
                $amount = (float) $item['quantity'] * (float) $item['unit_price'];
                InvoiceItem::create([
                    'invoice_id'  => $invoice->id,
                    'type'        => $item['type'] ?? 'service',
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'],
                    'unit_price'  => $item['unit_price'],
                    'amount'      => $amount,
                ]);
            }

            $profile = \App\Models\CustomerProfile::where('user_id', $customerId)->first();
            if ($profile) {
                $this->applyCreditToInvoice($invoice, $profile);
            }

            Log::info("Billing: Service charge invoice {$invoiceNumber} created for customer {$customerId}" . ($ticketId ? " (ticket #{$ticketId})" : '') . '.');

            return $invoice->load(['items', 'customer.customerProfile', 'tenant']);
        });
    }

    /**
     * Register a payment and allocate it to invoices.
     *
     * @param array $data
     * @return Payment
     */
    public function registerPayment(array $data): Payment
    {
        $payment = DB::transaction(function () use ($data) {
            $payment = Payment::create([
                'tenant_id' => $data['tenant_id'],
                'customer_id' => $data['customer_id'],
                'amount' => $data['amount'],
                'payment_date' => $data['payment_date'],
                'method' => $data['method'] ?? 'cash',
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'completed',
                'created_by' => $data['created_by'] ?? null,
            ]);

            // Allocate payment to open invoices (oldest first)
            $this->allocatePayment($payment, $data['allocations'] ?? null);

            return $payment->load('allocations');
        });

        // After commit: if this payment cleared the customer's outstanding
        // balance, reconnect them right away (synchronously) so the operator
        // sees the result without depending on a queue worker being up. The
        // call is fully guarded (never throws) and runs after the transaction,
        // so a slow/failing router can't roll back or break the saved payment.
        $this->reactivateIfCleared((int) $data['customer_id']);

        return $payment;
    }

    /**
     * Auto-reconnect a customer after a payment IF, and only if:
     *   - they have NO overdue invoices left, AND
     *   - they are currently cut on the router (last suspension log =
     *     SUSPEND/success not yet followed by an UNSUSPEND).
     *
     * Per operator policy, ANY current cut is lifted once the balance is
     * cleared — including manual suspensions — so paying always reconnects the
     * customer. Lifts the block via RouterProvisioningService and mirrors the
     * manual `activate` DB state (status=true). Never throws — a router failure
     * must not roll back or break the payment; the reconcile/manual tools
     * remain the safety net.
     */
    public function reactivateIfCleared(int $customerId): void
    {
        try {
            $profile = CustomerProfile::where('user_id', $customerId)->first();
            if (!$profile || !$profile->router_id || !$profile->ip_user) {
                return;
            }

            // Still owes? Keep the cut in place.
            $overdue = Invoice::where('customer_id', $customerId)
                ->where('due_date', '<', now())
                ->where('balance_due', '>', 0)
                ->whereNotIn('status', ['void', 'cancelled', 'paid'])
                ->count();
            if ($overdue > 0) {
                return;
            }

            // Is the customer currently cut on the router? The latest log row is
            // the same "confirmed cut" signal the reconciler uses: a successful
            // SUSPEND that hasn't been followed by an UNSUSPEND.
            $latest = SuspensionActionLog::where('customer_id', $customerId)
                ->where('router_id', $profile->router_id)
                ->latest('id')
                ->first();

            $currentlyCut = $latest
                && $latest->action === SuspensionActionLog::ACTION_SUSPEND
                && $latest->status === SuspensionActionLog::STATUS_SUCCESS;

            if (!$currentlyCut) {
                return; // not cut, or already reconnected
            }

            $ok = app(RouterProvisioningService::class)->unsuspendCustomer(
                $customerId,
                (int) $profile->router_id,
                ['reason' => SuspensionActionLog::REASON_AUTO_RECONNECT]
            );

            // Mirror the manual activate's DB state so the UI reflects reality.
            if ($ok && $profile->status !== true) {
                $plan = $profile->service_id ? Plan::find($profile->service_id) : null;
                $profile->update([
                    'status'         => true,
                    'service_status' => ($plan && $plan->is_courtesy) ? 'gratis' : 'activo',
                ]);
            }

            Log::info("Billing: auto-reconnect customer {$customerId} after payment cleared overdue balance "
                . "(router {$profile->router_id}). router_ok=" . ($ok ? '1' : '0'));
        } catch (\Throwable $e) {
            Log::error("Billing: auto-reconnect after payment failed for customer {$customerId}: {$e->getMessage()}");
        }
    }

    /**
     * Allocate payment amount to invoices.
     * Any unallocated remainder is stored as credit_balance on the customer profile.
     *
     * @param Payment $payment
     * @param array|null $allocations Manual allocations: [['invoice_id' => X, 'amount' => Y], ...]
     */
    protected function allocatePayment(Payment $payment, ?array $allocations = null): void
    {
        $remainingAmount = (float) $payment->amount;

        if ($allocations) {
            // Manual allocation
            foreach ($allocations as $allocation) {
                if ($remainingAmount <= 0)
                    break;

                $invoice = Invoice::find($allocation['invoice_id']);
                if (!$invoice || $invoice->balance_due <= 0)
                    continue;

                $amountToApply = min($allocation['amount'], $invoice->balance_due, $remainingAmount);

                PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'invoice_id' => $invoice->id,
                    'amount' => $amountToApply,
                ]);

                $invoice->balance_due -= $amountToApply;
                $this->updateInvoiceStatus($invoice);
                $invoice->save();

                $remainingAmount -= $amountToApply;
            }
        } else {
            // Auto-allocate to oldest open invoices (FIFO)
            $openInvoices = Invoice::where('customer_id', $payment->customer_id)
                ->where('balance_due', '>', 0)
                ->whereNotIn('status', ['void', 'cancelled'])
                ->orderBy('due_date', 'asc')
                ->get();

            foreach ($openInvoices as $invoice) {
                if ($remainingAmount <= 0)
                    break;

                $amountToApply = min((float) $invoice->balance_due, $remainingAmount);

                PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'invoice_id' => $invoice->id,
                    'amount' => $amountToApply,
                ]);

                $invoice->balance_due -= $amountToApply;
                $this->updateInvoiceStatus($invoice);
                $invoice->save();

                $remainingAmount -= $amountToApply;
            }
        }

        // Any remaining amount after all invoices are paid becomes credit for the customer.
        if ($remainingAmount > 0) {
            $customer = CustomerProfile::where('user_id', $payment->customer_id)->first();
            if ($customer) {
                $customer->credit_balance = (float) $customer->credit_balance + $remainingAmount;
                $customer->save();
                Log::info("Billing: Payment {$payment->id} — \${$remainingAmount} stored as credit for customer {$payment->customer_id}.");
            }
        }
    }

    /**
     * Apply a customer's credit balance to a single invoice, reducing both.
     * Called automatically after each new invoice is created.
     */
    protected function applyCreditToInvoice(Invoice $invoice, CustomerProfile $profile): void
    {
        if ($profile->credit_balance <= 0 || $invoice->balance_due <= 0) {
            return;
        }

        $apply = min((float) $profile->credit_balance, (float) $invoice->balance_due);

        $invoice->balance_due -= $apply;
        $this->updateInvoiceStatus($invoice);
        $invoice->save();

        $profile->credit_balance -= $apply;
        $profile->save();

        Log::info("Billing: Auto-applied \${$apply} credit to invoice {$invoice->id} for customer {$profile->user_id}. Remaining credit: {$profile->credit_balance}.");
    }

    /**
     * Update invoice status based on balance_due.
     *
     * @param Invoice $invoice
     */
    protected function updateInvoiceStatus(Invoice $invoice): void
    {
        if ($invoice->balance_due <= 0) {
            $invoice->status = 'paid';
        } elseif ($invoice->balance_due < $invoice->total) {
            $invoice->status = 'partial';
        } elseif ($invoice->due_date < now() && $invoice->balance_due > 0) {
            $invoice->status = 'overdue';
        } else {
            $invoice->status = 'issued';
        }
    }

    /**
     * Reverse all allocations of a payment and restore invoice balances.
     * Also removes any credit_balance that was generated by the payment's excess.
     */
    protected function reversePaymentAllocations(Payment $payment): void
    {
        $allocations = $payment->allocations()->with('invoice')->get();

        foreach ($allocations as $allocation) {
            $invoice = $allocation->invoice;
            if ($invoice) {
                $invoice->balance_due = (float) $invoice->balance_due + (float) $allocation->amount;
                $this->updateInvoiceStatus($invoice);
                $invoice->save();
            }
            $allocation->delete();
        }

        // Reverse any excess credit that was generated by this payment
        $totalAllocated = $allocations->sum('amount');
        $excess = (float) $payment->amount - (float) $totalAllocated;
        if ($excess > 0) {
            $customer = CustomerProfile::where('user_id', $payment->customer_id)->first();
            if ($customer) {
                $customer->credit_balance = max(0, (float) $customer->credit_balance - $excess);
                $customer->save();
            }
        }
    }

    /**
     * Update a payment's metadata and re-allocate when amount changes.
     */
    public function updatePayment(Payment $payment, array $data): Payment
    {
        return DB::transaction(function () use ($payment, $data) {
            $amountChanged = isset($data['amount']) && (float) $data['amount'] !== (float) $payment->amount;

            if ($amountChanged) {
                $this->reversePaymentAllocations($payment);
            }

            $payment->update([
                'amount'       => $data['amount']       ?? $payment->amount,
                'payment_date' => $data['payment_date'] ?? $payment->payment_date,
                'method'       => $data['method']       ?? $payment->method,
                'reference'    => array_key_exists('reference', $data) ? $data['reference'] : $payment->reference,
                'notes'        => array_key_exists('notes', $data) ? $data['notes'] : $payment->notes,
            ]);

            if ($amountChanged) {
                $this->allocatePayment($payment);
            }

            return $payment->load('allocations');
        });
    }

    /**
     * Delete a payment, reversing all its allocations and any credit generated.
     */
    public function deletePayment(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $this->reversePaymentAllocations($payment);
            $payment->delete();
        });
    }

    /**
     * Permanently delete an invoice, its items and its payment allocations.
     *
     * Any money that had been applied to it is returned to the customer as
     * credit so a received payment is never silently lost when the invoice is
     * removed (the payment record itself is kept for history). Hard delete —
     * for legal invoicing prefer voiding (status=cancelled) over deleting.
     */
    public function deleteInvoice(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $allocations = PaymentAllocation::where('invoice_id', $invoice->id)->get();

            if ($allocations->isNotEmpty()) {
                $customer = CustomerProfile::where('user_id', $invoice->customer_id)->first();
                foreach ($allocations as $allocation) {
                    if ($customer) {
                        $customer->credit_balance = (float) $customer->credit_balance + (float) $allocation->amount;
                    }
                    $allocation->delete();
                }
                if ($customer) {
                    $customer->save();
                }
            }

            $invoice->items()->delete();
            $invoice->delete();

            Log::info("Billing: Invoice {$invoice->id} (#{$invoice->number}) deleted.");
        });
    }

    /**
     * Revert an invoice back to "owing": undo every payment allocation tied to
     * it, restore its balance to the full total, and recompute its status.
     *
     * A payment that funded ONLY this invoice is deleted (its money is undone).
     * A payment that also funded other invoices keeps those allocations; the
     * portion freed from this invoice becomes customer credit so the books stay
     * balanced (sum of allocations + credit still equals the payment amount).
     *
     * Used by the "Marcar como no pagada" action — operator correction and
     * testing the overdue → cut → pay → reconnect cycle.
     */
    public function markInvoiceUnpaid(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            // Work from the persisted balance, not a possibly-stale in-memory one.
            $invoice->refresh();

            $allocations = PaymentAllocation::where('invoice_id', $invoice->id)->get();

            foreach ($allocations as $allocation) {
                $invoice->balance_due = (float) $invoice->balance_due + (float) $allocation->amount;

                $payment = Payment::find($allocation->payment_id);
                $allocation->delete();

                if (!$payment) {
                    continue;
                }

                $remaining = PaymentAllocation::where('payment_id', $payment->id)->count();
                if ($remaining === 0) {
                    // Payment existed only for this invoice → undo it entirely.
                    $payment->delete();
                } else {
                    // Payment also funded other invoices → keep it and park the
                    // freed amount as customer credit so nothing is lost.
                    $customer = CustomerProfile::where('user_id', $payment->customer_id)->first();
                    if ($customer) {
                        $customer->credit_balance = (float) $customer->credit_balance + (float) $allocation->amount;
                        $customer->save();
                    }
                }
            }

            $this->updateInvoiceStatus($invoice);
            $invoice->save();

            Log::info("Billing: Invoice {$invoice->id} marked unpaid; balance restored to {$invoice->balance_due}.");

            return $invoice->fresh(['customer', 'items', 'payments']);
        });
    }

    /**
     * Notify the customer that a new invoice was issued, using the channel
     * configured in the router's billing config (email / whatsapp / both).
     * Silent no-op when a channel is selected but contact info or external
     * credentials are missing — the failure is logged inside the caller's
     * try/catch.
     */
    protected function notifyInvoiceCreated(Invoice $invoice, CustomerProfile $profile, Billing $billingConfig): void
    {
        $customer = $invoice->customer;
        if (!$customer) {
            return;
        }

        // "No facturar": never notify an excluded customer, even if some other
        // code path created an invoice for them manually.
        if ($profile->exclude_from_billing) {
            return;
        }

        $periodLabel = $invoice->period_start
            ? Carbon::parse($invoice->period_start)->locale('es')->isoFormat('MMMM YYYY')
            : null;

        $data = [
            'customer_name'  => trim("{$profile->name} {$profile->last_name}") ?: ($customer->name ?? 'Cliente'),
            'invoice_number' => $invoice->number,
            'amount'         => $invoice->balance_due ?? $invoice->total,
            'due_date'       => $invoice->due_date,
            'issue_date'     => $invoice->issue_date,
            'company_name'   => $invoice->tenant?->name ?? 'ISPWatch',
            'period_label'   => $periodLabel,
        ];

        $type = $billingConfig->notification_type ?: 'email';

        if (in_array($type, ['email', 'both'], true) && $customer->email) {
            Mail::to($customer->email)->send(new InvoiceCreatedMail($data));
        }

        if (in_array($type, ['whatsapp', 'both'], true)) {
            $phone = $profile->phone ?? $customer->tel ?? null;
            if ($phone && $this->whatsAppService->isConfigured()) {
                $this->whatsAppService->sendInvoiceCreated($phone, $data);
            }
        }
    }

    /**
     * Mark overdue invoices and return list for suspension processing.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getOverdueInvoices()
    {
        $overdueInvoices = Invoice::where('due_date', '<', now())
            ->where('balance_due', '>', 0)
            ->whereNotIn('status', ['void', 'cancelled', 'paid'])
            ->get();

        foreach ($overdueInvoices as $invoice) {
            if ($invoice->status !== 'overdue') {
                $invoice->status = 'overdue';
                $invoice->save();
            }
        }

        return $overdueInvoices;
    }
}
