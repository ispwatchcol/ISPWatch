<?php

namespace App\Http\Controllers;

use App\Models\InventoryStock;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

        $this->rechazarCambioDeConteoConExistencias($inventoryStock, $data);

        $inventoryStock->update($data);

        return response()->json($inventoryStock);
    }

    /**
     * Impide cambiar la forma de contar un modelo que ya tiene existencias.
     *
     * `is_serialized` decide de dónde salen las cantidades: si es serializado,
     * de las filas de `inventory_device` (una por aparato); si no, de los saldos
     * por custodio en `inventory_balances`. Al cambiarlo, lo que había registrado
     * bajo la forma anterior deja de mirarse — no se borra, se vuelve invisible,
     * que en contabilidad es peor: nadie se entera de que faltan.
     *
     * La pantalla ya lo desactivaba cuando el modelo tiene movimiento, y el
     * docblock de rules() lo daba por hecho. Pero la interfaz no es una
     * restricción: la API estaba abierta y cualquier cliente —o un formulario
     * con estado viejo— podía mandar el cambio igual. P-19 · KAN-77.
     */
    private function rechazarCambioDeConteoConExistencias(InventoryStock $stock, array $data): void
    {
        if (! array_key_exists('is_serialized', $data) || $data['is_serialized'] === null) {
            return;
        }

        $nuevo = (bool) $data['is_serialized'];

        if ($nuevo === (bool) $stock->is_serialized) {
            return;
        }

        $equipos = $stock->devices()->count();
        $saldos  = $stock->balances()->count();

        if ($equipos === 0 && $saldos === 0) {
            return;
        }

        // Se nombra lo que quedaría fuera y se dice cómo dejarlo en cero: un
        // "no se puede" a secas obliga a adivinar qué estorba.
        $estorbo = [];
        if ($equipos > 0) {
            $estorbo[] = "{$equipos} equipo(s) registrado(s) por serial";
        }
        if ($saldos > 0) {
            $estorbo[] = "{$saldos} saldo(s) por cantidad";
        }

        $salida = $nuevo
            ? 'Da de baja o traspasa los saldos hasta dejarlos en cero antes de pasarlo a serial.'
            : 'Da de baja o traspasa los equipos antes de pasarlo a conteo por cantidad.';

        throw ValidationException::withMessages([
            'is_serialized' => 'Este modelo ya tiene ' . implode(' y ', $estorbo)
                . '. Si cambias cómo se cuenta, esas existencias dejan de poder contarse. '
                . $salida,
        ]);
    }

    public function destroy(InventoryStock $inventoryStock)
    {
        $inventoryStock->delete();

        return response()->json(['message' => 'Stock eliminado correctamente.']);
    }

    /**
     * is_serialized decide cómo se cuenta el modelo: por serial (una fila por
     * aparato) o por cantidad (un saldo por custodio). Cambiarlo con existencias
     * ya registradas dejaría equipos o saldos sin forma de contarse; quien lo
     * impide es rechazarCambioDeConteoConExistencias(), no la pantalla.
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
