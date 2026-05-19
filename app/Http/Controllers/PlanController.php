<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\MikroTikSshService;
use App\Models\Plan;
use App\Traits\FixesSequences;
use App\Http\Requests\StorePlanRequest;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    use FixesSequences;
    public function index(Request $request)
    {
        return response()->json([
            'data' => Plan::with('typePlan')
                ->withCount(['userServices' => fn($q) => $q->whereHas('user', fn($u) => $u->where('status', true)->where('role_id', 3))->whereIn('status', ['active', 'gratis'])])
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
        $plan->delete();

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

        $mikrotik = app(MikroTikSshService::class);
        $result = $mikrotik->syncPppoeProfileOnRouter(
            $router->ip,
            $router->user_rb,
            $router->password_rb,
            $plan->name,
            $plan->speed_up,
            $plan->speed_down,
            $plan->local_address,
            $plan->pppoe_pool,
            $router->puerto_api ?? 8728
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
}
