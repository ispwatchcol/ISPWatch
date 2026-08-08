<?php

namespace App\Http\Controllers\Api\Partner;

use App\Models\CustomerInstallation;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tickets de soporte e instalaciones del tenant, en solo lectura.
 *
 * Fuera de la respuesta a propósito: `sheet` (el acta de instalación, un JSON
 * con datos del acta y referencias a fotos) y las rutas de las firmas
 * digitalizadas. Son documentos con valor probatorio y firma manuscrita del
 * abonado; se consultan desde el panel, no se sirven por API.
 */
class PartnerSupportController extends PartnerController
{
    public function tickets(Request $request): JsonResponse
    {
        $request->validate($this->commonRules() + [
            'status'      => 'sometimes|string|max:30',
            'priority'    => 'sometimes|string|max:30',
            'customer_id' => 'sometimes|integer',
        ]);

        $tenantId = $this->tenantId($request);

        $query = SupportTicket::query()
            ->where('support_ticket.tenant_id', $tenantId)
            ->select([
                'support_ticket.id',
                'support_ticket.user_id',
                'support_ticket.subject',
                'support_ticket.description',
                'support_ticket.status',
                'support_ticket.priority',
                'support_ticket.category',
                'support_ticket.resolved_at',
                'support_ticket.created_at',
                'support_ticket.updated_at',
            ]);

        if ($status = $request->query('status')) {
            $query->where('support_ticket.status', $status);
        }

        if ($priority = $request->query('priority')) {
            $query->where('support_ticket.priority', $priority);
        }

        if ($customerId = $request->query('customer_id')) {
            $query->where('support_ticket.user_id', (int) $customerId);
        }

        if ($from = $request->query('from')) {
            $query->whereDate('support_ticket.created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('support_ticket.created_at', '<=', $to);
        }

        if ($since = $request->query('updated_since')) {
            $query->where('support_ticket.updated_at', '>=', $since);
        }

        $query->orderByDesc('support_ticket.created_at')->orderByDesc('support_ticket.id');

        return $this->paginated($query, $request, fn ($row) => [
            'id'          => (int) $row->id,
            'customer_id' => $row->user_id ? (int) $row->user_id : null,
            'subject'     => $row->subject,
            'description' => $row->description,
            'status'      => $row->status,
            'priority'    => $row->priority,
            'category'    => $row->category,
            'resolved_at' => $row->resolved_at,
            'created_at'  => $row->created_at,
            'updated_at'  => $row->updated_at,
        ]);
    }

    public function installations(Request $request): JsonResponse
    {
        $request->validate($this->commonRules() + [
            'status'      => 'sometimes|string|max:30',
            'customer_id' => 'sometimes|integer',
        ]);

        $tenantId = $this->tenantId($request);

        $query = CustomerInstallation::query()
            ->where('customer_installations.tenant_id', $tenantId)
            ->select([
                'customer_installations.id',
                'customer_installations.customer_id',
                'customer_installations.prospect_id',
                'customer_installations.scheduled_date',
                'customer_installations.technician',
                'customer_installations.address',
                'customer_installations.status',
                'customer_installations.completed_at',
                'customer_installations.signed_at',
                'customer_installations.installation_cost',
                'customer_installations.additional_charges',
                'customer_installations.discount',
                'customer_installations.payment_received',
                'customer_installations.created_at',
                'customer_installations.updated_at',
            ]);

        if ($status = $request->query('status')) {
            $query->where('customer_installations.status', $status);
        }

        if ($customerId = $request->query('customer_id')) {
            $query->where('customer_installations.customer_id', (int) $customerId);
        }

        if ($from = $request->query('from')) {
            $query->whereDate('customer_installations.scheduled_date', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('customer_installations.scheduled_date', '<=', $to);
        }

        if ($since = $request->query('updated_since')) {
            $query->where('customer_installations.updated_at', '>=', $since);
        }

        $query->orderByDesc('customer_installations.scheduled_date')
              ->orderByDesc('customer_installations.id');

        return $this->paginated($query, $request, fn ($row) => [
            'id'                 => (int) $row->id,
            'customer_id'        => $row->customer_id ? (int) $row->customer_id : null,
            'prospect_id'        => $row->prospect_id ? (int) $row->prospect_id : null,
            'scheduled_date'     => $row->scheduled_date,
            'technician'         => $row->technician,
            'address'            => $row->address,
            'status'             => $row->status,
            'completed_at'       => $row->completed_at,
            'signed_at'          => $row->signed_at,
            'installation_cost'  => $row->installation_cost,
            'additional_charges' => $row->additional_charges,
            'discount'           => $row->discount,
            'payment_received'   => $row->payment_received,
            'created_at'         => $row->created_at,
            'updated_at'         => $row->updated_at,
        ]);
    }
}
