<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\InventoryDevice;

class InventoryDeviceController extends Controller
{
    /**
     * Display a listing of the devices.
     */
    public function index()
    {
        // Return devices with their nested stock/provider/branch relations so the
        // Inventory list can render brand/model/provider/branch names and ids.
        // Tenant scoping is automatic via BelongsToTenant.
        $devices = InventoryDevice::with([
            'stock:id,brand,model,price',
            'provider:id,name',
            'branch:id,name',
        ])->orderBy('created_at', 'desc')->get();

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

        $device = InventoryDevice::create($data);

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
        return response()->json($inventoryDevice->load(['stock', 'provider', 'branch']));
    }

    /**
     * Update the specified device in storage.
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

        $inventoryDevice->update($data);

        return response()->json([
            'message' => 'Equipo actualizado correctamente. ✅',
            'device' => $inventoryDevice
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
}
