<?php

namespace App\Http\Controllers;

use App\Models\InventoryDevice;
use App\Models\InventoryMovement;
use App\Services\Inventory\InventoryLedger;
use Illuminate\Http\Request;

class InventoryDeviceController extends Controller
{
    public function __construct(private InventoryLedger $ledger)
    {
    }

    /**
     * Display a listing of the devices.
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'status'    => 'nullable|in:stock,assigned,installed,retired',
            'holder_id' => 'nullable|integer',
        ]);

        // Return devices with their nested stock/provider/branch relations so the
        // Inventory list can render brand/model/provider/branch names and ids.
        // Tenant scoping is automatic via BelongsToTenant.
        $devices = InventoryDevice::with([
            'stock:id,brand,model,price,is_serialized,unit',
            'provider:id,name',
            'branch:id,name',
            'holder:id,user_name,user_lastname,name',
            'customer:id,user_name,user_lastname,name',
        ])
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['holder_id'] ?? null, fn ($q, $v) => $q->heldByUser((int) $v))
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (InventoryDevice $device) {
                $row = $device->toArray();
                $row['holder_label'] = $this->holderLabel($device);

                return $row;
            });

        return response()->json($devices);
    }

    /**
     * Store a newly created device in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'stock_id' => 'nullable|integer|exists:inventory_stock,id',
            'provider_id' => 'nullable|integer|exists:inventory_provider,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'branch_id' => 'nullable|integer|exists:inventory_branch,id',
            'serial' => 'nullable|string|max:255|unique:inventory_device,serial',
            'mac' => 'nullable|string|max:255|unique:inventory_device,mac',
        ]);

        // Un equipo con custodio nace ya entregado; sin custodio, en bodega.
        $data['status'] = !empty($data['user_id'])
            ? InventoryDevice::STATUS_ASSIGNED
            : InventoryDevice::STATUS_STOCK;

        $device = InventoryDevice::create($data);

        // El alta es el primer movimiento del kardex: sin él, el historial de un
        // equipo empezaría en su primer traspaso y no se sabría de dónde salió.
        $this->ledger->recordInitialEntry($device, $request->user());

        return response()->json([
            'message' => 'Equipo añadido correctamente. ✅',
            'device' => $device
        ], 201);
    }

    /**
     * Display the specified device.
     */
    public function show(InventoryDevice $inventoryDevice)
    {
        return response()->json($inventoryDevice->load(['stock', 'provider', 'branch', 'holder', 'customer']));
    }

    /**
     * Update the specified device in storage.
     *
     * Cambiar "asignado a" desde el formulario es un traspaso como cualquier
     * otro, así que se delega en InventoryLedger en vez de escribir la columna
     * a mano: de lo contrario el equipo cambiaría de manos sin dejar rastro.
     */
    public function update(Request $request, InventoryDevice $inventoryDevice)
    {
        $data = $request->validate([
            'stock_id' => 'nullable|integer|exists:inventory_stock,id',
            'provider_id' => 'nullable|integer|exists:inventory_provider,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'branch_id' => 'nullable|integer|exists:inventory_branch,id',
            'serial' => 'nullable|string|max:255|unique:inventory_device,serial,' . $inventoryDevice->id,
            'mac' => 'nullable|string|max:255|unique:inventory_device,mac,' . $inventoryDevice->id,
        ]);

        $newUserId   = $data['user_id'] ?? null;
        $newBranchId = $data['branch_id'] ?? null;
        $custodyMoved = $inventoryDevice->status !== InventoryDevice::STATUS_INSTALLED
            && ((int) $newUserId !== (int) $inventoryDevice->user_id
                || (!$newUserId && (int) $newBranchId !== (int) $inventoryDevice->branch_id));

        // Las columnas de custodia las mueve el ledger, no el update directo.
        unset($data['user_id'], $data['branch_id']);
        $inventoryDevice->update($data);

        if ($custodyMoved) {
            $target = $newUserId ?: $newBranchId;

            $this->ledger->transferDevice(
                $inventoryDevice,
                $newUserId ? InventoryMovement::HOLDER_USER : InventoryMovement::HOLDER_BRANCH,
                $target === null ? null : (int) $target,
                $request->user(),
                'Cambio desde la ficha del equipo'
            );
        }

        return response()->json([
            'message' => 'Equipo actualizado correctamente. ✅',
            'device' => $inventoryDevice->fresh()
        ]);
    }

    /**
     * Remove the specified device from storage.
     */
    public function destroy(InventoryDevice $inventoryDevice)
    {
        $inventoryDevice->delete();

        return response()->json([
            'message' => 'Equipo eliminado correctamente. ✅'
        ]);
    }

    /** "Bodega Norte", "Juan Pérez" o "Instalado · María Gómez". */
    private function holderLabel(InventoryDevice $device): string
    {
        $name = fn ($user) => $user
            ? (trim(($user->user_name ?? '') . ' ' . ($user->user_lastname ?? '')) ?: $user->name)
            : null;

        return match ($device->status) {
            InventoryDevice::STATUS_ASSIGNED  => $name($device->holder) ?? 'Asignado',
            InventoryDevice::STATUS_INSTALLED => 'Instalado · ' . ($name($device->customer) ?? 'cliente'),
            InventoryDevice::STATUS_RETIRED   => 'De baja',
            default                           => $device->branch?->name ?: 'Bodega',
        };
    }
}
