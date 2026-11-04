<?php

namespace App\Http\Controllers;

use App\Constants\Permissions;
use App\Models\SupportTicket;
use App\Services\TicketIncidentService;
use App\Support\TicketWorkflow;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lectura de vínculos entre tickets — PR #6 de CNO (§ 16, § 19.7 y § 19.8).
 *
 * Sólo LEE. Vincular un duplicado o asociar a un incidente es una TRANSICIÓN
 * (`PATCH /support/{id}/status` con `duplicate_of_id` o `parent_ticket_id`):
 * el vínculo y el estado se escriben juntos o no se escribe ninguno.
 *
 * Las dos rutas van con `ticket_view`: quien radica tiene que ver si el
 * cliente ya tiene un ticket abierto, y quien mira un expediente tiene que ver
 * con qué otros está relacionado. Un ticket de otro ISP no aparece (scope de
 * `BelongsToTenant`).
 */
class TicketLinkController extends Controller
{
    public function __construct(private readonly TicketIncidentService $incidentes)
    {
    }

    /**
     * Prevención de duplicados (§ 16): antes de radicar —o al vincular— los
     * tickets abiertos del mismo servicio y los incidentes activos sobre su
     * infraestructura.
     *
     * `GET /support/open-for?user_id=&sectorial_id=&exclude=`
     */
    public function openFor(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;
        $delIsp = fn (string $tabla) => $tenantId
            ? Rule::exists($tabla, 'id')->where('tenant_id', $tenantId)
            : Rule::exists($tabla, 'id');

        $data = $request->validate([
            'user_id'      => ['nullable', 'integer', $delIsp('users')],
            'sectorial_id' => ['nullable', 'integer', $delIsp('sectorial')],
            'exclude'      => ['nullable', 'integer'],
        ]);

        $userId = isset($data['user_id']) ? (int) $data['user_id'] : null;
        $sectorialId = isset($data['sectorial_id']) ? (int) $data['sectorial_id'] : null;
        $excluir = isset($data['exclude']) ? (int) $data['exclude'] : null;

        return response()->json([
            'open_tickets' => $userId
                ? $this->incidentes->abiertosDelCliente($userId, $excluir)->map(fn ($t) => $this->resumen($t))->values()
                : [],
            'active_incidents' => $this->incidentes->incidentesActivos($userId, $sectorialId, $excluir)
                ->map(fn ($t) => $this->resumenDeIncidente($t))->values(),
        ]);
    }

    /**
     * Con qué está relacionado un ticket, y su reincidencia (§ 16).
     *
     * `GET /support/{ticket}/links`
     */
    public function links(Request $request, $ticket)
    {
        $ticket = SupportTicket::query()
            ->when($this->puedeVerArchivados($request), fn ($q) => $q->withTrashed())
            ->findOrFail($ticket);

        $ticket->load([
            'parent' => fn ($q) => $q->withTrashed()->with('sectorial:id,name,element_type'),
            'duplicateOf' => fn ($q) => $q->withTrashed(),
        ]);

        $hijos = $ticket->esMasivo()
            ? $ticket->incidentChildren()->with('user.customerProfile:user_id,name,last_name')->orderBy('created_at')->get()
            : collect();

        $duplicados = $ticket->duplicates()->orderBy('created_at')->get();

        return response()->json([
            'modality'         => $ticket->modality,
            'parent'           => $ticket->parent ? $this->resumenDeIncidente($ticket->parent) : null,
            'duplicate_of'     => $ticket->duplicateOf ? $this->resumen($ticket->duplicateOf) : null,
            'incident_children' => $hijos->map(fn ($t) => $this->resumen($t) + [
                'customer' => $this->nombreDelCliente($t),
            ])->values(),
            'duplicates'       => $duplicados->map(fn ($t) => $this->resumen($t))->values(),
            // Un masivo no tiene servicio propio: su reincidencia es la del
            // elemento, que también sale aquí.
            'recurrence'       => $this->incidentes->reincidencia($ticket),
        ]);
    }

    // ── Internos ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function resumen(SupportTicket $t): array
    {
        return [
            'id'           => $t->id,
            'subject'      => $t->subject,
            'status'       => $t->status,
            'status_label' => $t->status_label,
            'is_terminal'  => TicketWorkflow::esTerminal($t->status),
            'modality'     => $t->modality,
            'created_at'   => $t->created_at?->toJSON(),
            'is_archived'  => $t->is_archived,
        ];
    }

    /** @return array<string, mixed> */
    private function resumenDeIncidente(SupportTicket $t): array
    {
        return $this->resumen($t) + [
            'sectorial' => $t->sectorial ? [
                'id'           => $t->sectorial->id,
                'name'         => $t->sectorial->name,
                'element_type' => $t->sectorial->element_type,
            ] : null,
            'children_count' => $t->incident_children_count ?? $t->incidentChildren()->count(),
        ];
    }

    private function nombreDelCliente(SupportTicket $t): string
    {
        $perfil = $t->user?->customerProfile;

        return $perfil
            ? trim(($perfil->name ?? '') . ' ' . ($perfil->last_name ?? ''))
            : trim(($t->user?->user_name ?? '') . ' ' . ($t->user?->user_lastname ?? ''));
    }

    /** El mismo criterio que el detalle del ticket (PR C). */
    private function puedeVerArchivados(Request $request): bool
    {
        $u = $request->user();

        return (int) $u?->role_id === 1
            || $u?->hasPermission(Permissions::TICKET_ARCHIVE)
            || $u?->hasPermission(Permissions::TICKET_RESTORE);
    }
}
