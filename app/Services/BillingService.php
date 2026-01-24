<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Models\UserService;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BillingService
{
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
     * Generate monthly invoices for all eligible customers.
     * Idempotent: safe to run multiple times for same period.
     *
     * @param string|null $period Format: YYYY-MM (defaults to current month)
     * @return int Number of invoices created
     */
    public function generateMonthlyInvoices(?string $period = null): int
    {
        if (!$period) {
            $period = now()->format('Y-m');
        }

        $periodStart = Carbon::parse($period . '-01')->startOfDay();
        $periodEnd = $periodStart->copy()->endOfMonth()->startOfDay();
        $issueDate = now()->startOfDay();
        $dueDate = $issueDate->copy()->addDays(5);

        $created = 0;

        // Get all active customers with exactly ONE active user_service
        $customers = User::whereHas('customerProfile')
            ->whereHas('userServices', function ($q) {
                $q->where('status', 'active');
            })
            ->with([
                'userServices' => function ($q) {
                    $q->where('status', 'active')->with('servicePlan');
                }
            ])
            ->get();

        foreach ($customers as $customer) {
            $activeServices = $customer->userServices->where('status', 'active');

            // Enforce: only one active service per customer
            if ($activeServices->count() !== 1) {
                Log::warning("Customer {$customer->id} has " . $activeServices->count() . " active services (expected 1). Skipping invoice generation.");
                continue;
            }

            $userService = $activeServices->first();
            $servicePlan = $userService->servicePlan;

            if (!$servicePlan) {
                Log::warning("Customer {$customer->id} active service has no service_plan. Skipping.");
                continue;
            }

            $tenantId = $customer->tenant_id;

            // Check if invoice already exists for this period (idempotency)
            $existingInvoice = Invoice::where('tenant_id', $tenantId)
                ->where('customer_id', $customer->id)
                ->where('period_start', $periodStart)
                ->where('period_end', $periodEnd)
                ->first();

            if ($existingInvoice) {
                continue; // Already exists, skip
            }

            $subtotal = $servicePlan->cost_product ?? 0;
            $tax = 0; // No taxes per requirement
            $total = $subtotal + $tax;

            try {
                // Generate invoice number
                $invoiceNumber = $this->generateInvoiceNumber($tenantId);

                $invoice = Invoice::create([
                    'tenant_id' => $tenantId,
                    'customer_id' => $customer->id,
                    'service_id' => $servicePlan->id,
                    'number' => $invoiceNumber,
                    'issue_date' => $issueDate,
                    'due_date' => $dueDate,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'currency' => 'COP',
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total' => $total,
                    'balance_due' => $total,
                    'status' => 'issued',
                ]);

                // Create invoice item (monthly service)
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'type' => 'plan',
                    'description' => "Servicio mensual: {$servicePlan->name}",
                    'quantity' => 1,
                    'unit_price' => $subtotal,
                    'amount' => $subtotal,
                ]);

                $created++;

                Log::info("Invoice {$invoiceNumber} created for customer {$customer->id}");
            } catch (\Exception $e) {
                Log::error("Failed to create invoice for customer {$customer->id}: {$e->getMessage()}");
            }
        }

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
     *
     * @param Payment $payment
     * @param array|null $allocations Manual allocations: [['invoice_id' => X, 'amount' => Y], ...]
     */
    protected function allocatePayment(Payment $payment, ?array $allocations = null): void
    {
        $remainingAmount = $payment->amount;

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
            // Auto-allocate to oldest open invoices
            $openInvoices = Invoice::where('customer_id', $payment->customer_id)
                ->where('balance_due', '>', 0)
                ->whereNotIn('status', ['void', 'cancelled'])
                ->orderBy('due_date', 'asc')
                ->get();

            foreach ($openInvoices as $invoice) {
                if ($remainingAmount <= 0)
                    break;

                $amountToApply = min($invoice->balance_due, $remainingAmount);

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
