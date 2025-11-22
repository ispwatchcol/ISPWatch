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
        $devices = InventoryDevice::with(['stock', 'provider', 'branch'])->select(
            'id',
            'serial',
            'mac',
            'stock_id',
            'provider_id',
            'branch_id'
        )->get()->map(function ($device) {
            return [
                'id' => $device->id,
                'serial' => $device->serial,
                'mac' => $device->mac,
                'brand' => $device->stock->brand ?? '-',
                'model' => $device->stock->model ?? '-',
                'provider' => $device->provider->name,
                'branch' => $device->branch->name,
                'created_at' => $device->created_at,
            ];
        });

        return response()->json($devices);
    }

    /**
     * Store a newly created device in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'stock_id' => 'required|integer|exists:inventory_stock,id',
            'provider_id' => 'required|integer|exists:inventory_provider,id',
            'user_id' => 'nullable|integer',
            'branch_id' => 'required|integer|exists:inventory_branche,id',
            'serial' => 'required|string|max:255|unique:inventory_device,serial',
            'mac' => 'required|string|max:255|unique:inventory_device,mac',
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
            'stock_id' => 'sometimes|integer|exists:inventory_stock,id',
            'provider_id' => 'sometimes|integer|exists:inventory_provider,id',
            'user_id' => 'nullable|integer',
            'branch_id' => 'sometimes|integer|exists:inventory_branche,id',
            'serial' => 'sometimes|string|max:255|unique:inventory_device,serial,' . $inventoryDevice->id,
            'mac' => 'sometimes|string|max:255|unique:inventory_device,mac,' . $inventoryDevice->id,
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
