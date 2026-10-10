<?php

namespace App\Services\Inventory;

use App\Models\Expense;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Convierte una ENTRADA de inventario en un gasto, si la empresa lo activó.
 *
 * Viene apagado por empresa y es deliberado: muchos ISP ya registran a mano la
 * factura del proveedor. Con esto encendido esa compra se contaría dos veces y
 * el balance mentiría hacia abajo — el peor sentido en el que puede mentir, ya
 * que nadie reclama por tener menos gastos de los reales.
 *
 * El importe sale de `inventory_stock.price`, que es el precio del CATÁLOGO. En
 * `expenses.amount` queda congelado: si mañana el catálogo cambia de precio, los
 * gastos ya creados no se mueven. Es una foto del momento, no un cálculo — el
 * mismo criterio que ya usa `installation_equipment.unit_price`.
 *
 * La idempotencia no la cuida este código sino el índice ÚNICO de
 * `expenses.inventory_movement_id`: acá se consulta antes de insertar, pero lo
 * que de verdad impide el gasto duplicado en una carrera es la base.
 *
 * **Por qué la API es por lotes.** La carga masiva entra por acá con cientos de
 * movimientos de golpe, y este proyecto ya se quemó con eso: una importación que
 * consultaba por fila tumbaba el gateway con 200 filas. Por eso el método que
 * manda es `forMovements()` —tres consultas fijas, no tres por equipo— y el de
 * un solo movimiento delega en él.
 */
class InventoryExpenseRecorder
{
    /**
     * Ajustes del tenant, resueltos una sola vez por instancia.
     *
     * Importa más de lo que parece: `record()` del ledger pasa por acá en CADA
     * movimiento, y con el interruptor apagado —que es el caso de todos hoy—
     * consultar el tenant cada vez sería una consulta de más por movimiento, a
     * cambio de nada. Se guarda `false` explícito para distinguir "ya lo busqué
     * y no hay" de "todavía no lo busqué".
     *
     * @var Tenant|false|null
     */
    private $tenantCache = null;

    /**
     * Registra el gasto de un movimiento suelto. Lo usa el ledger.
     *
     * Devuelve `null` cuando no había nada que hacer o el gasto quedó creado, y
     * un aviso cuando la empresa SÍ quería el gasto pero no se pudo crear. Ese
     * aviso hay que mostrarlo: callarlo deja el balance descuadrado sin que
     * nadie se entere, que es justo lo que se quiere evitar.
     */
    public function forMovement(InventoryMovement $movement): ?string
    {
        return $this->forMovements(collect([$movement]))[0] ?? null;
    }

    /**
     * Registra de una sola vez los gastos de varias entradas.
     *
     * @param  Collection<int, InventoryMovement|object>  $movements  filas con id, tenant_id, stock_id, type, quantity, created_by, created_at
     * @return array<int, string>  avisos de los que no se pudieron crear
     */
    public function forMovements(Collection $movements): array
    {
        $entradas = $movements->filter(
            fn ($m) => ($m->type ?? null) === InventoryMovement::TYPE_ENTRADA
        );

        if ($entradas->isEmpty()) {
            return [];
        }

        // Todos los movimientos de una misma operación son del mismo tenant.
        $tenant = $this->tenant((int) $entradas->first()->tenant_id);

        if (! $tenant || ! $tenant->inventory_entry_creates_expense) {
            return [];
        }

        // Consulta 1: los gastos que YA existen para estos movimientos.
        $yaCobrados = Expense::withoutTenantScope()
            ->whereIn('inventory_movement_id', $entradas->pluck('id'))
            ->pluck('inventory_movement_id')
            ->flip();

        $pendientes = $entradas->reject(fn ($m) => $yaCobrados->has($m->id));

        if ($pendientes->isEmpty()) {
            return [];
        }

        // Consulta 2: los precios del catálogo, en bloque.
        $stocks = InventoryStock::withoutTenantScope()
            ->whereIn('id', $pendientes->pluck('stock_id')->unique())
            ->get()
            ->keyBy('id');

        $filas   = [];
        $avisos  = [];
        $sinPrecio = [];

        foreach ($pendientes as $movimiento) {
            $stock  = $stocks->get($movimiento->stock_id);
            $precio = $stock?->price === null ? null : (float) $stock->price;

            if (! $precio) {
                // Sin precio de catálogo no hay importe que registrar. No se crea
                // un gasto en 0: un cero se lee como "salió gratis", no como
                // "falta el dato", y ensuciaría el listado de gastos.
                //
                // El aviso se agrupa por modelo: en una carga masiva de 200
                // equipos del mismo modelo, 200 líneas idénticas no informan más
                // que una — sólo esconden las demás.
                $nombre = $stock?->label() ?? 'El material';
                $sinPrecio[$nombre] = ($sinPrecio[$nombre] ?? 0) + 1;
                continue;
            }

            $cantidad = (float) $movimiento->quantity;
            $fecha    = $movimiento->created_at ?? now();

            $filas[] = [
                'tenant_id'             => $movimiento->tenant_id,
                'expense_category_id'   => $tenant->inventory_expense_category_id,
                'created_by'            => $movimiento->created_by,
                'inventory_movement_id' => $movimiento->id,
                'expense_date'          => is_string($fecha) ? substr($fecha, 0, 10) : $fecha->toDateString(),
                'amount'                => round($precio * $cantidad, 2),
                'description'           => $this->descripcion($stock, $cantidad),
                'status'                => Expense::STATUS_ACTIVE,
                'created_at'            => now(),
                'updated_at'            => now(),
            ];
        }

        foreach ($sinPrecio as $nombre => $veces) {
            $avisos[] = $veces === 1
                ? "{$nombre} no tiene precio en el catálogo: no se creó el gasto de esta entrada. "
                    . 'Ponle precio al modelo y registra el gasto a mano.'
                : "{$nombre} no tiene precio en el catálogo: no se crearon los gastos de {$veces} entradas. "
                    . 'Ponle precio al modelo y registra el gasto a mano.';
        }

        // Consulta 3: todos los gastos de un golpe.
        if ($filas !== []) {
            DB::table('expenses')->insert($filas);
        }

        return $avisos;
    }

    /**
     * Anula el gasto de un movimiento que se deshizo.
     *
     * No se borra: en este proyecto los registros de dinero se anulan, nunca se
     * destruyen — borrarlos deja el balance cuadrando por arte de magia y sin
     * rastro de qué pasó.
     */
    public function voidForMovement(int $movementId): void
    {
        Expense::withoutTenantScope()
            ->where('inventory_movement_id', $movementId)
            ->where('status', Expense::STATUS_ACTIVE)
            ->update(['status' => Expense::STATUS_VOID]);
    }

    /** El tenant, buscado una sola vez por instancia. */
    private function tenant(int $tenantId): ?Tenant
    {
        if ($this->tenantCache === null || ($this->tenantCache && $this->tenantCache->id !== $tenantId)) {
            $this->tenantCache = Tenant::find($tenantId) ?: false;
        }

        return $this->tenantCache ?: null;
    }

    private function descripcion(InventoryStock $stock, float $cantidad): string
    {
        $cant = floor($cantidad) == $cantidad
            ? (string) (int) $cantidad
            : rtrim(rtrim(number_format($cantidad, 2, ',', ''), '0'), ',');

        return mb_substr("Entrada de inventario: {$stock->label()} × {$cant}", 0, 255);
    }
}
