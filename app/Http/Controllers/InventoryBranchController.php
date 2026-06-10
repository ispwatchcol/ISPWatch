<?php

namespace App\Http\Controllers;

use App\Models\InventoryBranch;
use Illuminate\Http\Request;

/**
 * CRUD for inventory branches. Tenant scoping is automatic via BelongsToTenant.
 */
class InventoryBranchController extends Controller
{
    private array $rules = [
        'name' => 'required|string|max:255',
        'dir' => 'nullable|string|max:255',
        'numero' => 'nullable|integer',
    ];

    public function index()
    {
        return response()->json(InventoryBranch::orderBy('name')->get());
    }

    public function store(Request $request)
    {
        return response()->json(InventoryBranch::create($request->validate($this->rules)), 201);
    }

    public function update(Request $request, InventoryBranch $inventoryBranch)
    {
        $inventoryBranch->update($request->validate($this->rules));

        return response()->json($inventoryBranch);
    }

    public function destroy(InventoryBranch $inventoryBranch)
    {
        $inventoryBranch->delete();

        return response()->json(['message' => 'Sucursal eliminada correctamente.']);
    }
}
