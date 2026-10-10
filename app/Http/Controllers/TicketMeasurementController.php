<?php

namespace App\Http\Controllers;

use App\Constants\Permissions;
use App\Http\Requests\Support\StoreTicketMeasurementRequest;
use App\Http\Requests\Support\UpdateTicketMeasurementRequest;
use App\Models\SupportTicket;
use App\Models\SupportTicketHistory;
use App\Models\TicketIntervention;
use App\Models\TicketMeasurement;
use App\Support\TicketMeasurements;
use App\Support\TicketWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PR F2 · Mediciones técnicas del ticket (§ 12 y § 13 de la Solicitud Maestra).
 *
 * CÓMO SE PROTEGE EL TICKET ARCHIVADO
 *
 * Con `SupportTicket::findOrFail()` sin `withTrashed()`. El scope global de
 * `SoftDeletes` deja fuera lo archivado, así que responde 404 a cualquier
 * escritura. Es lo mismo que hacen `updateStatus` y el controlador de
 * intervenciones, por el motivo que documenta el primero: «un ticket archivado
 * está fuera de la operación y no se mueve».
 *
 * La lectura sí usa `withTrashed()` para quien puede ver archivados: el § 15.10
 * exige que el expediente conserve su historia, y las mediciones son parte de
 * ella.
 *
 * NO HAY `destroy()`
 *
 * Una medición no se borra: es la constancia de lo que se leyó, y el § 15.5 la
 * convierte en requisito de cierre. Poder hacerla desaparecer equivaldría a
 * poder saltarse el requisito sin que constara. El modelo además bloquea
 * `deleting`.
 */
class TicketMeasurementController extends Controller
{
    /** Mediciones del ticket, agrupadas para la comparación del § 13. */
    public function index(Request $request, $ticketId): JsonResponse
    {
        $ticket = $this->ticketParaLeer($request, $ticketId);

        $mediciones = $ticket->measurements()
            ->with(['intervention:id,sequence,kind'])
            ->get();

        return response()->json([
            'data' => $mediciones,

            // § 13: «comparar el estado técnico antes y después de la
            // intervención». La comparación se arma aquí y no en el navegador
            // para que la regla de emparejamiento sea una sola y esté probada.
            'comparison' => $this->comparar($mediciones),

            // Lo que la pantalla necesita para decidir si puede proponer o
            // cerrar, y para explicar por qué no.
            'final_test' => [
                'present' => TicketMeasurement::tienePruebaFinal((int) $ticket->getKey()),
                'waiver'  => $ticket->final_test_waiver_reason ? [
                    'reason'       => $ticket->final_test_waiver_reason,
                    'reason_label' => TicketMeasurements::razonesSinPruebaFinal()[$ticket->final_test_waiver_reason] ?? null,
                    'note'         => $ticket->final_test_waiver_note,
                ] : null,
            ],
        ]);
    }

    public function store(StoreTicketMeasurementRequest $request, $ticketId): JsonResponse
    {
        $ticket = $this->ticketParaEscribir($ticketId);

        if ($cerrado = $this->rechazoSiEstaCerrado($ticket)) {
            return $cerrado;
        }

        $datos = $request->validated();

        if ($error = $this->intervencionInvalida($ticket, $datos['intervention_id'] ?? null)) {
            return $error;
        }

        $usuario = $request->user();

        $medicion = DB::transaction(function () use ($ticket, $datos, $usuario) {
            $medicion = TicketMeasurement::create($datos + [
                'tenant_id'         => $ticket->tenant_id,
                'support_ticket_id' => $ticket->getKey(),
                'recorded_by'       => $usuario?->id,
                'recorded_by_name'  => $this->nombreDe($usuario),
            ]);

            SupportTicketHistory::registrar(
                $ticket,
                SupportTicketHistory::MEASUREMENT_RECORDED,
                field: $medicion->test_type,
                newValue: $this->legible($medicion),
                metadata: $this->resumen($medicion),
            );

            return $medicion;
        });

        return response()->json([
            'message'     => 'Medición registrada.',
            'measurement' => $medicion->fresh(['intervention']),
        ], 201);
    }

    /**
     * Corrige una medición mientras el ticket siga abierto.
     *
     * Un dígito mal tecleado no es lo mismo que una medición que no existió: por
     * eso se puede corregir, pero no borrar. El cerrojo aquí no es un estado de
     * la medición —como en las intervenciones— sino el del ticket: una vez
     * cerrado, el expediente no se retoca.
     */
    public function update(UpdateTicketMeasurementRequest $request, $ticketId, $measurementId): JsonResponse
    {
        $ticket = $this->ticketParaEscribir($ticketId);

        if ($cerrado = $this->rechazoSiEstaCerrado($ticket)) {
            return $cerrado;
        }

        $medicion = $this->medicionDe($ticket, $measurementId);
        $datos    = $request->validated();

        if ($error = $this->intervencionInvalida($ticket, $datos['intervention_id'] ?? null)) {
            return $error;
        }

        $antes = $this->legible($medicion);

        DB::transaction(function () use ($medicion, $datos, $ticket, $antes) {
            $medicion->fill($datos)->save();

            SupportTicketHistory::registrar(
                $ticket,
                SupportTicketHistory::MEASUREMENT_UPDATED,
                field: $medicion->test_type,
                oldValue: $antes,
                newValue: $this->legible($medicion),
                metadata: $this->resumen($medicion),
            );
        });

        return response()->json([
            'message'     => 'Medición corregida.',
            'measurement' => $medicion->fresh(['intervention']),
        ]);
    }

    // ── Apoyo ────────────────────────────────────────────────────────────

    /**
     * Empareja inicial y final por tipo de prueba, para el § 13.
     *
     * Se toma la ÚLTIMA de cada fase por tipo, no la primera: si se corrigió una
     * lectura registrando otra, la buena es la de después. `seguimiento` no
     * entra en el par —el documento compara «antes y después»— pero se devuelve
     * aparte para que la pantalla pueda mostrarlo.
     *
     * @param  \Illuminate\Support\Collection<int, TicketMeasurement>  $mediciones
     * @return array<int, array<string, mixed>>
     */
    private function comparar($mediciones): array
    {
        $porTipo = [];

        foreach ($mediciones as $m) {
            // Las mediciones llegan ordenadas por `measured_at`, así que la
            // última asignación de cada fase gana.
            $porTipo[$m->test_type][$m->phase] = $m;
        }

        $salida = [];

        foreach ($porTipo as $tipo => $fases) {
            $inicial = $fases[TicketMeasurements::FASE_INICIAL] ?? null;
            $final   = $fases[TicketMeasurements::FASE_FINAL] ?? null;

            $salida[] = [
                'test_type' => $tipo,
                'initial'   => $inicial ? $this->legible($inicial) : null,
                'follow_up' => isset($fases[TicketMeasurements::FASE_SEGUIMIENTO])
                    ? $this->legible($fases[TicketMeasurements::FASE_SEGUIMIENTO])
                    : null,
                'final'     => $final ? $this->legible($final) : null,
                // Qué falta para poder comparar. La pantalla lo necesita para
                // decir «sin medición final» en vez de dejar la celda vacía.
                'complete'  => $inicial !== null && $final !== null,
            ];
        }

        return $salida;
    }

    /** «−76 dBm», «conectado». Valor y unidad juntos, que es como se lee. */
    private function legible(TicketMeasurement $m): string
    {
        return trim($m->value . ' ' . ($m->unit ?? ''));
    }

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

    /**
     * Un ticket cerrado no admite mediciones nuevas ni correcciones.
     *
     * Registrar una medición después del cierre dejaría el expediente diciendo
     * que se midió algo que no se tuvo en cuenta al decidir cerrar. Si de verdad
     * hace falta, el camino es reabrir el ticket, que ya existe y deja evento.
     */
    private function rechazoSiEstaCerrado(SupportTicket $ticket): ?JsonResponse
    {
        if (!TicketWorkflow::esTerminal($ticket->status)) {
            return null;
        }

        return response()->json([
            'message' => 'El ticket ya está cerrado: no admite mediciones nuevas ni correcciones. '
                . 'Si hace falta registrar una, reábrelo primero — así queda constancia de por qué.',
            'error'   => 'ticket_already_closed',
            'status'  => $ticket->status,
        ], 422);
    }

    /** La intervención, si se indica, tiene que ser de ESTE ticket. */
    private function intervencionInvalida(SupportTicket $ticket, $intervencionId): ?JsonResponse
    {
        if (blank($intervencionId)) {
            return null;
        }

        $existe = TicketIntervention::where('support_ticket_id', $ticket->getKey())
            ->where('id', $intervencionId)
            ->exists();

        if ($existe) {
            return null;
        }

        return response()->json([
            'message' => 'Esa intervención no pertenece a este ticket.',
            'error'   => 'intervention_not_in_ticket',
        ], 422);
    }

    /** La medición tiene que ser de ESTE ticket. 404 si no lo es. */
    private function medicionDe(SupportTicket $ticket, $measurementId): TicketMeasurement
    {
        return TicketMeasurement::where('support_ticket_id', $ticket->getKey())
            ->where('id', $measurementId)
            ->firstOrFail();
    }

    private function nombreDe($usuario): ?string
    {
        if (!$usuario) {
            return null;
        }

        return trim(($usuario->user_name ?? '') . ' ' . ($usuario->user_lastname ?? '')) ?: null;
    }

    /**
     * Lo que va al historial.
     *
     * Identificadores y etiquetas cortas. El valor legible viaja en
     * `old_value`/`new_value`, que es donde el requerimiento del § 18 lo pide.
     *
     * @return array<string, mixed>
     */
    private function resumen(TicketMeasurement $m): array
    {
        return array_filter([
            'measurement_id'  => $m->getKey(),
            'phase'           => $m->phase,
            'source'          => $m->source,
            'intervention_id' => $m->intervention_id,
        ], fn ($v) => $v !== null);
    }
}
