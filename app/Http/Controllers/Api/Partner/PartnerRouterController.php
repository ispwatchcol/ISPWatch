<?php

namespace App\Http\Controllers\Api\Partner;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Inventario de routers del tenant, en solo lectura.
 *
 * POR QUÉ HACE FALTA
 * ------------------
 * `/customers` y `/services` ya traen el `router_id` de cada abonado, así que
 * un integrador podía reconstruir el inventario a partir de los barridos. Lo
 * que no podía ver es un router SIN clientes —justo el caso de un router
 * piloto recién creado—, y tampoco su identificador antes de asignarle el
 * primero. Sin eso, el integrador terminaba buscando el router por nombre, que
 * es editable y no sirve como llave.
 *
 * QUÉ SALE Y QUÉ NO
 * -----------------
 * Sale lo que identifica al router y dice quién lo gestiona. No sale nada que
 * sirva para llegar a él: ni IP, ni puerto, ni usuario, ni contraseña, ni la
 * interfaz WAN. La tabla `router` guarda credenciales del RouterBoard, así que
 * aquí la lista blanca de columnas no es una convención: es el control.
 *
 * `managed_by_external_aaa` es informativo. NO transfiere autoridad: aunque el
 * router esté en modo AAA externo, quién tiene servicio lo sigue decidiendo
 * ISPWatch y lo publica en `access` de `/customers` y `/services`. El modo sólo
 * significa que ISPWatch dejó de escribir en ese equipo y que el AAA ejecuta.
 */
class PartnerRouterController extends PartnerController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page'                    => 'sometimes|integer|min:1',
            'per_page'                => 'sometimes|integer|min:1|max:' . self::MAX_PER_PAGE,
            'after_id'                => 'sometimes|integer|min:0|prohibits:page',
            'managed_by_external_aaa' => 'sometimes|boolean',
        ]);

        $query = $this->baseQuery($this->tenantId($request));

        if ($request->has('managed_by_external_aaa')) {
            $query->where('router.radius', $request->boolean('managed_by_external_aaa'));
        }

        $query->orderBy('router.id');

        return $this->listing(
            $query,
            $request,
            'router.id',
            fn ($row) => $row->id,
            fn ($row) => $this->present($row)
        );
    }

    public function show(Request $request, int $router): JsonResponse
    {
        $row = $this->baseQuery($this->tenantId($request))
            ->where('router.id', $router)
            ->first();

        // 404 y no 403 cuando es de otro tenant, igual que el resto de la API.
        if (!$row) {
            return response()->json([
                'error'   => 'not_found',
                'message' => 'Router no encontrado.',
            ], 404);
        }

        return response()->json(['data' => $this->present($row)]);
    }

    /**
     * Consulta sobre el query builder y no sobre el modelo: así ningún
     * accessor ni `$appends` del modelo Router puede colar un campo, y la
     * frontera de tenant es explícita, no un global scope.
     */
    private function baseQuery(int $tenantId)
    {
        $customers = DB::table('customer_profile')
            ->selectRaw('COUNT(*)')
            ->whereColumn('customer_profile.router_id', 'router.id');

        return DB::table('router')
            ->where('router.tenant_id', $tenantId)
            ->select([
                'router.id',
                'router.name',
                'router.status',
                'router.radius',
            ])
            ->selectSub($customers, 'customers_count');
    }

    private function present(object $row): array
    {
        return [
            'router_id'               => (int) $row->id,
            // Editable: sirve para mostrar, no como llave.
            'name'                    => $row->name,
            'status'                  => $row->status,
            'managed_by_external_aaa' => (bool) $row->radius,
            // 0 = router sin abonados (p. ej. un piloto recién creado).
            'customers_count'         => (int) $row->customers_count,
        ];
    }
}
