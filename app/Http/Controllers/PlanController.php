<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\MikroTikSshService;
use App\Models\Plan;
use App\Traits\FixesSequences;
use App\Http\Requests\StorePlanRequest;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    use FixesSequences;
    public function index(Request $request)
    {
        return response()->json([
            'data' => Plan::with('typePlan')
                ->withCount(['userServices' => function ($q) use ($request) {
                    $tenantId = $request->user()?->tenant_id;
                    $customerRoleIds = $tenantId
                        ? \App\Models\Role::idsByName('Cliente', $tenantId)
                        : [3];
                    $q->whereHas('user', fn($u) => $u->where('status', true)->whereIn('role_id', $customerRoleIds))
                      ->whereIn('status', ['active', 'gratis']);
                }])
                ->get()
                ->map(fn($plan) => [
                    ...$plan->toArray(),
                    'active_clients_count' => $plan->user_services_count,
                ])
        ]);
    }

    // 👇 ESTE ES EL MÉTODO QUE TE FALTABA PARA QUE CARGUE EL EDITAR
    public function show(Request $request, $id)
    {
        // BelongsToTenant global scope auto-filters by tenant
        $plan = Plan::findOrFail($id);
        return response()->json($plan);
    }

    public function store(StorePlanRequest $request)
    {
        $plan = $this->createWithSequenceFix(Plan::class, $request->validated());

        return response()->json([
            'success' => true,
            'data' => $plan->load('typePlan')
        ], 201);
    }

    // 👇 ESTE ES EL MÉTODO QUE TE FALTABA PARA GUARDAR EL EDITAR
    public function update(Request $request, $id)
    {
        // BelongsToTenant global scope auto-filters by tenant
        $plan = Plan::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'speed_down' => 'sometimes|string',
            'speed_up' => 'sometimes|string',
            'cost_product' => 'sometimes|numeric',
            'commit' => 'nullable|string',
            'type' => 'sometimes|string',
            'type_plan_id' => 'sometimes|exists:type_plans,id',
            'priority' => 'nullable|integer|min:1|max:8',
            'burst_download' => 'nullable|string',
            'burst_upload' => 'nullable|string',
            'pppoe_pool' => 'nullable|string',
            'local_address' => 'nullable|string',
            'shared_users' => 'nullable|integer|min:1',
            'session_timeout' => 'nullable|string',
            'idle_timeout' => 'nullable|string',
            'pcq_rate' => 'nullable|string',
            'address_mask' => 'nullable|string',
            // Plan de cortesía: el cliente queda en 'gratis' y nunca se factura.
            'is_courtesy' => 'sometimes|boolean',
        ]);

        $plan->update($validated);

        return response()->json([
            'success' => true,
            'data' => $plan
        ]);
    }

    public function destroy(Request $request, $id)
    {
        // BelongsToTenant global scope auto-filters by tenant
        $plan = Plan::findOrFail($id);

        try {
            $plan->delete();
        } catch (QueryException $e) {
            // 23503 = foreign_key_violation (PostgreSQL): alguna tabla aún referencia
            // el plan con RESTRICT. Devolvemos un mensaje legible en vez de un 500.
            if (($e->getCode() === '23503') || str_contains($e->getMessage(), 'foreign key')) {
                return response()->json([
                    'message' => 'No se puede eliminar el plan porque tiene datos asociados que lo referencian.',
                ], 409);
            }

            throw $e;
        }

        return response()->json([
            'message' => 'Plan eliminado'
        ]);
    }

    /**
     * Sync a PPPoE plan as a /ppp profile on the selected client router.
     */
    public function syncPppoeProfile(Request $request, Plan $plan)
    {
        $data = $request->validate([
            'router_id' => 'required|integer|exists:router,id',
        ]);

        $typeCode = $plan->typePlan?->code ?? $plan->type;
        if (strtolower((string) $typeCode) !== 'pppoe') {
            return response()->json([
                'success' => false,
                'message' => 'Solo los planes PPPoE se pueden cargar como perfil en la RB.',
            ], 422);
        }

        $router = Router::findOrFail($data['router_id']);

        if (!$router->ip || !$router->user_rb || !$router->password_rb) {
            $missing = [];
            if (!$router->ip) $missing[] = 'IP VPN (¿VPN conectada?)';
            if (!$router->user_rb) $missing[] = 'usuario de gestión';
            if (!$router->password_rb) $missing[] = 'contraseña de gestión';

            return response()->json([
                'success' => false,
                'message' => 'El router no tiene credenciales completas: ' . implode(', ', $missing) . '. Genera el script VPN y conéctalo primero.',
            ], 422);
        }

        // Resolve the address/port the CORE must really dial: `router.ip` goes
        // stale whenever the L2TP tunnel reconnects onto another pool address.
        $endpoint = app(\App\Services\MikroTik\RouterEndpointResolver::class)->resolve($router);

        $mikrotik = app(MikroTikSshService::class);
        $result = $mikrotik->syncPppoeProfileOnRouter(
            $endpoint['ip'],
            $router->user_rb,
            $router->password_rb,
            $plan->name,
            $plan->is_courtesy ? '0' : $plan->speed_up,
            $plan->is_courtesy ? '0' : $plan->speed_down,
            $plan->local_address,
            $plan->pppoe_pool,
            $endpoint['api_port'],
            $endpoint['ssh_port']
        );

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'No se pudo sincronizar el perfil PPPoE.',
                'details' => $result,
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Perfil PPPoE cargado correctamente en la RB ' . $router->name . ' (' . $router->ip . ')',
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
            ],
            'router' => [
                'id' => $router->id,
                'name' => $router->name,
                'ip' => $router->ip,
                'pppoe_enabled' => (bool) $router->pppoe,
            ],
            'result' => $result,
        ]);
    }

    /**
     * Load the HotSpot user profile of a plan onto a router (per-plan engine).
     * Mirror of syncPppoeProfile for HotSpot plans.
     */
    public function syncHotspotProfile(Request $request, Plan $plan)
    {
        $data = $request->validate([
            'router_id' => 'required|integer|exists:router,id',
        ]);

        $typeCode = $plan->typePlan?->code ?? $plan->type;
        if (strtolower((string) $typeCode) !== 'hotspot') {
            return response()->json([
                'success' => false,
                'message' => 'Solo los planes HotSpot se pueden cargar como perfil HotSpot en la RB.',
            ], 422);
        }

        $router = Router::findOrFail($data['router_id']);

        if ($missing = $this->missingRouterCredentials($router)) {
            return response()->json([
                'success' => false,
                'message' => 'El router no tiene credenciales completas: ' . implode(', ', $missing) . '. Genera el script VPN y conéctalo primero.',
            ], 422);
        }

        // Resolve the address/port the CORE must really dial: `router.ip` goes
        // stale whenever the L2TP tunnel reconnects onto another pool address.
        $endpoint = app(\App\Services\MikroTik\RouterEndpointResolver::class)->resolve($router);

        $mikrotik = app(MikroTikSshService::class);
        $result = $mikrotik->syncHotspotUserProfileOnRouter(
            $endpoint['ip'],
            $router->user_rb,
            $router->password_rb,
            $plan->name,
            $plan->is_courtesy ? '0' : $plan->speed_up,
            $plan->is_courtesy ? '0' : $plan->speed_down,
            $plan->shared_users !== null ? (int) $plan->shared_users : null,
            $plan->session_timeout,
            $plan->idle_timeout,
            $endpoint['api_port'],
            $endpoint['ssh_port']
        );

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'No se pudo sincronizar el perfil HotSpot.',
                'details' => $result,
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Perfil HotSpot cargado correctamente en la RB ' . $router->name . ' (' . $router->ip . ')',
            'plan'    => ['id' => $plan->id, 'name' => $plan->name],
            'router'  => ['id' => $router->id, 'name' => $router->name, 'ip' => $router->ip, 'hotspot_enabled' => (bool) $router->hotspot],
            'result'  => $result,
        ]);
    }

    /**
     * Build the PCQ engine of a plan onto a router (per-plan): pcq queue types,
     * mangle rules for the plan address-list, and the queue trees.
     */
    public function syncPcqEngine(Request $request, Plan $plan)
    {
        $data = $request->validate([
            'router_id' => 'required|integer|exists:router,id',
        ]);

        $typeCode = $plan->typePlan?->code ?? $plan->type;
        if (strtolower((string) $typeCode) !== 'pcq') {
            return response()->json([
                'success' => false,
                'message' => 'Solo los planes PCQ se pueden cargar como motor PCQ en la RB.',
            ], 422);
        }

        $router = Router::findOrFail($data['router_id']);

        if ($missing = $this->missingRouterCredentials($router)) {
            return response()->json([
                'success' => false,
                'message' => 'El router no tiene credenciales completas: ' . implode(', ', $missing) . '. Genera el script VPN y conéctalo primero.',
            ], 422);
        }

        // Resolve the address/port the CORE must really dial: `router.ip` goes
        // stale whenever the L2TP tunnel reconnects onto another pool address.
        $endpoint = app(\App\Services\MikroTik\RouterEndpointResolver::class)->resolve($router);

        $mikrotik = app(MikroTikSshService::class);
        $result = $mikrotik->syncPcqEngineOnRouter(
            $endpoint['ip'],
            $router->user_rb,
            $router->password_rb,
            $plan->name,
            $plan->is_courtesy ? '0' : $plan->speed_up,
            $plan->is_courtesy ? '0' : $plan->speed_down,
            $plan->is_courtesy ? '0' : $plan->pcq_rate,
            $plan->address_mask,
            $endpoint['api_port'],
            $endpoint['ssh_port']
        );

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'No se pudo sincronizar el motor PCQ.',
                'details' => $result,
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Motor PCQ cargado correctamente en la RB ' . $router->name . ' (' . $router->ip . ')',
            'plan'    => ['id' => $plan->id, 'name' => $plan->name],
            'router'  => ['id' => $router->id, 'name' => $router->name, 'ip' => $router->ip, 'pcq_enabled' => (bool) $router->control_pcq],
            'result'  => $result,
        ]);
    }

    /**
     * Return the list of missing management credentials for a router, or [] if complete.
     */
    private function missingRouterCredentials(Router $router): array
    {
        $missing = [];
        if (!$router->ip) $missing[] = 'IP VPN (¿VPN conectada?)';
        if (!$router->user_rb) $missing[] = 'usuario de gestión';
        if (!$router->password_rb) $missing[] = 'contraseña de gestión';
        return $missing;
    }
}
