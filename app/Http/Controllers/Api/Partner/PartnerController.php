<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
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

    protected function perPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);

        return max(1, min($perPage, self::MAX_PER_PAGE));
    }

    /**
     * Envoltura de paginación uniforme para todos los listados.
     *
     * Se usa paginación por página (no por cursor) porque los listados van
     * ordenados por fecha descendente y el caso real del integrador es
     * "tráeme lo de este mes"; el cursor sólo compensa a partir de decenas de
     * miles de filas por página recorrida.
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
