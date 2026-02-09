<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Traits\FixesSequences;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    use FixesSequences;
    public function index(Request $request)
    {
        $tenantId = $request->query('tenant');

        if (!$tenantId) {
            return response()->json([
                'data' => [],
                'message' => 'No tenant'
            ]);
        }

        return response()->json([
            'data' => Plan::with('typePlan')
                ->byTenant($tenantId)
                ->get()
        ]);
    }

    // 👇 ESTE ES EL MÉTODO QUE TE FALTABA PARA QUE CARGUE EL EDITAR
    public function show(Request $request, $id)
    {
        $tenantId = $request->query('tenant');

        if (!$tenantId) {
            return response()->json(['message' => 'Tenant ID es obligatorio'], 400);
        }

        // Buscamos el plan y verificamos que pertenezca al tenant
        $plan = Plan::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$plan) {
            return response()->json(['message' => 'Plan no encontrado'], 404);
        }

        return response()->json($plan);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'speed_down' => 'required|string',
            'speed_up' => 'required|string',
            'cost_product' => 'required|numeric',
            'commit' => 'nullable|string',
            'type' => 'required|string',
            'type_plan_id' => 'required|exists:type_plans,id',
            'tenant_id' => 'required|integer',
            // Campos específicos por tipo de plan
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

        $plan = $this->createWithSequenceFix(Plan::class, $validated);

        return response()->json([
            'success' => true,
            'data' => $plan->load('typePlan')
        ], 201);
    }

    // 👇 ESTE ES EL MÉTODO QUE TE FALTABA PARA GUARDAR EL EDITAR
    public function update(Request $request, $id)
    {
        $tenantId = $request->query('tenant');

        $plan = Plan::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$plan) {
            return response()->json(['message' => 'Plan no encontrado'], 404);
        }

        // Validamos solo los campos que vienen (sometimes)
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'speed_down' => 'sometimes|string',
            'speed_up' => 'sometimes|string',
            'cost_product' => 'sometimes|numeric',
            'commit' => 'nullable|string',
            'type' => 'sometimes|string',
            'type_plan_id' => 'sometimes|exists:type_plans,id',
            // Campos específicos por tipo de plan
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
        // Es buena práctica validar el tenant también al eliminar
        $tenantId = $request->query('tenant');

        $plan = Plan::where('id', $id);

        if ($tenantId) {
            $plan->where('tenant_id', $tenantId);
        }

        $plan = $plan->firstOrFail();
        $plan->delete();

        return response()->json([
            'message' => 'Plan eliminado'
        ]);
    }
}
