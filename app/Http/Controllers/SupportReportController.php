<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Models\Sectorial;
use App\Models\SupportTicket;
use App\Support\TicketCatalogs;
use App\Support\TicketMetrics;
use App\Support\TicketReportFilters;
use App\Traits\ExportsCsv;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tablero y exportación de tickets — PR #7 del seguimiento de CNO.
 *
 *   · F1-19 · mediana, P90 y P95 además del promedio (§ 17).
 *   · F1-18 · exportación completa, filtrable por infraestructura (§ 19.10).
 *   · F1-20 · cortes de día y mes, y fechas del archivo, en America/Bogota.
 *
 * Las dos rutas exigen `ticket_export`, la capacidad que el § 18 le da al
 * Auditor / gerencia («consulta, exportación, tableros»), y comparten los
 * filtros de `TicketReportFilters` para que lo que se ve y lo que se descarga
 * salgan del mismo conjunto.
 *
 * Los tickets ARCHIVADOS no entran en ninguna de las dos: `SupportTicket` usa
 * SoftDeletes y archivar es, para el negocio, sacarlo de la operación. Es lo
 * mismo que ya hacía el tablero antes de este PR (TicketArchivingTest).
 */
class SupportReportController extends Controller
{
    use ExportsCsv;

    public function __construct(private readonly TicketCatalogs $catalogs)
    {
    }

    /**
     * Tablero. Conserva TODAS las claves que devolvía antes (la pantalla y las
     * pruebas las leen) y añade `resolution_time`, `closure_time`,
     * `timezone`, `filters` y `filter_options`.
     */
    public function statistics(Request $request)
    {
        $filtros = TicketReportFilters::validar($request);
        $tenantId = $request->user()?->tenant_id;

        $baseQuery = $this->consultaBase($tenantId, $filtros);

        // Se cuenta por EQUIVALENCIA, no por el código exacto. Con los estados
        // de la Solicitud Maestra, «abiertos» ya no son sólo los `open`: son
        // también `radicado` y `en_clasificacion`, que es a lo que equivalen.
        $idsDe = fn (string $legacy) => $this->catalogs->idsEquivalentesA($legacy);

        $totalTickets = (clone $baseQuery)->count();
        $openTickets = (clone $baseQuery)->whereIn('status_id', $idsDe(SupportTicket::STATUS_OPEN))->count();
        $inProgressTickets = (clone $baseQuery)->whereIn('status_id', $idsDe(SupportTicket::STATUS_IN_PROGRESS))->count();

        // «Este mes» es el mes de BOGOTÁ (F1-20). Con `now()` en UTC, de las
        // 19:00 del último día en adelante el tablero ya contaba el mes siguiente.
        $ahora = Carbon::now(TicketMetrics::ZONA);
        $resolvedThisMonth = (clone $baseQuery)->whereIn('status_id', $idsDe(SupportTicket::STATUS_RESOLVED))
            ->where('resolved_at', '>=', $ahora->copy()->startOfMonth()->utc())
            ->count();

        [$resolucion, $cierre] = $this->distribucionesDeTiempo($baseQuery);

        // Se conserva el número que la pantalla mostraba antes —días, un
        // decimal— pero ahora sale de la MISMA distribución que los percentiles.
        // Antes se sumaba `diffInDays()` ticket a ticket.
        $avgResolutionTime = $resolucion['avg_hours'] === null ? 0 : round($resolucion['avg_hours'] / 24, 1);

        $distribucion = fn (string $columna, string $tabla, string $clave) =>
            (clone $baseQuery)->select($columna, DB::raw('count(*) as count'))
                ->whereNotNull($columna)
                ->groupBy($columna)
                ->get()
                ->map(fn ($item) => [
                    $clave  => $this->catalogs->label($tabla, (int) $item->{$columna}),
                    'code'  => $this->catalogs->code($tabla, (int) $item->{$columna}),
                    'count' => $item->count,
                ]);

        // Tendencia mensual (últimos 6 meses), con los meses de Bogotá.
        $monthlyTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $mes = $ahora->copy()->startOfMonth()->subMonthsNoOverflow($i);

            $monthlyTrend[] = [
                'month' => $mes->locale('es')->translatedFormat('M'),
                'count' => (clone $baseQuery)
                    ->whereBetween('created_at', [$mes->copy()->utc(), $mes->copy()->endOfMonth()->utc()])
                    ->count(),
            ];
        }

        $recentTickets = (clone $baseQuery)->with(['user', 'staff'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'total_tickets'       => $totalTickets,
            'open_tickets'        => $openTickets,
            'in_progress_tickets' => $inProgressTickets,
            'resolved_this_month' => $resolvedThisMonth,
            'avg_resolution_time' => $avgResolutionTime,
            'resolution_time'     => $resolucion,
            'closure_time'        => $cierre,
            'by_priority'         => $distribucion('priority_id', TicketCatalogs::PRIORITY, 'priority'),
            'by_status'           => $distribucion('status_id', TicketCatalogs::STATUS, 'status'),
            'by_category'         => $distribucion('category_id', TicketCatalogs::CATEGORY, 'category'),
            'monthly_trend'       => $monthlyTrend,
            'recent_tickets'      => $recentTickets,
            'timezone'            => TicketMetrics::ZONA,
            'filters'             => array_filter($filtros, fn ($v) => $v !== null && $v !== ''),
            'filter_options'      => $this->opcionesDeFiltro($tenantId),
        ]);
    }

    /**
     * Exportación CSV de TODOS los tickets del filtro, no de una página.
     *
     * «Completa» (§ 19.10) quiere decir que el archivo trae lo que el
     * expediente sabe del ticket: cliente, infraestructura, técnico, el
     * diagnóstico con código y etiqueta, los tiempos y la cantidad de
     * intervenciones. El código va aparte de la etiqueta porque la etiqueta es
     * editable (R1) y el código no: quien cruce el archivo con otro sistema
     * necesita el que no cambia.
     */
    public function export(Request $request)
    {
        $filtros = TicketReportFilters::validar($request);
        $tenantId = $request->user()?->tenant_id;

        $query = $this->consultaBase($tenantId, $filtros)
            ->with(['user.customerProfile.router', 'staff', 'sectorial'])
            ->withCount('interventions')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        $diagnostico = [
            'symptom'         => 'Síntoma',
            'suspected_cause' => 'Causa sospechada',
            'confirmed_cause' => 'Causa confirmada',
            'solution'        => 'Acción',
            'result'          => 'Resultado',
        ];

        $zona = TicketMetrics::ZONA;
        $columnas = [
            'Ticket', "Creado ({$zona})", 'Estado', 'Código estado', 'Prioridad', 'Categoría',
            'Asunto', 'Descripción', 'Cliente', 'Cédula', 'Router', 'Sectorial', 'Técnico',
        ];
        foreach ($diagnostico as $nombre) {
            $columnas[] = $nombre;
            $columnas[] = "Código {$nombre}";
        }
        array_push(
            $columnas,
            "Resuelto ({$zona})", "Cerrado ({$zona})", 'Horas hasta resolución', 'Horas hasta cierre',
            'Intervenciones', 'Sin cobro', 'Motivo sin cobro',
        );

        $nombreArchivo = 'tickets-' . Carbon::now($zona)->format('Y-m-d') . '.csv';

        return $this->streamCsv($nombreArchivo, $columnas, $query, function (SupportTicket $t) use ($diagnostico, $zona) {
            $perfil = $t->user?->customerProfile;
            $cliente = $perfil
                ? trim(($perfil->name ?? '') . ' ' . ($perfil->last_name ?? ''))
                : trim(($t->user?->user_name ?? '') . ' ' . ($t->user?->user_lastname ?? ''));
            $tecnico = $t->staff ? trim(($t->staff->user_name ?? '') . ' ' . ($t->staff->user_lastname ?? '')) : '';

            $fila = [
                $t->id,
                $this->csvDateTime($t->created_at, $zona),
                $this->csvText($t->status_label),
                $t->status,
                $this->csvText($t->priority_label),
                $this->csvText($t->category_label),
                $this->csvText($t->subject),
                $this->csvText($t->description),
                $this->csvText($cliente),
                $this->csvText($perfil?->cedula),
                $this->csvText($perfil?->router?->name),
                $this->csvText($t->sectorial?->name),
                $this->csvText($tecnico),
            ];

            $diag = $t->diagnosis;
            foreach (array_keys($diagnostico) as $campo) {
                $fila[] = $this->csvText($diag[$campo]['label'] ?? '');
                $fila[] = $diag[$campo]['code'] ?? '';
            }

            array_push(
                $fila,
                $this->csvDateTime($t->resolved_at, $zona),
                $this->csvDateTime($t->closed_at, $zona),
                $this->horasEntre($t->created_at, $t->resolved_at),
                $this->horasEntre($t->created_at, $t->closed_at),
                $t->interventions_count,
                $t->no_charge ? 'Sí' : 'No',
                $this->csvText($t->no_charge_reason),
            );

            return $fila;
        });
    }

    // ── Internos ─────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $filtros */
    private function consultaBase(?int $tenantId, array $filtros): Builder
    {
        // El scope global ya acota al tenant; el `where` explícito se conserva
        // como en el resto del módulo (OWASP A01): un reporte es justo el
        // sitio donde una fuga entre ISPs sería masiva.
        $query = SupportTicket::query();
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        return TicketReportFilters::aplicar($query, $filtros, $this->catalogs);
    }

    /**
     * Distribuciones de «radicado → resuelto» y «radicado → cerrado».
     *
     * Son los dos tiempos del § 17 que el modelo registra hoy. Primera
     * atención, diagnóstico, asignación y llegada no tienen timestamp propio
     * todavía, y fabricarlos desde el historial sería inventar una definición
     * que el cliente no ha dado.
     *
     * Una duración negativa (fecha de resolución anterior a la de radicado, por
     * datos importados) se descarta y se cuenta aparte: meterla movería la
     * mediana sin que nadie lo notara.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function distribucionesDeTiempo(Builder $baseQuery): array
    {
        $filas = (clone $baseQuery)
            ->where(fn ($q) => $q->whereNotNull('resolved_at')->orWhereNotNull('closed_at'))
            ->toBase()
            ->select(['created_at', 'resolved_at', 'closed_at'])
            ->get();

        $resolucion = $cierre = [];
        $descartadas = ['resolution' => 0, 'closure' => 0];

        foreach ($filas as $fila) {
            foreach (['resolved_at' => 'resolution', 'closed_at' => 'closure'] as $columna => $clave) {
                if ($fila->{$columna} === null) {
                    continue;
                }

                $minutos = Carbon::parse($fila->created_at, 'UTC')
                    ->diffInMinutes(Carbon::parse($fila->{$columna}, 'UTC'), false);

                if ($minutos < 0) {
                    $descartadas[$clave]++;

                    continue;
                }

                if ($clave === 'resolution') {
                    $resolucion[] = $minutos;
                } else {
                    $cierre[] = $minutos;
                }
            }
        }

        return [
            TicketMetrics::resumen($resolucion) + ['excluded' => $descartadas['resolution']],
            TicketMetrics::resumen($cierre) + ['excluded' => $descartadas['closure']],
        ];
    }

    /**
     * Routers y sectoriales del ISP para los filtros del tablero.
     *
     * Van en la misma respuesta para no depender de `/routers` ni de
     * `/sectorials`, que exigen permisos de otros módulos: un Auditor con
     * `ticket_export` tiene que poder filtrar sin que le den `manage_routers`.
     * Sólo id y nombre, que es lo que el expediente del ticket ya muestra.
     *
     * @return array{routers: mixed, sectorials: mixed}
     */
    private function opcionesDeFiltro(?int $tenantId): array
    {
        $deEsteIsp = fn ($q) => $tenantId ? $q->where('tenant_id', $tenantId) : $q;

        return [
            'routers'    => $deEsteIsp(Router::query())->orderBy('name')->get(['id', 'name']),
            'sectorials' => $deEsteIsp(Sectorial::query())->orderBy('name')->get(['id', 'name']),
        ];
    }

    private function horasEntre($desde, $hasta): string
    {
        if (! $desde || ! $hasta) {
            return '';
        }

        $minutos = Carbon::parse($desde)->diffInMinutes(Carbon::parse($hasta), false);

        return $minutos < 0 ? '' : number_format($minutos / 60, 2, ',', '');
    }
}
