<?php

namespace App\Http\Controllers;

use App\Models\Sectorial;
use App\Models\SectorialHistory;
use Illuminate\Http\Request;

class SectorialHistoryController extends Controller
{
    public function index(Request $request, $sectorialId)
    {
        $query = Sectorial::where('id', $sectorialId);
        if ($tenantId = $request->user()?->tenant_id) {
            $query->where('tenant_id', $tenantId);
        }
        $sectorial = $query->firstOrFail();

        $history = SectorialHistory::with('user:id,user_name,user_lastname')
            ->where('sectorial_id', $sectorial->id)
            ->orderBy('created_at', 'desc')
            ->limit(200)
            ->get();

        return response()->json($history);
    }

    public function tickets(Request $request, $sectorialId)
    {
        $query = Sectorial::where('id', $sectorialId);
        if ($tenantId = $request->user()?->tenant_id) {
            $query->where('tenant_id', $tenantId);
        }
        $sectorial = $query->firstOrFail();

        $tickets = $sectorial->tickets()
            ->with(['user:id,user_name,user_lastname,email', 'staff:id,user_name,user_lastname'])
            ->get();

        return response()->json($tickets);
    }
}
