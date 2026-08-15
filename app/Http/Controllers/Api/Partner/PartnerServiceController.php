<?php

namespace App\Http\Controllers\Api\Partner;

use App\Models\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Servicios contratados, en solo lectura.
 *
 * QUÉ ES UN «SERVICIO» AQUÍ, Y QUÉ NO ES TODAVÍA
 * -----------------------------------------------
 * `user_services.id` es un identificador estable y distinto del cliente, que es
 * lo que un integrador necesita para no correlacionar por nombre ni documento.
 *
 * Pero hay que ser honestos sobre su alcance: los atributos de red (router, IP,
 * usuario PPPoE) todavía cuelgan de `customer_profile`, no del servicio, así
 * que en la práctica la relación es **1 cliente = 1 servicio**. Soportar varios
 * puntos por titular es una migración de modelo que toca facturación,
 * aprovisionamiento y cortes.
 *
 * El identificador se expone igual porque tener la llave correcta desde el
 * primer día evita una migración del lado del integrador; lo que no se hace es
 * prometer una semántica multi-punto que el sistema aún no puede sostener.
 *
 * DE DÓNDE SALE EL PLAN
 * ---------------------
 * De `user_services.service_plan_id`, que es lo que usa BillingService para
 * decidir qué cobrar. Existe un segundo puntero, `customer_profile.service_id`
 * —que pese al nombre también es un plan— y lo usa el aprovisionamiento.
 * `UserService::syncForCustomer()` los mantiene alineados, pero no hay
 * restricción que lo garantice. Se expone el de facturación porque es el que
 * define el estado comercial, que es la autoridad que ISPWatch conserva.
 */
class PartnerServiceController extends PartnerController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate($this->commonRules() + [
            'customer_id'    => 'sometimes|integer',
            'status'         => 'sometimes|string|max:30',
            'service_status' => 'sometimes|string|max:30',
        ]);

        $tenantId = $this->tenantId($request);
        $query    = $this->baseQuery($tenantId);

        if ($customerId = $request->query('customer_id')) {
            $query->where('user_services.user_id', (int) $customerId);
        }

        if ($status = $request->query('status')) {
            $query->where('user_services.status', $status);
        }

        if ($serviceStatus = $request->query('service_status')) {
            $query->where('customer_profile.service_status', $serviceStatus);
        }

        if ($since = $request->query('updated_since')) {
            $query->where('user_services.updated_at', '>=', $since);
        }

        $query->orderBy('user_services.id');

        return $this->paginated($query, $request, fn ($row) => $this->present($row));
    }

    public function show(Request $request, int $service): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $row = $this->baseQuery($tenantId)
            ->where('user_services.id', $service)
            ->first();

        // 404 y no 403 cuando el servicio es de otro tenant: distinguirlos le
        // confirmaría al integrador que ese id existe en la plataforma.
        if (!$row) {
            return response()->json([
                'error'   => 'not_found',
                'message' => 'Servicio no encontrado.',
            ], 404);
        }

        return response()->json(['data' => $this->present($row)]);
    }

    /**
     * La frontera de tenant es el join con `users`: ni `user_services` ni
     * `customer_profile` tienen `tenant_id` propio, así que confiar en un scope
     * de modelo dejaría abierta justamente la tabla con datos personales.
     */
    private function baseQuery(int $tenantId)
    {
        return UserService::query()
            ->join('users', 'user_services.user_id', '=', 'users.id')
            ->leftJoin('customer_profile', 'user_services.user_id', '=', 'customer_profile.user_id')
            ->leftJoin('service_plan', 'user_services.service_plan_id', '=', 'service_plan.id')
            ->leftJoin('router', 'customer_profile.router_id', '=', 'router.id')
            ->where('users.tenant_id', $tenantId)
            ->select($this->columns())
            ->selectSub($this->revisionSubquery($tenantId), 'revision');
    }

    /**
     * Revisión del recurso: el id de su último evento publicado.
     *
     * Es una subconsulta correlacionada y no un `GROUP BY` sobre toda la tabla
     * a propósito. `partner_events` crece sin techo, así que agregarla entera en
     * cada listado se degradaría con el tiempo; con el índice
     * (tenant_id, customer_id, id) esto es una búsqueda por índice por fila.
     */
    private function revisionSubquery(int $tenantId)
    {
        return DB::table('partner_events')
            ->selectRaw('MAX(id)')
            ->whereColumn('partner_events.customer_id', 'user_services.user_id')
            ->where('partner_events.tenant_id', $tenantId);
    }

    /** Lista blanca de columnas. Lo que no está aquí no sale de la API. */
    private function columns(): array
    {
        return [
            'user_services.id',
            'user_services.user_id',
            'user_services.status',
            'user_services.start_date',
            'user_services.end_date',
            'user_services.updated_at',
            'customer_profile.service_status',
            'customer_profile.ip_user',
            'customer_profile.pppoe_username',
            'customer_profile.is_fiber',
            'customer_profile.installation_date',
            'customer_profile.exclude_from_billing',
            'service_plan.id as plan_id',
            'service_plan.name as plan_name',
            'service_plan.speed_down as plan_speed_down',
            'service_plan.speed_up as plan_speed_up',
            'service_plan.cost_product as plan_price',
            'service_plan.is_courtesy as plan_is_courtesy',
            'router.id as router_id',
            'router.name as router_name',
            'router.radius as router_radius',
        ];
    }

    private function present(object $row): array
    {
        return [
            'service_id'  => (int) $row->id,
            'customer_id' => (int) $row->user_id,

            // Estado del contrato de servicio (`active` / `gratis`) frente al
            // estado comercial del cliente (`activo` / `suspendido` / ...).
            // Son cosas distintas: el segundo es el que decide si hay servicio.
            'status'         => $row->status,
            'service_status' => $row->service_status,

            'technology'          => $row->is_fiber ? 'FIBER' : 'RADIO',
            'excluded_from_billing' => (bool) $row->exclude_from_billing,

            'plan' => $row->plan_id ? [
                'id'          => (int) $row->plan_id,
                'name'        => $row->plan_name,
                'speed_down'  => $row->plan_speed_down,
                'speed_up'    => $row->plan_speed_up,
                'price'       => $row->plan_price,
                'is_courtesy' => (bool) $row->plan_is_courtesy,
            ] : null,

            'network' => [
                'ip'             => $row->ip_user,
                'pppoe_username' => $row->pppoe_username,
                'router_id'      => $row->router_id ? (int) $row->router_id : null,
                'router_name'    => $row->router_name,
                // Le dice al integrador si ISPWatch ya dejó de escribir en ese
                // equipo, o sea si la frontera técnica está establecida.
                'managed_by_external_aaa' => (bool) $row->router_radius,
            ],

            'start_date'        => $row->start_date,
            'end_date'          => $row->end_date,
            'installation_date' => $row->installation_date,

            // null = nunca cambió desde que existe el feed. No es un error:
            // significa que no hay nada nuevo que sincronizar.
            'revision'   => $row->revision !== null ? (int) $row->revision : null,
            'updated_at' => $row->updated_at,
        ];
    }
}
