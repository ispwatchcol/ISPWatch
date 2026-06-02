<?php

namespace App\Services;

use App\Models\CustomerInstallation;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InstallationBillingService
{
    public function __construct(protected BillingService $billingService)
    {
    }

    /**
     * Create or update the invoice (and optional payment) tied to an installation.
     *
     * Returns null when the installation has no customer yet (prospect-only).
     * All DB writes are wrapped in a single transaction; any failure rolls back
     * both the billing fields and the invoice creation, preventing partial state.
     */
    public function upsertInstallationInvoice(CustomerInstallation $installation, int $tenantId): ?Invoice
    {
        if (!$installation->customer_id) {
            return null;
        }

        return DB::transaction(function () use ($installation, $tenantId) {
            $items    = $this->buildItems($installation);
            $subtotal = max(0.0, (float) array_sum(array_column($items, 'amount')));
            $total    = $subtotal; // installation invoices carry no tax

            $existing = Invoice::where('installation_id', $installation->id)
                ->where('tenant_id', $tenantId)
                ->first();

            if ($existing) {
                return $this->updateInvoice($existing, $items, $subtotal, $total, $installation, $tenantId);
            }

            return $this->createInvoice($installation, $items, $subtotal, $total, $tenantId);
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function createInvoice(
        CustomerInstallation $installation,
        array $items,
        float $subtotal,
        float $total,
        int $tenantId
    ): Invoice {
        // generateInvoiceNumber uses its own nested transaction + lockForUpdate
        // on the tenant row — safe to call from inside an outer transaction.
        $number = $this->billingService->generateInvoiceNumber($tenantId);
        $today  = now()->startOfDay();

        $invoice = Invoice::create([
            'tenant_id'       => $tenantId,
            'customer_id'     => $installation->customer_id,
            'installation_id' => $installation->id,
            'invoice_type'    => Invoice::TYPE_INSTALLATION,
            'number'          => $number,
            'issue_date'      => $today,
            'due_date'        => $today,
            'period_start'    => $today,
            'period_end'      => $today,
            'currency'        => 'COP',
            'subtotal'        => $subtotal,
            'tax'             => 0,
            'total'           => $total,
            'balance_due'     => $total,
            'status'          => 'issued',
        ]);

        foreach ($items as $item) {
            InvoiceItem::create(['invoice_id' => $invoice->id] + $item);
        }

        $this->syncPayment($invoice, $installation, $tenantId);

        Log::info("InstallationBilling: Invoice {$number} created for installation #{$installation->id} (customer #{$installation->customer_id}).");

        return $invoice->refresh()->load(['items']);
    }

    private function updateInvoice(
        Invoice $existing,
        array $items,
        float $subtotal,
        float $total,
        CustomerInstallation $installation,
        int $tenantId
    ): Invoice {
        // Rebuild line items from the current billing fields
        $existing->items()->delete();
        foreach ($items as $item) {
            InvoiceItem::create(['invoice_id' => $existing->id] + $item);
        }

        // Reset totals — syncPayment will set balance_due and status
        $existing->update([
            'subtotal'    => $subtotal,
            'tax'         => 0,
            'total'       => $total,
            'balance_due' => $total,
        ]);

        $this->syncPayment($existing, $installation, $tenantId);

        Log::info("InstallationBilling: Invoice {$existing->number} updated for installation #{$installation->id}.");

        return $existing->refresh()->load(['items']);
    }

    /**
     * Create, update, or remove the Payment record tied to this invoice.
     *
     * Assumption: installation invoices are single-payment invoices managed
     * exclusively by this service. If the billing team manually applies extra
     * payments via the regular billing UI, those allocations are preserved but
     * counted when recalculating balance_due.
     */
    private function syncPayment(Invoice $invoice, CustomerInstallation $installation, int $tenantId): void
    {
        $received   = max(0.0, (float) ($installation->payment_received ?? 0));
        $total      = (float) $invoice->total;
        $toAllocate = min($received, $total);

        // Find the (at most one) payment previously created by this flow
        $allocation      = PaymentAllocation::where('invoice_id', $invoice->id)->with('payment')->first();
        $existingPayment = $allocation?->payment;

        if ($received > 0) {
            if ($existingPayment && $allocation) {
                $existingPayment->update([
                    'amount' => $received,
                    'method' => $installation->payment_method ?: ($existingPayment->method ?: 'cash'),
                    'notes'  => $installation->payment_notes  ?: $existingPayment->notes,
                ]);
                $allocation->update(['amount' => $toAllocate]);
            } else {
                $payment = Payment::create([
                    'tenant_id'    => $tenantId,
                    'customer_id'  => $installation->customer_id,
                    'amount'       => $received,
                    'payment_date' => now()->startOfDay(),
                    'method'       => $installation->payment_method ?: 'cash',
                    'notes'        => $installation->payment_notes,
                    'status'       => 'completed',
                ]);
                PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'invoice_id' => $invoice->id,
                    'amount'     => $toAllocate,
                ]);
            }
        } else {
            // Remove the installation payment; leave any manual payments untouched
            if ($allocation) {
                $allocation->delete();
            }
            if ($existingPayment) {
                $existingPayment->refresh();
                if ($existingPayment->allocations()->count() === 0) {
                    $existingPayment->delete();
                }
            }
        }

        // Recalculate balance from ALL allocations (covers manual payments too)
        $allocated          = (float) PaymentAllocation::where('invoice_id', $invoice->id)->sum('amount');
        $invoice->balance_due = max(0.0, $total - $allocated);

        $invoice->status = match (true) {
            $total <= 0                    => 'paid',
            $invoice->balance_due <= 0     => 'paid',
            $allocated > 0                 => 'partial',
            default                        => 'issued',
        };
        $invoice->save();
    }

    /**
     * Build InvoiceItem rows from the installation billing fields.
     * Discounts are stored as negative unit_price / amount (type = 'discount').
     */
    private function buildItems(CustomerInstallation $installation): array
    {
        $items = [];

        $cost = (float) ($installation->installation_cost ?? 0);
        if ($cost > 0) {
            $items[] = [
                'type'        => 'service',
                'description' => 'Instalación de servicio',
                'quantity'    => 1,
                'unit_price'  => $cost,
                'amount'      => $cost,
            ];
        }

        $charges = (float) ($installation->additional_charges ?? 0);
        if ($charges > 0) {
            $items[] = [
                'type'        => 'charge',
                'description' => 'Cargos adicionales',
                'quantity'    => 1,
                'unit_price'  => $charges,
                'amount'      => $charges,
            ];
        }

        $discount = (float) ($installation->discount ?? 0);
        if ($discount > 0) {
            $reason  = $installation->discount_reason ?: 'Descuento';
            $items[] = [
                'type'        => 'discount',
                'description' => $reason,
                'quantity'    => 1,
                'unit_price'  => -$discount,
                'amount'      => -$discount,
            ];
        }

        return $items;
    }
}
