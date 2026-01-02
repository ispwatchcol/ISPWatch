<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index(Request $request)
    {
        // tenant obligatorio
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

    public function store(Request $request)
    {
        // Validación
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'speed_down' => 'required|string',
            'speed_up' => 'required|string',
            'cost_product' => 'required|numeric',
            'commit' => 'nullable|string',
            'type' => 'required|string',
            'type_plan_id' => 'required|exists:type_plans,id',
            'tenant_id' => 'required|integer',
        ]);

        $plan = Plan::create($validated);

        return response()->json([
            'success' => true,
            'data' => $plan->load('typePlan')
        ], 201);
    }

    public function destroy($id)
    {
        Plan::findOrFail($id)->delete();

        return response()->json([
            'message' => 'Plan eliminado'
        ]);
    }
}
