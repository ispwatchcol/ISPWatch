<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Traits\FixesSequences;
use App\Http\Requests\StorePlanRequest;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    use FixesSequences;
    public function index(Request $request)
    {
        // BelongsToTenant scope auto-filters by tenant query param
        return response()->json([
            'data' => Plan::with('typePlan')->get()
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
}
