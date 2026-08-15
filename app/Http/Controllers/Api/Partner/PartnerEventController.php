<?php

namespace App\Http\Controllers\Api\Partner;

use App\Models\PartnerEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Feed de cambios comerciales, consumido por cursor.
 *
 * POR QUÉ CURSOR Y NO PAGINACIÓN POR PÁGINA
 * ------------------------------------------
 * El resto de la API pública pagina por número de página, y está bien para
 * «tráeme las facturas de este mes». Aquí no sirve: el feed crece mientras el
 * integrador lo recorre, así que la página 2 de hace un minuto ya no contiene
 * las mismas filas — se saltan eventos sin que nadie lo note.
 *
 * Con cursor sobre un id autoincremental eso no puede pasar: el integrador
 * guarda el último id procesado y pide lo siguiente. Si se cae, retoma donde
 * quedó; si necesita reprocesar, vuelve atrás. No hay estado del lado nuestro.
 *
 * POR QUÉ EL EVENTO ES DELGADO
 * -----------------------------
 * Dice QUÉ cambió y de quién, no transporta el recurso completo. El consumidor
 * consulta después /customers/{id} o /services/{id} y obtiene el estado
 * definitivo. Así el feed no se desactualiza —un payload gordo puede describir
 * un estado que ya cambió dos veces— y un evento duplicado es inofensivo.
 */
class PartnerEventController extends PartnerController
{
    /** Tope propio: el feed se recorre en lotes grandes, no se navega. */
    private const MAX_LIMIT = 500;

    private const DEFAULT_LIMIT = 100;

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'since'       => 'sometimes|integer|min:0',
            'limit'       => 'sometimes|integer|min:1|max:' . self::MAX_LIMIT,
            'event_type'  => ['sometimes', Rule::in(PartnerEvent::TYPES)],
            'customer_id' => 'sometimes|integer',
        ]);

        $tenantId = $this->tenantId($request);
        $limit    = (int) $request->query('limit', self::DEFAULT_LIMIT);
        $limit    = max(1, min($limit, self::MAX_LIMIT));

        $query = PartnerEvent::query()
            ->where('tenant_id', $tenantId)
            ->where('id', '>', (int) $request->query('since', 0));

        if ($type = $request->query('event_type')) {
            $query->where('event_type', $type);
        }

        if ($customerId = $request->query('customer_id')) {
            $query->where('customer_id', (int) $customerId);
        }

        // Orden ascendente estricto por id: es lo que hace que el cursor sea
        // correcto. Cualquier otro orden rompe la garantía de no saltarse nada.
        $events = $query->orderBy('id')->limit($limit)->get();

        $last = $events->last();

        return response()->json([
            'data' => $events->map(fn (PartnerEvent $e) => [
                'event_id'    => (int) $e->id,
                'event_type'  => $e->event_type,
                'customer_id' => (int) $e->customer_id,
                'service_id'  => $e->service_id !== null ? (int) $e->service_id : null,
                'changes'     => $e->changes,
                'occurred_at' => $e->occurred_at?->toIso8601String(),
                // El id del evento ES la revisión del recurso tras el cambio:
                // permite comparar contra el `revision` que devuelven
                // /customers y /services sin pedir nada más.
                'revision'    => (int) $e->id,
            ])->values(),
            'meta' => [
                // Cursor a mandar en la próxima llamada. Si no hubo eventos se
                // devuelve el mismo `since` recibido, para que el integrador
                // pueda reintentar sin lógica especial.
                'next_since' => $last ? (int) $last->id : (int) $request->query('since', 0),
                'count'      => $events->count(),
                // true = quedan más ahora mismo; el integrador puede seguir
                // pidiendo sin esperar al próximo ciclo.
                'has_more'   => $events->count() === $limit,
            ],
        ]);
    }
}
