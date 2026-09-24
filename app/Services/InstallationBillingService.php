<?php

namespace App\Services;

use App\Models\CustomerCredit;
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
            // El pago sobre el que hay que cuadrar el excedente. Se asigna en
            // cada rama y no con `$existingPayment ?? $payment`: cuando hay un
            // pago viejo pero su asignacion se perdio, la rama que corre es la
            // de creacion, y aquella expresion habria acreditado el excedente
            // sobre el pago equivocado.
            $pagoVigente = null;

            if ($existingPayment && $allocation) {
                $existingPayment->update([
                    'amount' => $received,
                    'method' => $installation->payment_method ?: ($existingPayment->method ?: 'cash'),
                    'notes'  => $installation->payment_notes  ?: $existingPayment->notes,
                ]);
                $allocation->update(['amount' => $toAllocate]);
                $pagoVigente = $existingPayment;
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
                $pagoVigente = $payment;
            }

            // El instalador cobró más de lo que valía la instalación: ese
            // excedente es dinero recibido, y hasta ahora no iba a ninguna
            // parte. `$toAllocate` es min(recibido, total), así que la
            // diferencia no quedaba ni asignada a la factura ni como saldo a
            // favor: desaparecía de los libros y sólo reaparecía como un
            // descuadre de caja (BooksAuditService C6).
            $this->syncExcessCredit($pagoVigente, $received - $toAllocate);
        } else {
            // Remove the installation payment; leave any manual payments untouched
            if ($allocation) {
                $allocation->delete();
            }
            if ($existingPayment) {
                $existingPayment->refresh();
                if ($existingPayment->allocations()->count() === 0) {
                    // Devolver el saldo a favor que hubiera generado este pago
                    // ANTES de borrarlo: si no, el movimiento `earned` queda
                    // apuntando a un pago que ya no existe y el saldo del
                    // cliente conserva plata que nunca entró.
                    CustomerCredit::reverseForPayment($existingPayment);
                    $existingPayment->delete();
                }
            }
        }

        // Recalculate balance from ALL allocations (covers manual payments too)
        $allocated = (float) PaymentAllocation::where('invoice_id', $invoice->id)->sum('amount');

        // El saldo a favor también salda facturas, y NO deja fila en
        // `payment_allocations`. Sin este término, recalcular el saldo aquí
        // borraba el crédito ya aplicado a esta factura y el cliente volvía a
        // deber algo que ya había pagado.
        $creditApplied = abs((float) CustomerCredit::where('to_invoice_id', $invoice->id)
            ->where('type', CustomerCredit::TYPE_APPLIED)
            ->sum('amount'));

        $invoice->balance_due = max(0.0, $total - $allocated - $creditApplied);

        $invoice->status = match (true) {
            $total <= 0                    => 'paid',
            $invoice->balance_due <= 0     => 'paid',
            $allocated > 0                 => 'partial',
            default                        => 'issued',
        };
        $invoice->save();
    }

    /**
     * Deja el saldo a favor generado por este pago en exactamente $excedente.
     *
     * El cobro de una instalación puede cambiar varias veces (el instalador
     * corrige lo recibido, se aplica un descuento, cambia el valor), así que no
     * basta con acreditar una vez: hay que cuadrar contra lo que ya se acreditó
     * antes, o cada edición sumaría saldo de nuevo.
     *
     * Bajar el excedente sólo devuelve la parte que todavía no consumió ninguna
     * factura — la misma doctrina de `reverseForPayment()` y de los arrastres:
     * lo que ya viajó a otra factura se queda donde está, porque quitarlo
     * cobraría dos veces.
     */
    private function syncExcessCredit(?Payment $payment, float $excedente): void
    {
        $excedente = round(max(0.0, $excedente), 2);

        // Sin titular no hay libro de saldo al que apuntar (P-43): el pago
        // existe por su valor contable, pero un saldo A FAVOR DE alguien no
        // significa nada sin ese alguien.
        if (!$payment || !$payment->customer_id) {
            return;
        }

        $ganado    = (float) CustomerCredit::where('from_payment_id', $payment->id)
            ->where('type', CustomerCredit::TYPE_EARNED)->sum('amount');
        $revertido = abs((float) CustomerCredit::where('from_payment_id', $payment->id)
            ->where('type', CustomerCredit::TYPE_REVERSED)->sum('amount'));

        $vigente = round($ganado - $revertido, 2);
        $delta   = round($excedente - $vigente, 2);

        if (abs($delta) < 0.01) {
            return;
        }

        if ($delta > 0) {
            CustomerCredit::earn(
                $payment,
                $delta,
                "Excedente del cobro de instalación (pago #{$payment->id})"
            );

            return;
        }

        // El excedente bajó: se devuelve lo devolvible y se vuelve a dejar el
        // que corresponda. Si lo ya consumido supera el excedente nuevo no se
        // hace nada más: ese saldo ya pagó facturas y no se puede deshacer.
        $r = CustomerCredit::reverseForPayment($payment);

        $porReponer = round($excedente - $r['kept'], 2);

        if ($porReponer > 0) {
            CustomerCredit::earn(
                $payment,
                $porReponer,
                "Excedente del cobro de instalación (pago #{$payment->id})"
            );
        }
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

        // Adicionales itemizados (router adicional, cable extra, etc.); si no hay
        // desglose se factura el monto agregado legacy como una sola línea.
        $additionalItems = collect($installation->additional_items ?? [])
            ->filter(fn ($it) => (float) ($it['amount'] ?? 0) > 0);

        if ($additionalItems->isNotEmpty()) {
            foreach ($additionalItems as $it) {
                $amount  = (float) $it['amount'];
                $items[] = [
                    'type'        => 'charge',
                    'description' => $it['description'] ?: 'Cargo adicional',
                    'quantity'    => 1,
                    'unit_price'  => $amount,
                    'amount'      => $amount,
                ];
            }
        } else {
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
