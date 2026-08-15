<?php

namespace App\Http\Controllers\Api\Partner;

use App\Models\CustomerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Clientes del tenant, en solo lectura.
 *
 * Deliberadamente FUERA de la respuesta, aunque estén en las mismas tablas:
 * `pppoe_password`, `hotspot_password` y `mac_address`. Las dos primeras son
 * credenciales de red reutilizables; la tercera permite suplantar el equipo de
 * un abonado. Nada de eso hace falta para conciliar cartera o alimentar un CRM,
 * que es para lo que existe esta API.
 */
class PartnerCustomerController extends PartnerController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate($this->commonRules() + [
            'service_status' => 'sometimes|string|max:30',
            'router_id'      => 'sometimes|integer',
            'document'       => 'sometimes|string|max:40',
        ]);

        $tenantId = $this->tenantId($request);

        $query = CustomerProfile::query()
            ->join('users', 'customer_profile.user_id', '=', 'users.id')
            ->leftJoin('service_plan', 'customer_profile.service_id', '=', 'service_plan.id')
            ->leftJoin('sectorial', 'customer_profile.sectorial_id', '=', 'sectorial.id')
            ->leftJoin('router', 'customer_profile.router_id', '=', 'router.id')
            ->where('users.tenant_id', $tenantId)
            ->select($this->columns())
            ->selectSub($this->revisionSubquery($tenantId), 'revision');

        if ($status = $request->query('service_status')) {
            $query->where('customer_profile.service_status', $status);
        }

        if ($routerId = $request->query('router_id')) {
            $query->where('customer_profile.router_id', (int) $routerId);
        }

        // Búsqueda auxiliar por documento: sirve para el alta inicial, cuando
        // el integrador todavía no tiene el id. Es coincidencia EXACTA a
        // propósito — una búsqueda parcial sobre documentos convierte esta
        // ruta en un enumerador de la base de clientes del ISP.
        if ($document = $request->query('document')) {
            $query->where('customer_profile.cedula', $document);
        }

        // `customer_profile` no tiene timestamps propios: el reloj del cliente
        // es `users.updated_at`, que es lo que se mueve al editar la ficha.
        if ($since = $request->query('updated_since')) {
            $query->where('users.updated_at', '>=', $since);
        }

        $query->orderBy('users.id');

        return $this->paginated($query, $request, fn ($row) => $this->present($row));
    }

    public function show(Request $request, int $customer): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $row = CustomerProfile::query()
            ->join('users', 'customer_profile.user_id', '=', 'users.id')
            ->leftJoin('service_plan', 'customer_profile.service_id', '=', 'service_plan.id')
            ->leftJoin('sectorial', 'customer_profile.sectorial_id', '=', 'sectorial.id')
            ->leftJoin('router', 'customer_profile.router_id', '=', 'router.id')
            ->where('users.tenant_id', $tenantId)
            ->where('customer_profile.user_id', $customer)
            ->select($this->columns())
            ->selectSub($this->revisionSubquery($tenantId), 'revision')
            ->first();

        // 404 y no 403 cuando el cliente es de otro tenant: distinguirlos le
        // confirmaría al integrador que ese id existe en la plataforma.
        if (!$row) {
            return response()->json([
                'error'   => 'not_found',
                'message' => 'Cliente no encontrado.',
            ], 404);
        }

        return response()->json(['data' => $this->present($row)]);
    }

    /**
     * Revisión del recurso: el id de su último evento publicado.
     *
     * Subconsulta correlacionada y no `GROUP BY` sobre toda la tabla:
     * `partner_events` crece sin techo, así que agregarla entera en cada
     * listado se degrada con el tiempo. Con el índice
     * (tenant_id, customer_id, id) esto es una búsqueda por índice por fila.
     */
    private function revisionSubquery(int $tenantId)
    {
        return DB::table('partner_events')
            ->selectRaw('MAX(id)')
            ->whereColumn('partner_events.customer_id', 'customer_profile.user_id')
            ->where('partner_events.tenant_id', $tenantId);
    }

    /** Lista blanca de columnas. Lo que no está aquí no sale de la API. */
    private function columns(): array
    {
        return [
            'customer_profile.user_id',
            'customer_profile.name',
            'customer_profile.last_name',
            'customer_profile.is_company',
            'customer_profile.cedula',
            'customer_profile.address',
            'customer_profile.city',
            'customer_profile.state',
            'customer_profile.country',
            'customer_profile.postal_code',
            'customer_profile.latitude',
            'customer_profile.longitude',
            'customer_profile.installation_date',
            'customer_profile.status',
            'customer_profile.service_status',
            'customer_profile.credit_balance',
            'customer_profile.exclude_from_billing',
            'customer_profile.is_fiber',
            'customer_profile.ip_user',
            'customer_profile.pppoe_username',
            'users.email',
            'users.tel',
            'users.created_at',
            'users.updated_at',
            'service_plan.id as plan_id',
            'service_plan.name as plan_name',
            'service_plan.speed_down as plan_speed_down',
            'service_plan.speed_up as plan_speed_up',
            'service_plan.cost_product as plan_price',
            'sectorial.name as sectorial_name',
            'router.name as router_name',
        ];
    }

    private function present(object $row): array
    {
        return [
            'id'                   => (int) $row->user_id,
            'name'                 => $row->name,
            'last_name'            => $row->last_name,
            'is_company'           => (bool) $row->is_company,
            'document'             => $row->cedula,
            'email'                => $row->email,
            'phone'                => $row->tel,
            'address'              => $row->address,
            'city'                 => $row->city,
            'state'                => $row->state,
            'country'              => $row->country,
            'postal_code'          => $row->postal_code,
            'latitude'             => $row->latitude,
            'longitude'            => $row->longitude,
            'installation_date'    => $row->installation_date,
            'is_enabled'           => (bool) $row->status,
            'service_status'       => $row->service_status,
            'credit_balance'       => $row->credit_balance,
            'excluded_from_billing' => (bool) $row->exclude_from_billing,
            'is_fiber'             => (bool) $row->is_fiber,
            'ip'                   => $row->ip_user,
            'pppoe_username'       => $row->pppoe_username,
            'plan'                 => $row->plan_id ? [
                'id'          => (int) $row->plan_id,
                'name'        => $row->plan_name,
                'speed_down'  => $row->plan_speed_down,
                'speed_up'    => $row->plan_speed_up,
                'price'       => $row->plan_price,
            ] : null,
            'sectorial'  => $row->sectorial_name,
            'router'     => $row->router_name,
            // null = este cliente no ha cambiado desde que existe el feed. No
            // es un error: significa que no hay nada nuevo que sincronizar.
            'revision'   => $row->revision !== null ? (int) $row->revision : null,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
