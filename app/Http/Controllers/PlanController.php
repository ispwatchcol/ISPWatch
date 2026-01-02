<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => Plan::with('typePlan')->get()
        ]);
    }

    public function destroy($id)
    {
        Plan::findOrFail($id)->delete();

        return response()->json([
            'message' => 'Plan eliminado'
        ]);
    }
}
