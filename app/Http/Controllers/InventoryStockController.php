<?php

namespace App\Http\Controllers;

use App\Models\InventoryStock;
use App\Services\Inventory\InventoryAvailability;
use App\Services\Inventory\InventoryLedger;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * CRUD for inventory stock items. Tenant scoping + tenant_id assignment are
 * automatic via the InventoryStock model's BelongsToTenant trait.
 */
class InventoryStockController extends Controller
{
    /**
     * Catálogo. A quien administra inventario se le agrega `available`: la
     * existencia total del producto (equipos disponibles o suma de saldos).
     * Sin ese dato, un producto «por cantidad» creado sin entrada se veía
     * igual que uno con miles de metros, y la orden decía «sin saldo» sin que
     * nadie entendiera por qué. Quien sólo ve soporte recibe el catálogo de
     * siempre: no se le enseña existencia que no administra.
     */
    public function index(Request $request, InventoryLedger $ledger, InventoryAvailability $availability)
    {
        $stocks = InventoryStock::orderBy('brand')->get();
        $actor  = $request->user();

        if ($actor && $ledger->managesInventory($actor)) {
            $available  = $availability->availableByStock((int) $actor->tenant_id);
            $serialRows = $availability->serialRowsOnConsumables((int) $actor->tenant_id);
            $stocks->each(function (InventoryStock $s) use ($available, $serialRows) {
                $s->setAttribute('available', (float) ($available[$s->id] ?? 0));
                // Filas con serial sobre un material (alta equivocada por el
                // formulario de equipos): no son existencia, pero se avisan.
                $s->setAttribute('serial_rows', $serialRows[$s->id] ?? 0);
            });
        }

        return response()->json($stocks);
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
        $this->rechazarPrecisionMenorQueSaldos($inventoryStock, $data);

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

    /**
     * Bajar los decimales de un producto que ya tiene saldos con fracciones
     * dejaría esos saldos imposibles de mover: 12,5 m no se pueden entregar ni
     * consumir si el producto ahora sólo admite enteros. Se pide dejarlos
     * cuadrados antes de cambiarlo.
     */
    private function rechazarPrecisionMenorQueSaldos(InventoryStock $stock, array $data): void
    {
        if (!isset($data['quantity_decimals']) || $stock->is_serialized) {
            return;
        }

        $nuevo = (int) $data['quantity_decimals'];

        if ($nuevo >= $stock->quantityDecimals()) {
            return;
        }

        $probe = clone $stock;
        $probe->quantity_decimals = $nuevo;

        $chocan = $stock->balances()
            ->where('quantity', '>', 0)
            ->pluck('quantity')
            ->reject(fn ($q) => $probe->acceptsQuantity((float) $q))
            ->count();

        if ($chocan > 0) {
            throw ValidationException::withMessages([
                'quantity_decimals' => "Hay {$chocan} saldo(s) de este producto con más decimales de los que quieres permitir. "
                    . 'Ajústalos o consúmelos antes de cambiar la precisión.',
            ]);
        }
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
            // Decimales de la cantidad: 0 = piezas enteras, 2 = metros con
            // centímetros. Nunca más de 2: saldos y kardex son decimal(12,2).
            'quantity_decimals' => 'nullable|integer|between:0,' . InventoryStock::MAX_QUANTITY_DECIMALS,
        ];
    }
}
