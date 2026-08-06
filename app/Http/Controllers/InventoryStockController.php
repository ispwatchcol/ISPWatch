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
        $data = $request->validate($this->rules());

        return response()->json(InventoryStock::create($data), 201);
    }

    public function update(Request $request, InventoryStock $inventoryStock)
    {
        $data = $request->validate($this->rules());

        $inventoryStock->update($data);

        return response()->json($inventoryStock);
    }

    public function destroy(InventoryStock $inventoryStock)
    {
        $inventoryStock->delete();

        return response()->json(['message' => 'Stock eliminado correctamente.']);
    }

    /**
     * is_serialized decide cómo se cuenta el modelo: por serial (una fila por
     * aparato) o por cantidad (un saldo por custodio). Cambiarlo con existencias
     * ya registradas dejaría equipos o saldos sin forma de contarse, así que la
     * pantalla lo bloquea una vez el modelo tiene movimiento.
     */
    private function rules(): array
    {
        return [
            'brand'         => 'nullable',
            'model'         => 'nullable|string|max:255',
            'price'         => 'nullable|numeric|min:0',
            'is_serialized' => 'nullable|boolean',
            'unit'          => 'nullable|string|max:20',
        ];
    }
}
