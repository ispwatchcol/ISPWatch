<?php

namespace App\Http\Controllers;

use App\Models\InventoryStock;
use App\Models\InventoryUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo de unidades de medida del inventario (P-92).
 *
 * Las básicas (unidad, metro, rollo, caja, paquete) son del sistema y sólo se
 * listan. Cada empresa agrega las suyas («bolsa x100», «kilo»…), y el
 * formulario de producto sólo acepta unidades de esta lista.
 */
class InventoryUnitController extends Controller
{
    public function index()
    {
        return response()->json(InventoryUnit::catalog());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $this->rejectDuplicate($data['name']);

        $unit = InventoryUnit::create($data);

        return response()->json($unit, 201);
    }

    /**
     * Renombrar una unidad renombra también la de los productos que la usan:
     * es la forma de unificar «mts» y «metro» sin editar producto por producto.
     * Lo ya planificado en órdenes conserva la unidad con que se planificó.
     */
    public function update(Request $request, InventoryUnit $inventoryUnit)
    {
        $data = $this->validated($request);
        $renamed = mb_strtolower($data['name']) !== mb_strtolower($inventoryUnit->name);

        if ($renamed) {
            $this->rejectDuplicate($data['name'], $inventoryUnit->id);
        }

        $products = DB::transaction(function () use ($inventoryUnit, $data, $renamed) {
            $count = 0;

            if ($renamed || $data['name'] !== $inventoryUnit->name) {
                $count = $inventoryUnit->productsUsing()->update(['unit' => $data['name']]);
            }

            $inventoryUnit->update($data);

            return $count;
        });

        return response()->json([
            'unit'             => $inventoryUnit->fresh(),
            'products_renamed' => $products,
        ]);
    }

    /** Una unidad en uso no se borra: el producto quedaría con una unidad que no existe. */
    public function destroy(InventoryUnit $inventoryUnit)
    {
        $enUso = $inventoryUnit->productsUsing()->count();

        if ($enUso > 0) {
            return response()->json([
                'message'        => "La unidad «{$inventoryUnit->name}» la usan {$enUso} producto(s). "
                    . 'Cámbiales la unidad primero, o renómbrala para unificarla con otra.',
                'products_count' => $enUso,
            ], 409);
        }

        $inventoryUnit->delete();

        return response()->json(['message' => 'Unidad eliminada.']);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name'     => 'required|string|max:20',
            'decimals' => 'nullable|integer|between:0,' . InventoryStock::MAX_QUANTITY_DECIMALS,
        ]);

        $data['name']     = trim(preg_replace('/\s+/u', ' ', $data['name']));
        $data['decimals'] = (int) ($data['decimals'] ?? 0);

        if ($data['name'] === '') {
            throw ValidationException::withMessages(['name' => 'Escribe el nombre de la unidad.']);
        }

        return $data;
    }

    private function rejectDuplicate(string $name, ?int $exceptId = null): void
    {
        if (InventoryUnit::isBuiltIn($name)) {
            throw ValidationException::withMessages([
                'name' => "«{$name}» ya es una unidad básica del sistema.",
            ]);
        }

        $existe = InventoryUnit::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages([
                'name' => "Ya existe la unidad «{$name}».",
            ]);
        }
    }
}
