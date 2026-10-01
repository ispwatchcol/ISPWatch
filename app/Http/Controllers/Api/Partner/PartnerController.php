<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Services\PartnerEventSequencer;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Base de los controladores de la API pública de solo lectura.
 *
 * Dos reglas que valen para todos sus descendientes:
 *
 * 1. NUNCA se reutiliza un controlador del panel. Los del panel devuelven el
 *    modelo entero, y ahí viajan contraseñas PPPoE/hotspot, credenciales de
 *    router y campos internos. Aquí cada endpoint declara su `select()` con
 *    columnas explícitas: lo que no se nombra, no sale. Si mañana se agrega
 *    una columna sensible a una tabla, esta API no la expone sola.
 *
 * 2. El tenant SIEMPRE se toma de la llave autenticada y se aplica como filtro
 *    explícito, aunque el modelo ya traiga el global scope de BelongsToTenant.
 *    No es redundancia inútil: `customer_profile` no tiene `tenant_id` propio
 *    (su frontera es el join con `users`), así que confiar sólo en el scope
 *    dejaría abierta justo la tabla con más datos personales.
 */
abstract class PartnerController extends Controller
{
    /** Tope duro de página: protege la base y el ancho de banda del ISP. */
    protected const MAX_PER_PAGE = 100;

    protected const DEFAULT_PER_PAGE = 50;

    /**
     * Tenant de la llave. EnsureApiKeyRequest garantiza que no es null, pero
     * se vuelve a comprobar: este valor es la única frontera entre tenants.
     */
    protected function tenantId(Request $request): int
    {
        $tenantId = $request->user()?->tenant_id;

        abort_if(!$tenantId, 403, 'La llave de API no tiene tenant asignado.');

        return (int) $tenantId;
    }

    /**
     * Publica los eventos ya confirmados antes de leer.
     *
     * Lo necesitan el feed y también `/customers` y `/services`: su `revision`
     * sale del último evento publicado, y leerla sin publicar primero podría
     * mostrar una revisión más vieja que el cambio que el integrador ya ve.
     */
    protected function publishPendingEvents(): void
    {
        app(PartnerEventSequencer::class)->publishPending();
    }

    protected function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);

        return max(1, min($perPage, self::MAX_PER_PAGE));
    }

    /**
     * Listado que pagina por cursor si el integrador manda `after_id`, y por
     * página si no.
     *
     * POR QUÉ HACE FALTA EL CURSOR EN UN BARRIDO COMPLETO (KAN-115)
     * -------------------------------------------------------------
     * Con OFFSET, si se elimina una fila anterior a la página actual mientras
     * el integrador recorre, todo se corre un lugar y **una fila que no cambió
     * se salta sin ningún error**. Como no cambió, tampoco genera evento, y
     * reproducir el feed tras el barrido no la recupera. Con `id > after_id`
     * un borrado anterior no mueve nada de lo que falta por leer.
     *
     * `page` sigue funcionando para quien ya lo usa. `$query` debe venir
     * ordenada ascendente por `$keyColumn`.
     *
     * @param callable(object):int $keyOf id de la fila cruda, para el cursor
     */
    protected function listing(
        Builder $query,
        Request $request,
        string $keyColumn,
        callable $keyOf,
        callable $map
    ): JsonResponse {
        if (!$request->filled('after_id')) {
            return $this->paginated($query, $request, $map);
        }

        $perPage = $this->perPage($request);
        $afterId = (int) $request->query('after_id');

        // Una fila de más para saber si hay otra página sin un COUNT aparte.
        $rows = $query->where($keyColumn, '>', $afterId)->limit($perPage + 1)->get();

        $hasMore = $rows->count() > $perPage;
        $rows    = $rows->take($perPage);
        $last    = $rows->last();

        return response()->json([
            'data' => $rows->map($map)->values(),
            'meta' => [
                'per_page'      => $perPage,
                'after_id'      => $afterId,
                // Sin filas se devuelve el mismo cursor, igual que el feed.
                'next_after_id' => $last ? (int) $keyOf($last) : $afterId,
                'has_more'      => $hasMore,
            ],
        ]);
    }

    /**
     * Envoltura de paginación uniforme para todos los listados.
     *
     * Paginación por página: sirve para «tráeme lo de este mes». Para recorrer
     * una colección completa sin saltarse filas, ver listing() y `after_id`.
     */
    protected function paginated(Builder $query, Request $request, callable $map): JsonResponse
    {
        $page = $query->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($page->items())->map($map)->values(),
            'meta' => [
                'page'      => $page->currentPage(),
                'per_page'  => $page->perPage(),
                'total'     => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * Reglas de validación comunes a los listados.
     *
     * Validar los filtros no es cosmético: sin `date` en `from`/`to` cualquier
     * cadena llegaría al comparador de la base y produciría un 500 en vez de
     * un 422 que el integrador pueda entender y corregir.
     */
    protected function commonRules(): array
    {
        return [
            'page'          => 'sometimes|integer|min:1',
            'per_page'      => 'sometimes|integer|min:1|max:' . self::MAX_PER_PAGE,
            'from'          => 'sometimes|date',
            'to'            => 'sometimes|date',
            'updated_since' => 'sometimes|date',
        ];
    }
}
