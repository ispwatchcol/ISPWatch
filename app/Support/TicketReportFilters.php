<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Filtros compartidos por el tablero y la exportación de tickets (PR #7).
 *
 * Un solo sitio a propósito: si el tablero y el archivo filtraran distinto, el
 * cliente vería un P90 en pantalla y otro al recalcularlo sobre la exportación
 * «con los mismos filtros». La Solicitud Maestra (§ 19.10) pide exportar
 * «filtrando por infraestructura»: hoy eso es el **router** del cliente y el
 * **sectorial** del ticket, que es lo que el modelo asocia (F1-06 parcial).
 *
 * Las fechas llegan como día calendario de Bogotá y se convierten a un rango
 * UTC antes de tocar la base, que guarda en UTC (F1-20).
 */
final class TicketReportFilters
{
    /** Filtros por catálogo: llegan como CÓDIGO y se filtra por clave foránea. */
    private const CATALOGOS = [
        'status'   => ['status_id',   TicketCatalogs::STATUS],
        'priority' => ['priority_id', TicketCatalogs::PRIORITY],
        'category' => ['category_id', TicketCatalogs::CATEGORY],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function validar(Request $request): array
    {
        return $request->validate([
            'status'       => ['nullable', 'string', 'max:60'],
            'priority'     => ['nullable', 'string', 'max:60'],
            'category'     => ['nullable', 'string', 'max:60'],
            'subject_id'   => ['nullable', 'regex:/^(all|other|\d+)$/'],
            'staff_id'     => ['nullable', 'regex:/^(all|unassigned|\d+)$/'],
            'sectorial_id' => ['nullable', 'integer'],
            'router_id'    => ['nullable', 'integer'],
            'created_from' => ['nullable', 'date_format:Y-m-d'],
            'created_to'   => ['nullable', 'date_format:Y-m-d', 'after_or_equal:created_from'],
            'search'       => ['nullable', 'string', 'max:200'],
        ], [
            'created_to.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ]);
    }

    /**
     * Aplica los filtros ya validados sobre una consulta de `SupportTicket`.
     *
     * Un id de router o sectorial de OTRO ISP no da error: simplemente no
     * casa con ningún ticket, porque la consulta ya viene acotada al tenant.
     *
     * @param  array<string, mixed>  $f
     */
    public static function aplicar(Builder $query, array $f, TicketCatalogs $catalogos): Builder
    {
        foreach (self::CATALOGOS as $campo => [$columna, $tabla]) {
            $valor = $f[$campo] ?? null;

            if ($valor !== null && $valor !== '' && $valor !== 'all') {
                // Un código inexistente resuelve a null y no devuelve tickets,
                // en vez de ignorarse y devolverlos todos.
                $query->where($columna, $catalogos->id($tabla, $valor));
            }
        }

        $asunto = $f['subject_id'] ?? null;
        if ($asunto === 'other') {
            $query->whereNull('subject_id');
        } elseif ($asunto !== null && $asunto !== 'all') {
            $query->where('subject_id', (int) $asunto);
        }

        $tecnico = $f['staff_id'] ?? null;
        if ($tecnico === 'unassigned') {
            $query->whereNull('staff_id');
        } elseif ($tecnico !== null && $tecnico !== 'all') {
            $query->where('staff_id', (int) $tecnico);
        }

        if (! empty($f['sectorial_id'])) {
            $query->where('sectorial_id', (int) $f['sectorial_id']);
        }

        if (! empty($f['router_id'])) {
            // El router no es del ticket sino del CLIENTE: vive en su ficha.
            $router = (int) $f['router_id'];
            $query->whereHas('user.customerProfile', fn ($q) => $q->where('router_id', $router));
        }

        if (! empty($f['created_from'])) {
            $query->where('created_at', '>=', self::inicioDelDia($f['created_from']));
        }

        if (! empty($f['created_to'])) {
            $query->where('created_at', '<=', self::finDelDia($f['created_to']));
        }

        if (! empty($f['search'])) {
            $texto = $f['search'];
            $query->where(fn ($q) => $q->whereLike('subject', $texto)->orWhereLike('description', $texto));
        }

        return $query;
    }

    /** 00:00:00 de ese día EN BOGOTÁ, expresado en UTC para comparar con la base. */
    public static function inicioDelDia(string $dia): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $dia, TicketMetrics::ZONA)->startOfDay()->utc();
    }

    /** 23:59:59.999999 de ese día EN BOGOTÁ, expresado en UTC. */
    public static function finDelDia(string $dia): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $dia, TicketMetrics::ZONA)->endOfDay()->utc();
    }
}
