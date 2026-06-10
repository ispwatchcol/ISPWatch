<?php

namespace App\Http\Controllers;

use App\Models\InventoryStock;
use Illuminate\Http\Request;

/**
 * CRUD for inventory stock items. Tenant scoping + tenant_id assignment are
 * automatic via the InventoryStock model's BelongsToTenant trait.
 */
class InventoryStockController extends Controller
{
    public function index()
    {
        return response()->json(InventoryStock::orderBy('brand')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'brand' => 'nullable',
            'model' => 'nullable|string|max:255',
            'price' => 'nullable|numeric|min:0',
        ]);

        return response()->json(InventoryStock::create($data), 201);
    }

    public function update(Request $request, InventoryStock $inventoryStock)
    {
        $data = $request->validate([
            'brand' => 'nullable',
            'model' => 'nullable|string|max:255',
            'price' => 'nullable|numeric|min:0',
        ]);

        $inventoryStock->update($data);

        return response()->json($inventoryStock);
    }

    public function destroy(InventoryStock $inventoryStock)
    {
        $inventoryStock->delete();

        return response()->json(['message' => 'Stock eliminado correctamente.']);
    }
}
