<?php

namespace App\Http\Controllers\Api\Partner;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Facturas y pagos del tenant, en solo lectura.
 *
 * `invoices` y `payments` sí llevan `tenant_id` propio, así que el filtro es
 * directo. Aun así se escribe explícito en cada consulta en vez de descansar en
 * el global scope: `Payment` no usa el trait BelongsToTenant (sólo tiene la
 * columna), y depender de qué modelo lo usa y cuál no es exactamente el tipo de
 * detalle que se olvida al agregar un endpoint.
 */
class PartnerBillingController extends PartnerController
{
    public function invoices(Request $request): JsonResponse
    {
        $request->validate($this->commonRules() + [
            'status'      => 'sometimes|string|max:30',
            'customer_id' => 'sometimes|integer',
        ]);

        $tenantId = $this->tenantId($request);

        $query = Invoice::query()
            ->where('invoices.tenant_id', $tenantId)
            ->select([
                'invoices.id',
                'invoices.customer_id',
                'invoices.number',
                'invoices.invoice_type',
                'invoices.issue_date',
                'invoices.due_date',
                'invoices.period_start',
                'invoices.period_end',
                'invoices.currency',
                'invoices.subtotal',
                'invoices.tax',
                'invoices.total',
                'invoices.balance_due',
                'invoices.status',
                'invoices.created_at',
                'invoices.updated_at',
            ]);

        if ($status = $request->query('status')) {
            $query->where('invoices.status', $status);
        }

        if ($customerId = $request->query('customer_id')) {
            $query->where('invoices.customer_id', (int) $customerId);
        }

        // `from`/`to` filtran por fecha de emisión, que es el criterio con el
        // que un contador cierra un periodo.
        if ($from = $request->query('from')) {
            $query->whereDate('invoices.issue_date', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('invoices.issue_date', '<=', $to);
        }

        if ($since = $request->query('updated_since')) {
            $query->where('invoices.updated_at', '>=', $since);
        }

        $query->orderByDesc('invoices.issue_date')->orderByDesc('invoices.id');

        return $this->paginated($query, $request, fn ($row) => [
            'id'           => (int) $row->id,
            'customer_id'  => (int) $row->customer_id,
            'number'       => $row->number,
            'type'         => $row->invoice_type,
            'issue_date'   => $row->issue_date,
            'due_date'     => $row->due_date,
            'period_start' => $row->period_start,
            'period_end'   => $row->period_end,
            'currency'     => $row->currency,
            'subtotal'     => $row->subtotal,
            'tax'          => $row->tax,
            'total'        => $row->total,
            'balance_due'  => $row->balance_due,
            'status'       => $row->status,
            'created_at'   => $row->created_at,
            'updated_at'   => $row->updated_at,
        ]);
    }

    public function payments(Request $request): JsonResponse
    {
        $request->validate($this->commonRules() + [
            'customer_id' => 'sometimes|integer',
            'status'      => 'sometimes|string|max:30',
        ]);

        $tenantId = $this->tenantId($request);

        $query = Payment::query()
            ->where('payments.tenant_id', $tenantId)
            ->select([
                'payments.id',
                'payments.customer_id',
                'payments.amount',
                'payments.payment_date',
                'payments.method',
                'payments.reference',
                'payments.status',
                'payments.created_at',
            ]);

        if ($customerId = $request->query('customer_id')) {
            $query->where('payments.customer_id', (int) $customerId);
        }

        if ($status = $request->query('status')) {
            $query->where('payments.status', $status);
        }

        if ($from = $request->query('from')) {
            $query->whereDate('payments.payment_date', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('payments.payment_date', '<=', $to);
        }

        $query->orderByDesc('payments.payment_date')->orderByDesc('payments.id');

        return $this->paginated($query, $request, fn ($row) => [
            'id'           => (int) $row->id,
            'customer_id'  => (int) $row->customer_id,
            'amount'       => $row->amount,
            'payment_date' => $row->payment_date,
            'method'       => $row->method,
            'reference'    => $row->reference,
            'status'       => $row->status,
            'created_at'   => $row->created_at,
        ]);
    }
}
