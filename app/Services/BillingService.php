<?php

namespace App\Services;

use App\Mail\InvoiceCreatedMail;
use App\Models\Billing;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Router;
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
     * @param string|null $period  Format: YYYY-MM. Null = derive per router.
     * @return int Number of invoices created
     */
    public function generateMonthlyInvoices(?string $period = null): int
    {
        $periodExplicit = $period !== null;
        $today          = now();
        $created        = 0;

        // ── Iterate routers that have a billing config ──────────────────────
        $routers = Router::with(['billingConfig', 'customers'])
            ->whereNotNull('billing_router_id')
            ->get();

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
            $customerProfiles = CustomerProfile::where('router_id', $router->id)
                ->where('status', true)
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

                    // Auto-apply any existing credit balance to the new invoice
                    $profile->refresh();
                    $this->applyCreditToInvoice($invoice, $profile);

                    $created++;

                    Log::info("Billing: Invoice {$invoiceNumber} created for customer {$customerId} (router {$router->id}).");

                    // Notify customer that a new invoice was issued (email / whatsapp / both
                    // per the router's notification_type). Failure to notify must NOT
                    // roll back the invoice creation.
                    try {
                        $invoice->refresh()->load('tenant');
                        $this->notifyInvoiceCreated($invoice, $profile, $billingConfig);
                    } catch (\Throwable $e) {
                        Log::error("Billing: notify-on-create failed for invoice {$invoiceNumber}: {$e->getMessage()}");
                    }
                } catch (\Exception $e) {
                    Log::error("Billing: Failed to create invoice for customer {$customerId}: {$e->getMessage()}");
                }
            }
        }

        Log::info("Billing: Generation complete. {$created} invoice(s) created for period {$period}.");

        return $created;
    }

    /**
     * Register a payment and allocate it to invoices.
     *
     * @param array $data
     * @return Payment
     */
    public function registerPayment(array $data): Payment
    {
        return DB::transaction(function () use ($data) {
            $payment = Payment::create([
                'tenant_id' => $data['tenant_id'],
                'customer_id' => $data['customer_id'],
                'amount' => $data['amount'],
                'payment_date' => $data['payment_date'],
                'method' => $data['method'] ?? 'cash',
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'completed',
            ]);

            // Allocate payment to open invoices (oldest first)
            $this->allocatePayment($payment, $data['allocations'] ?? null);

            return $payment->load('allocations');
        });
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
