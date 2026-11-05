<?php

namespace App\Services;

use App\Models\CustomerProfile;
use App\Models\Sectorial;
use App\Models\SupportTicket;
use App\Support\TicketCatalogs;
use App\Support\TicketWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Duplicados, incidentes masivos y reincidencias — PR #6 de CNO (§ 16).
 *
 *   · Prevención de duplicados: «antes de crear, revisar tickets abiertos del
 *     mismo servicio e incidentes activos en su AP, PON, nodo o enlace».
 *   · Incidente padre: «relacionar múltiples tickets individuales con una sola
 *     falla común de infraestructura».
 *   · Reincidencia: «repeticiones por servicio, síntoma, causa o
 *     infraestructura en ventanas de 7, 30 y 90 días».
 *
 * EL «SERVICIO» ES EL CLIENTE. Mientras siga abierta **D-01**, el esquema
 * impone 1 cliente = 1 servicio, así que «tickets del mismo servicio» se
 * resuelve por `user_id`. El día que exista `service_id` en el ticket, este es
 * el único sitio que cambia.
 *
 * LA INFRAESTRUCTURA ES EL ELEMENTO DE RED (`sectorial`): AP, nodo, OLT,
 * splitter, NAP… El ticket la guarda en `sectorial_id` («elemento afectado»)
 * y el cliente la tiene en su ficha (`sectorial_id` y `olt_id`). Los elementos
 * cuelgan en árbol por `parent_id`, así que una caída del nodo afecta a los
 * clientes de todos los AP que cuelgan de él: por eso se suben los ancestros.
 */
class TicketIncidentService
{
    /** Ventanas de reincidencia del § 16 y del § 17, en días. */
    public const VENTANAS = [7, 30, 90];

    /** Tope de niveles al subir por el árbol: protege de un ciclo en los datos. */
    private const PROFUNDIDAD_MAXIMA = 10;

    public function __construct(private readonly TicketCatalogs $catalogs)
    {
    }

    // ── Validación de los vínculos ───────────────────────────────────────

    /**
     * Por qué `$ticket` NO puede marcarse como duplicado de `$original`, o
     * `null` si puede.
     *
     * Un duplicado es «el mismo problema del mismo servicio reportado dos
     * veces». Por eso se exige el mismo cliente: dos clientes distintos con la
     * misma falla no son un duplicado, son un incidente masivo.
     */
    public function motivoParaNoDuplicar(SupportTicket $ticket, ?SupportTicket $original): ?string
    {
        return match (true) {
            $original === null                       => 'El ticket original no existe.',
            $original->is($ticket)                   => 'Un ticket no puede ser duplicado de sí mismo.',
            $original->status === TicketWorkflow::DUPLICADO
                => 'El ticket #' . $original->id . ' ya es un duplicado'
                    . ($original->duplicate_of_id ? ' del #' . $original->duplicate_of_id : '')
                    . '. Vincula el ticket original.',
            $ticket->esMasivo() || $original->esMasivo()
                => 'Un incidente masivo no se marca como duplicado: los reportes de clientes se ASOCIAN a él.',
            $ticket->user_id === null || (int) $original->user_id !== (int) $ticket->user_id
                => 'Sólo es duplicado un ticket del mismo cliente. Si es la misma falla en otro cliente, asócialos a un incidente masivo.',
            default => null,
        };
    }

    /** Por qué `$ticket` NO puede asociarse a `$incidente`, o `null` si puede. */
    public function motivoParaNoAsociar(SupportTicket $ticket, ?SupportTicket $incidente): ?string
    {
        return match (true) {
            $incidente === null                     => 'El incidente masivo no existe.',
            $incidente->is($ticket)                 => 'Un ticket no puede asociarse a sí mismo.',
            ! $incidente->esMasivo()                => 'El ticket #' . $incidente->id . ' no es un incidente masivo.',
            $ticket->esMasivo()                     => 'Un incidente masivo no se asocia a otro: no hay incidentes anidados.',
            TicketWorkflow::esTerminal($incidente->status)
                => 'El incidente #' . $incidente->id . ' ya está cerrado. Reábrelo o crea uno nuevo.',
            default => null,
        };
    }

    // ── Detección al radicar ─────────────────────────────────────────────

    /**
     * Tickets abiertos del mismo servicio (= cliente, D-01).
     *
     * @return Collection<int, SupportTicket>
     */
    public function abiertosDelCliente(int $userId, ?int $excluir = null): Collection
    {
        return SupportTicket::query()
            ->where('user_id', $userId)
            ->whereNotIn('status_id', $this->idsTerminales())
            ->when($excluir, fn ($q) => $q->whereKeyNot($excluir))
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'subject', 'status_id', 'priority_id', 'category_id', 'created_at', 'modality', 'user_id']);
    }

    /**
     * Incidentes masivos ACTIVOS sobre la infraestructura del cliente, o sobre
     * el elemento indicado, contando sus ancestros.
     *
     * Sin cliente ni elemento devuelve TODOS los masivos activos: así el
     * operador puede elegir uno aunque el cliente no tenga la infraestructura
     * cargada en la ficha, que es lo frecuente en datos migrados.
     *
     * @return Collection<int, SupportTicket>
     */
    public function incidentesActivos(?int $userId = null, ?int $sectorialId = null, ?int $excluir = null): Collection
    {
        $elementos = $this->elementosDe($userId, $sectorialId);
        $filtrarPorElemento = $userId !== null || $sectorialId !== null;

        return SupportTicket::query()
            ->where('modality', SupportTicket::MODALITY_MASIVO)
            ->whereNotIn('status_id', $this->idsTerminales())
            ->when($filtrarPorElemento, fn ($q) => $q->whereIn('sectorial_id', $elementos ?: [0]))
            ->when($excluir, fn ($q) => $q->whereKeyNot($excluir))
            ->with('sectorial:id,name,element_type')
            ->withCount('incidentChildren')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'subject', 'status_id', 'priority_id', 'category_id', 'created_at', 'modality', 'sectorial_id']);
    }

    /**
     * Los elementos de red del cliente y del elemento dado, con sus ancestros.
     *
     * @return array<int, int>
     */
    public function elementosDe(?int $userId, ?int $sectorialId = null): array
    {
        $semillas = [];

        if ($sectorialId) {
            $semillas[] = $sectorialId;
        }

        if ($userId) {
            $perfil = CustomerProfile::query()->where('user_id', $userId)->first(['sectorial_id', 'olt_id']);
            array_push($semillas, $perfil?->sectorial_id, $perfil?->olt_id);
        }

        $vistos = [];
        $nivel = array_values(array_filter(array_map('intval', array_filter($semillas))));

        for ($i = 0; $nivel !== [] && $i < self::PROFUNDIDAD_MAXIMA; $i++) {
            $nuevos = array_values(array_diff($nivel, $vistos));
            if ($nuevos === []) {
                break;
            }
            $vistos = array_merge($vistos, $nuevos);

            $nivel = Sectorial::query()
                ->whereIn('id', $nuevos)
                ->whereNotNull('parent_id')
                ->pluck('parent_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return array_values(array_unique($vistos));
    }

    // ── Reincidencia ─────────────────────────────────────────────────────

    /**
     * Reincidencia de UN ticket: cuántos tickets anteriores hubo en cada
     * ventana, por cada una de las cuatro dimensiones del § 16.
     *
     * «Anterior» es radicado ANTES que este y dentro de la ventana contada
     * hacia atrás desde su radicación. No cuentan los duplicados —son el mismo
     * reporte dos veces, no una repetición de la falla— ni los archivados
     * (scope de SoftDeletes).
     *
     * Servicio, síntoma y causa se cuentan sobre el MISMO cliente: «el mismo
     * síntoma» en otro cliente no es reincidencia de este servicio. La
     * infraestructura se cuenta sobre el MISMO elemento, sea cual sea el
     * cliente: es la reincidencia del elemento.
     *
     * @return array{windows: array<int, array{service: int, symptom: int, cause: int, infrastructure: int}>, previous: Collection}
     */
    public function reincidencia(SupportTicket $ticket): array
    {
        $creado = $ticket->created_at ?? Carbon::now();
        $desde = $creado->copy()->subDays(max(self::VENTANAS));
        $idDuplicado = $this->catalogs->id(TicketCatalogs::STATUS, TicketWorkflow::DUPLICADO);

        $anteriores = function () use ($ticket, $creado, $desde, $idDuplicado): Builder {
            return SupportTicket::query()
                ->whereKeyNot($ticket->getKey())
                ->where('created_at', '<', $creado)
                ->where('created_at', '>=', $desde)
                ->when($idDuplicado, fn ($q) => $q->where(fn ($w) => $w->whereNull('status_id')->orWhere('status_id', '!=', $idDuplicado)));
        };

        $delCliente = $ticket->user_id
            ? $anteriores()->where('user_id', $ticket->user_id)->orderByDesc('created_at')
                ->get(['id', 'subject', 'status_id', 'created_at', 'symptom_id', 'confirmed_cause_id', 'sectorial_id'])
            : collect();

        $delElemento = $ticket->sectorial_id
            ? $anteriores()->where('sectorial_id', $ticket->sectorial_id)->get(['id', 'created_at'])
            : collect();

        $ventanas = [];
        foreach (self::VENTANAS as $dias) {
            $limite = $creado->copy()->subDays($dias);
            $enVentana = fn ($t) => $t->created_at >= $limite;

            $cliente = $delCliente->filter($enVentana);

            $ventanas[$dias] = [
                'service'        => $cliente->count(),
                'symptom'        => $ticket->symptom_id
                    ? $cliente->where('symptom_id', $ticket->symptom_id)->count() : 0,
                'cause'          => $ticket->confirmed_cause_id
                    ? $cliente->where('confirmed_cause_id', $ticket->confirmed_cause_id)->count() : 0,
                'infrastructure' => $delElemento->filter($enVentana)->count(),
            ];
        }

        return [
            'windows'  => $ventanas,
            'previous' => $delCliente->map(fn (SupportTicket $t) => [
                'id'           => $t->id,
                'subject'      => $t->subject,
                'status'       => $t->status,
                'status_label' => $t->status_label,
                'created_at'   => $t->created_at?->toJSON(),
            ])->values(),
        ];
    }

    /**
     * Tasa de reincidencia del tablero (§ 17): de los tickets del filtro,
     * cuántos tuvieron OTRO ticket del mismo cliente en los N días anteriores.
     *
     * Entran sólo tickets con cliente y que no son duplicados: un masivo no
     * tiene servicio propio y un duplicado inflaría la cifra con el mismo
     * reporte contado dos veces. Los tickets anteriores se buscan FUERA del
     * filtro de fechas —un ticket del 2 de marzo reincide sobre uno del 25 de
     * febrero aunque el tablero mire sólo marzo—, pero dentro del mismo ISP.
     *
     * @return array{base: int, windows: array<int, array{count: int, rate: float|null}>}
     */
    public function tasaDeReincidencia(Builder $baseQuery): array
    {
        $idDuplicado = $this->catalogs->id(TicketCatalogs::STATUS, TicketWorkflow::DUPLICADO);
        $sinDuplicados = fn ($q) => $idDuplicado
            ? $q->where(fn ($w) => $w->whereNull('status_id')->orWhere('status_id', '!=', $idDuplicado))
            : $q;

        $tickets = $sinDuplicados((clone $baseQuery)->whereNotNull('user_id'))
            ->toBase()
            ->select(['id', 'user_id', 'created_at'])
            ->get();

        $vacio = ['base' => $tickets->count(), 'windows' => []];
        foreach (self::VENTANAS as $dias) {
            $vacio['windows'][$dias] = ['count' => 0, 'rate' => $tickets->isEmpty() ? null : 0.0];
        }

        if ($tickets->isEmpty()) {
            return $vacio;
        }

        $fechas = $tickets->map(fn ($t) => Carbon::parse($t->created_at, 'UTC'));
        $desde = $fechas->min()->copy()->subDays(max(self::VENTANAS));
        $hasta = $fechas->max();

        // Historial de esos clientes, en tandas: un ISP grande puede tener
        // miles de clientes con ticket y PostgreSQL limita los parámetros.
        $historial = collect();
        foreach ($tickets->pluck('user_id')->unique()->chunk(1000) as $tanda) {
            $historial = $historial->merge(
                $sinDuplicados(SupportTicket::query())
                    ->whereIn('user_id', $tanda->values()->all())
                    ->whereBetween('created_at', [$desde, $hasta])
                    ->toBase()
                    ->select(['id', 'user_id', 'created_at'])
                    ->get()
            );
        }
        $porCliente = $historial->groupBy('user_id');

        $conteo = array_fill_keys(self::VENTANAS, 0);

        foreach ($tickets as $t) {
            $creado = Carbon::parse($t->created_at, 'UTC');

            // El anterior MÁS CERCANO decide todas las ventanas a la vez.
            $previo = ($porCliente[$t->user_id] ?? collect())
                ->filter(fn ($h) => (int) $h->id !== (int) $t->id && Carbon::parse($h->created_at, 'UTC') < $creado)
                ->map(fn ($h) => Carbon::parse($h->created_at, 'UTC'))
                ->max();

            if ($previo === null) {
                continue;
            }

            foreach (self::VENTANAS as $dias) {
                if ($previo >= $creado->copy()->subDays($dias)) {
                    $conteo[$dias]++;
                }
            }
        }

        $base = $tickets->count();
        $resultado = ['base' => $base, 'windows' => []];
        foreach (self::VENTANAS as $dias) {
            $resultado['windows'][$dias] = [
                'count' => $conteo[$dias],
                'rate'  => round($conteo[$dias] * 100 / $base, 1),
            ];
        }

        return $resultado;
    }

    // ── Internos ─────────────────────────────────────────────────────────

    /** @return array<int, int> */
    private function idsTerminales(): array
    {
        return collect(TicketWorkflow::TERMINALES)
            ->map(fn ($code) => $this->catalogs->id(TicketCatalogs::STATUS, $code))
            ->filter()
            ->values()
            ->all();
    }
}
