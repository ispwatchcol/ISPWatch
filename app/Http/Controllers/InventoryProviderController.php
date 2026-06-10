<?php

namespace App\Http\Controllers;

use App\Models\InventoryProvider;
use Illuminate\Http\Request;

/**
 * CRUD for inventory providers. Tenant scoping is automatic via BelongsToTenant.
 */
class InventoryProviderController extends Controller
{
    private array $rules = [
        'name' => 'required|string|max:255',
        'email' => 'nullable|string|max:255',
        'phone' => 'nullable|string|max:255',
        'addr' => 'nullable|string|max:255',
        'city' => 'nullable|string|max:255',
        'identification' => 'nullable|string|max:255',
        'advisor_name' => 'nullable|string|max:255',
        'advisor_phone' => 'nullable|string|max:255',
        'advisor_email' => 'nullable|string|max:255',
        'advisor_position' => 'nullable|string|max:255',
    ];

    public function index()
    {
        return response()->json(InventoryProvider::orderBy('name')->get());
    }

    public function store(Request $request)
    {
        return response()->json(InventoryProvider::create($request->validate($this->rules)), 201);
    }

    public function update(Request $request, InventoryProvider $inventoryProvider)
    {
        $inventoryProvider->update($request->validate($this->rules));

        return response()->json($inventoryProvider);
    }

    public function destroy(InventoryProvider $inventoryProvider)
    {
        $inventoryProvider->delete();

        return response()->json(['message' => 'Proveedor eliminado correctamente.']);
    }
}
