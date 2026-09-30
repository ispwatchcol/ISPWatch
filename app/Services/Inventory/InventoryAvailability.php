<?php

namespace App\Services\Inventory;

use App\Models\InventoryBalance;
use App\Models\InventoryBranch;
use App\Models\InventoryDevice;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\User;

/**
 * Lecturas de disponibilidad del inventario. SÓLO LEE: aquí no se mueve nada,
 * eso sigue siendo cosa exclusiva de InventoryLedger.
 *
 * Dos usos:
 *
 *  - El catálogo de PLANIFICACIÓN de una instalación: qué productos tiene el
 *    tenant y cuánto hay en total. Es informativo —planificar no reserva— y a
 *    quien no administra inventario no se le enseñan precios ni en qué bodega
 *    o en manos de quién está cada cosa.
 *
 *  - Explicar por qué la lista de consumibles de una orden o de un ticket sale
 *    vacía. Antes el bloque simplemente desaparecía y nadie sabía si faltaba
 *    configurar el producto, registrar la entrada o pedir un traspaso.
 *
 * Todas las consultas filtran por `tenant_id` explícitamente además del scope
 * global: el scope depende de que haya un usuario autenticado, y una cifra de
 * disponibilidad que mezcle empresas sería una fuga aunque no se pueda usar.
 */
class InventoryAvailability
{
    public function __construct(private InventoryLedger $ledger)
    {
    }

    /**
     * Catálogo de productos para planificar.
     *
     * @return array{can_view_details: bool, products: array<int, array<string, mixed>>}
     */
    public function planningCatalog(User $actor): array
    {
        $tenantId = (int) $actor->tenant_id;
        $details  = $this->ledger->managesInventory($actor);

        $stocks = InventoryStock::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->orderBy('brand')
            ->orderBy('model')
            ->get();

        $available = $this->availableByStock($tenantId);
        $holders   = $details ? $this->holdersByStock($tenantId) : [];

        $products = $stocks->map(function (InventoryStock $stock) use ($available, $holders, $details) {
            $row = [
                'id'            => $stock->id,
                'label'         => $stock->label(),
                'brand'         => $stock->brand,
                'model'         => $stock->model,
                'unit'          => $stock->unit,
                'is_serialized' => (bool) $stock->is_serialized,
                'available'     => (float) ($available[$stock->id] ?? 0),
            ];

            if ($details) {
                $row['price']   = $stock->price !== null ? (float) $stock->price : null;
                $row['holders'] = $holders[$stock->id] ?? [];
            }

            return $row;
        })->values()->all();

        return [
            'can_view_details' => $details,
            'products'         => $products,
        ];
    }

    /**
     * Cantidad disponible por producto en TODO el tenant.
     *
     * Serializado: equipos en bodega o en poder de alguien (`available()`),
     * sin contar los instalados ni los dados de baja. Consumible: suma de los
     * saldos positivos, tengan el custodio que tengan.
     *
     * @return array<int, float> stock_id => cantidad
     */
    public function availableByStock(int $tenantId): array
    {
        $devices = InventoryDevice::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->available()
            ->selectRaw('stock_id, COUNT(*) as total')
            ->groupBy('stock_id')
            ->pluck('total', 'stock_id');

        $balances = InventoryBalance::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('quantity', '>', 0)
            ->selectRaw('stock_id, SUM(quantity) as total')
            ->groupBy('stock_id')
            ->pluck('total', 'stock_id');

        $out = [];
        foreach ([$devices, $balances] as $rows) {
            foreach ($rows as $stockId => $total) {
                $out[(int) $stockId] = ($out[(int) $stockId] ?? 0) + (float) $total;
            }
        }

        return $out;
    }

    /**
     * Desglose por custodio, sólo para quien administra inventario.
     *
     * @return array<int, array<int, array{type: string, id: int|null, label: string, quantity: float}>>
     */
    private function holdersByStock(int $tenantId): array
    {
        $branches = InventoryBranch::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->pluck('name', 'id');

        $out = [];

        $devices = InventoryDevice::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->available()
            ->selectRaw('stock_id, status, user_id, branch_id, COUNT(*) as total')
            ->groupBy('stock_id', 'status', 'user_id', 'branch_id')
            ->get();

        $userIds = $devices->where('status', InventoryDevice::STATUS_ASSIGNED)->pluck('user_id')->filter();

        $balances = InventoryBalance::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('quantity', '>', 0)
            ->get(['stock_id', 'holder_type', 'holder_id', 'quantity']);

        $userIds = $userIds
            ->merge($balances->where('holder_type', InventoryMovement::HOLDER_USER)->pluck('holder_id'))
            ->unique()
            ->values();

        $users = User::whereIn('id', $userIds)
            ->where('tenant_id', $tenantId)
            ->get(['id', 'user_name', 'user_lastname', 'name'])
            ->keyBy('id');

        $userLabel = function (?int $id) use ($users): string {
            $user = $id ? $users->get($id) : null;
            if (!$user) {
                return 'Persona no encontrada';
            }

            return trim(($user->user_name ?? '') . ' ' . ($user->user_lastname ?? '')) ?: ($user->name ?? "Usuario #{$id}");
        };

        $branchLabel = fn (?int $id) => $id === null
            ? 'Bodega (sin sucursal)'
            : ($branches[$id] ?? "Bodega #{$id}");

        foreach ($devices as $row) {
            $isUser = $row->status === InventoryDevice::STATUS_ASSIGNED;
            $id     = $isUser ? ($row->user_id !== null ? (int) $row->user_id : null) : ($row->branch_id !== null ? (int) $row->branch_id : null);

            $out[(int) $row->stock_id][] = [
                'type'     => $isUser ? InventoryMovement::HOLDER_USER : InventoryMovement::HOLDER_BRANCH,
                'id'       => $id,
                'label'    => $isUser ? $userLabel($id) : $branchLabel($id),
                'quantity' => (float) $row->total,
            ];
        }

        foreach ($balances as $balance) {
            $isUser = $balance->holder_type === InventoryMovement::HOLDER_USER;

            $out[(int) $balance->stock_id][] = [
                'type'     => $balance->holder_type,
                'id'       => (int) $balance->holder_id,
                'label'    => $isUser ? $userLabel((int) $balance->holder_id) : $branchLabel((int) $balance->holder_id),
                'quantity' => (float) $balance->quantity,
            ];
        }

        return $out;
    }

    /**
     * Por qué la lista de consumibles de una orden o un ticket sale vacía.
     *
     * Distingue sólo lo que el sistema PUEDE saber en general. No diagnostica
     * un producto concreto: si un material en particular no aparece, la causa
     * hay que mirarla en su configuración y en sus saldos.
     *
     * @param  int     $accessibleRows  renglones de consumible que el usuario sí puede usar
     * @param  string  $where           «esta orden» / «esta visita», para el mensaje
     * @return array{code: string, message: string|null, inaccessible_products: int}
     */
    public function materialsStatus(int $tenantId, int $accessibleRows, string $where): array
    {
        if ($accessibleRows > 0) {
            return ['code' => 'ok', 'message' => null, 'inaccessible_products' => 0];
        }

        $consumables = InventoryStock::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('is_serialized', false)
            ->pluck('id');

        if ($consumables->isEmpty()) {
            return [
                'code'    => 'no_consumable_products',
                'message' => 'No hay productos configurados «por cantidad» en el inventario. Los consumibles '
                    . '(cable, conectores…) se crean en Inventario → Stock eligiendo «Por cantidad»; un producto '
                    . 'creado «por serial» sólo aparece en la lista de equipos con serial.',
                'inaccessible_products' => 0,
            ];
        }

        $conSaldo = InventoryBalance::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->whereIn('stock_id', $consumables)
            ->where('quantity', '>', 0)
            ->distinct()
            ->count('stock_id');

        if ($conSaldo === 0) {
            return [
                'code'    => 'no_stock',
                'message' => 'Ningún producto por cantidad tiene saldo registrado. La existencia entra por '
                    . 'Inventario → Entregas, como entrada sin origen, a una bodega o a una persona.',
                'inaccessible_products' => 0,
            ];
        }

        return [
            'code'    => 'not_accessible',
            'message' => "Hay saldo de {$conSaldo} producto(s) por cantidad, pero en bodegas o en poder de "
                . "personas de las que no puedes tomar en {$where}. Cada quien toma lo suyo y lo del técnico "
                . 'asignado; las bodegas, sólo con permiso de inventario. Pide que te lo entreguen en '
                . 'Inventario → Entregas.',
            'inaccessible_products' => $conSaldo,
        ];
    }
}
