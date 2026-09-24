<?php

namespace App\Http\Controllers;

use App\Constants\Permissions;
use App\Http\Requests\Support\ReopenTicketInterventionRequest;
use App\Http\Requests\Support\StoreTicketInterventionRequest;
use App\Http\Requests\Support\UpdateTicketInterventionRequest;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketHistory;
use App\Models\TicketIntervention;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PR F1 · Intervenciones técnicas del ticket (§ 14 de la Solicitud Maestra).
 *
 * CÓMO SE PROTEGE EL TICKET ARCHIVADO
 *
 * Con `SupportTicket::findOrFail()` a secas, sin `withTrashed()`. El scope
 * global de `SoftDeletes` deja fuera lo archivado, así que un ticket archivado
 * responde 404 a cualquier escritura. Es exactamente lo que ya hace
 * `updateStatus` y por el motivo que documenta allí: «un ticket archivado está
 * fuera de la operación y no se mueve. Para tocarlo hay que restaurarlo
 * primero».
 *
 * La LECTURA es distinta y usa `withTrashed()` para quien puede ver archivados:
 * el § 15.10 exige que el cierre no borre las intervenciones, y archivar un
 * expediente no puede hacer desaparecer su historia para quien tiene derecho a
 * consultarla.
 *
 * NO HAY `destroy()`, Y NO SE VA A AÑADIR
 *
 * Una intervención no se borra. Si está mal, se reabre y se corrige, y la
 * corrección queda en el historial. El modelo además bloquea `deleting`, así que
 * tampoco se puede desde un comando de consola.
 */
class TicketInterventionController extends Controller
{
    /** Visitas del ticket, con su evidencia enlazada. */
    public function index(Request $request, $ticketId): JsonResponse
    {
        $ticket = $this->ticketParaLeer($request, $ticketId);

        $intervenciones = $ticket->interventions()
            ->with([
                'technician:id,user_name,user_lastname',
                'assistant:id,user_name,user_lastname',
                'attachments',
            ])
            ->get();

        return response()->json(['data' => $intervenciones]);
    }

    public function store(StoreTicketInterventionRequest $request, $ticketId): JsonResponse
    {
        $ticket = $this->ticketParaEscribir($ticketId);
        $datos  = $request->validated();

        $intervencion = DB::transaction(function () use ($ticket, $datos, $request) {
            // POR QUÉ SE BLOQUEA LA FILA DEL TICKET
            //
            // El correlativo se calcula leyendo el máximo actual. Dos técnicos
            // guardando a la vez leerían el mismo número, y uno de los dos
            // chocaría contra el índice único — un 500 feo en lugar de un
            // número correcto. Bloquear el ticket serializa el cálculo.
            //
            // En SQLite `lockForUpdate()` no emite SQL, pero el motor serializa
            // las escrituras de todos modos; el índice único sigue siendo la
            // garantía real en los dos motores.
            SupportTicket::whereKey($ticket->getKey())->lockForUpdate()->first();

            $siguiente = (int) TicketIntervention::where('support_ticket_id', $ticket->getKey())
                ->max('sequence') + 1;

            $intervencion = TicketIntervention::create($datos + [
                'tenant_id'         => $ticket->tenant_id,
                'support_ticket_id' => $ticket->getKey(),
                'sequence'          => $siguiente,
                'technician_name'   => $this->nombreDe($datos['technician_id'] ?? null),
                'assistant_name'    => $this->nombreDe($datos['assistant_id'] ?? null),
                'created_by'        => $request->user()?->id,
            ]);

            SupportTicketHistory::registrar(
                $ticket,
                SupportTicketHistory::INTERVENTION_STARTED,
                metadata: $this->resumen($intervencion),
            );

            // Una intervención que nace ya terminada —lo normal en una atención
            // remota de dos minutos— deja los DOS eventos, no uno: la cronología
            // tiene que poder responder cuándo empezó y cuándo acabó.
            if ($intervencion->finished_at !== null) {
                SupportTicketHistory::registrar(
                    $ticket,
                    SupportTicketHistory::INTERVENTION_FINISHED,
                    metadata: $this->resumen($intervencion),
                );
            }

            return $intervencion;
        });

        return response()->json([
            'message'      => 'Intervención registrada.',
            'intervention' => $intervencion->fresh(['technician', 'assistant', 'attachments']),
        ], 201);
    }

    /**
     * Edita una intervención EN CURSO.
     *
     * Si ya está finalizada se rechaza con 422 diciendo qué hacer en su lugar.
     * No es cortesía: como no hay borrado, reabrir es el único camino de
     * corrección y la interfaz tiene que poder explicarlo.
     */
    public function update(UpdateTicketInterventionRequest $request, $ticketId, $interventionId): JsonResponse
    {
        $ticket       = $this->ticketParaEscribir($ticketId);
        $intervencion = $this->intervencionDe($ticket, $interventionId);

        if ($intervencion->finished_at !== null) {
            return response()->json([
                'message' => 'Esta intervención ya está finalizada y no se puede editar. '
                    . 'Reábrela indicando el motivo y quedará registrado en el historial.',
                'error'   => 'intervention_finished',
            ], 422);
        }

        $datos = $request->validated();

        // El nombre congelado se recalcula SÓLO si cambia el técnico. Si no, se
        // conserva el que se guardó: si el empleado se dio de baja, su nombre en
        // esta visita debe seguir siendo el que era.
        if (array_key_exists('technician_id', $datos)) {
            $datos['technician_name'] = $this->nombreDe($datos['technician_id']);
        }

        if (array_key_exists('assistant_id', $datos)) {
            $datos['assistant_name'] = $this->nombreDe($datos['assistant_id']);
        }

        $seFinaliza = ($datos['finished_at'] ?? null) !== null;

        DB::transaction(function () use ($intervencion, $datos, $ticket, $seFinaliza) {
            $intervencion->fill($datos)->save();

            SupportTicketHistory::registrar(
                $ticket,
                $seFinaliza
                    ? SupportTicketHistory::INTERVENTION_FINISHED
                    : SupportTicketHistory::INTERVENTION_EDITED,
                metadata: $this->resumen($intervencion),
            );
        });

        return response()->json([
            'message'      => $seFinaliza ? 'Intervención finalizada.' : 'Intervención actualizada.',
            'intervention' => $intervencion->fresh(['technician', 'assistant', 'attachments']),
        ]);
    }

    /**
     * Reabre una intervención finalizada para poder corregirla.
     *
     * Es la contrapartida de no tener borrado: el registro no desaparece, se
     * corrige, y la corrección deja constancia de quién, cuándo y por qué.
     */
    public function reopen(ReopenTicketInterventionRequest $request, $ticketId, $interventionId): JsonResponse
    {
        $ticket       = $this->ticketParaEscribir($ticketId);
        $intervencion = $this->intervencionDe($ticket, $interventionId);

        if ($intervencion->finished_at === null) {
            return response()->json([
                'message' => 'Esta intervención sigue en curso: se puede editar directamente.',
                'error'   => 'intervention_not_finished',
            ], 422);
        }

        $motivo = $request->validated()['reason'];

        DB::transaction(function () use ($intervencion, $ticket, $motivo) {
            $cerradaEl = optional($intervencion->finished_at)->toIso8601String();

            // Éste es el único sitio que limpia `finished_at`. `update()` no
            // puede llegar aquí: rechaza con 422 cualquier intervención ya
            // finalizada antes de tocar nada. El guardia `saving` del modelo
            // deja pasar este cambio porque no toca campos de contenido, que es
            // lo que protege.
            $intervencion->fill(['finished_at' => null])->save();

            SupportTicketHistory::registrar(
                $ticket,
                SupportTicketHistory::INTERVENTION_REOPENED,
                field: 'finished_at',
                oldValue: $cerradaEl,
                newValue: null,
                metadata: $this->resumen($intervencion) + ['reason' => $motivo],
            );
        });

        return response()->json([
            'message'      => 'Intervención reabierta. Corrígela y vuelve a finalizarla.',
            'intervention' => $intervencion->fresh(['technician', 'assistant', 'attachments']),
        ]);
    }

    /**
     * Enlaza una evidencia YA SUBIDA a una intervención de este ticket.
     *
     * No sube nada: el archivo entra por el camino de siempre
     * (`PUT /support/{id}` con `attachments[]`), que ya lo deja en el bucket
     * privado y lo sirve por endpoint autenticado. Aquí sólo se dice de qué
     * visita salió, más el tipo y la descripción que pide la § 14. Así no hay
     * dos copias del mismo byte ni dos sitios donde comprobar permisos.
     */
    public function linkEvidence(Request $request, $ticketId, $interventionId): JsonResponse
    {
        $ticket       = $this->ticketParaEscribir($ticketId);
        $intervencion = $this->intervencionDe($ticket, $interventionId);

        $datos = $request->validate([
            'attachment_id' => ['required', 'integer'],
            'evidence_type' => ['sometimes', 'nullable', 'string', 'max:40'],
            'description'   => ['sometimes', 'nullable', 'string', 'max:1000'],
        ], [
            'attachment_id.required' => 'Indica qué evidencia se enlaza.',
        ]);

        // Se busca DENTRO del ticket. Sin este `where`, enlazar la evidencia de
        // otro expediente sería cuestión de acertar un id — y esta tabla no
        // tiene `tenant_id` propio, así que el cruce podría saltar de ISP.
        $evidencia = SupportTicketAttachment::where('ticket_id', $ticket->getKey())
            ->where('id', $datos['attachment_id'])
            ->firstOrFail();

        $evidencia->fill([
            'intervention_id' => $intervencion->getKey(),
            'evidence_type'   => $datos['evidence_type'] ?? $evidencia->evidence_type,
            'description'     => $datos['description'] ?? $evidencia->description,
        ])->save();

        return response()->json([
            'message'    => 'Evidencia enlazada a la intervención.',
            'attachment' => $evidencia->fresh(),
        ]);
    }

    // ── Apoyo ────────────────────────────────────────────────────────────

    /** Ticket para ESCRIBIR: lo archivado queda fuera por el scope global. */
    private function ticketParaEscribir($ticketId): SupportTicket
    {
        return SupportTicket::findOrFail($ticketId);
    }

    /** Ticket para LEER: incluye archivados si quien pide puede verlos. */
    private function ticketParaLeer(Request $request, $ticketId): SupportTicket
    {
        $usuario = $request->user();

        $puedeVerArchivados = $usuario && (
            (int) $usuario->role_id === 1
            || $usuario->hasPermission(Permissions::TICKET_ARCHIVE)
            || $usuario->hasPermission(Permissions::TICKET_RESTORE)
        );

        return SupportTicket::when($puedeVerArchivados, fn ($q) => $q->withTrashed())
            ->findOrFail($ticketId);
    }

    /** La intervención tiene que ser de ESTE ticket. 404 si no lo es. */
    private function intervencionDe(SupportTicket $ticket, $interventionId): TicketIntervention
    {
        return TicketIntervention::where('support_ticket_id', $ticket->getKey())
            ->where('id', $interventionId)
            ->firstOrFail();
    }

    /**
     * Nombre congelado del usuario, o null.
     *
     * El request ya validó que el id pertenece al tenant; el scope global de
     * `BelongsToTenant` en `User` lo vuelve a acotar aquí sin costo.
     */
    private function nombreDe(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $usuario = User::find($userId);

        if (!$usuario) {
            return null;
        }

        return trim(($usuario->user_name ?? '') . ' ' . ($usuario->user_lastname ?? '')) ?: null;
    }

    /**
     * Lo que va al historial.
     *
     * Sólo identificadores y etiquetas cortas: el historial es una cronología,
     * no una segunda copia de la intervención. Nada de hallazgo ni acción —son
     * texto largo y ya viven en su fila— y nada de rutas de almacenamiento.
     *
     * @return array<string, mixed>
     */
    private function resumen(TicketIntervention $intervencion): array
    {
        return array_filter([
            'intervention_id' => $intervencion->getKey(),
            'sequence'        => $intervencion->sequence,
            'kind'            => $intervencion->kind,
            'technician'      => $intervencion->technician_name,
        ], fn ($v) => $v !== null);
    }
}
