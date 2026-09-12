<?php

namespace App\Http\Controllers;

use App\Models\InstallationEquipment;
use App\Models\InventoryDevice;
use App\Models\InventoryMovement;
use App\Services\Inventory\InventoryExpenseRecorder;
use App\Services\Inventory\InventoryLedger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryDeviceController extends Controller
{
    public function __construct(
        private InventoryLedger $ledger,
        private InventoryExpenseRecorder $expenseRecorder,
    ) {
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
        $data = $request->validate($this->rules($request), $this->messages());

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
    public function show(InventoryDevice $inventory)
    {
        return response()->json($inventory->load(['stock', 'provider', 'branch', 'holder', 'customer']));
    }

    /**
     * Update the specified device in storage.
     *
     * Cambiar "asignado a" desde el formulario es un traspaso como cualquier
     * otro, así que se delega en InventoryLedger en vez de escribir la columna
     * a mano: de lo contrario el equipo cambiaría de manos sin dejar rastro.
     */
    public function update(Request $request, InventoryDevice $inventory)
    {
        $data = $request->validate($this->rules($request, $inventory), $this->messages());

        $newUserId   = $data['user_id'] ?? null;
        $newBranchId = $data['branch_id'] ?? null;
        $custodyMoved = $inventory->status !== InventoryDevice::STATUS_INSTALLED
            && ((int) $newUserId !== (int) $inventory->user_id
                || (!$newUserId && (int) $newBranchId !== (int) $inventory->branch_id));

        // Las columnas de custodia las mueve el ledger, no el update directo.
        unset($data['user_id'], $data['branch_id']);
        $inventory->update($data);

        if ($custodyMoved) {
            $target = $newUserId ?: $newBranchId;

            $this->ledger->transferDevice(
                $inventory,
                $newUserId ? InventoryMovement::HOLDER_USER : InventoryMovement::HOLDER_BRANCH,
                $target === null ? null : (int) $target,
                $request->user(),
                'Cambio desde la ficha del equipo'
            );
        }

        return response()->json([
            'message' => 'Equipo actualizado correctamente. ✅',
            'device' => $inventory->fresh()
        ]);
    }

    /**
     * Remove the specified device from storage.
     *
     * Un equipo que está en casa de un cliente no se borra: installation_equipment
     * apunta a él con SET NULL, así que el DELETE no falla — deja la línea de la
     * instalación sin equipo y nadie vuelve a saber qué router quedó instalado.
     * Para sacarlo del inventario está la baja (/inventory/{id}/retire), que sí
     * queda escrita en el kardex.
     */
    public function destroy(InventoryDevice $inventory)
    {
        $installed = $inventory->status === InventoryDevice::STATUS_INSTALLED
            || InstallationEquipment::where('device_id', $inventory->id)->exists();

        if ($installed) {
            throw ValidationException::withMessages([
                'device' => 'Este equipo está instalado en casa de un cliente y no se puede eliminar. '
                    . 'Devuélvelo a bodega o dale de baja para sacarlo del inventario.',
            ]);
        }

        // Si la entrada de este equipo generó un gasto automático (KAN-91), hay
        // que anularlo: borrar el equipo sin tocar el gasto deja el balance
        // cargando una compra que ya no existe en el inventario.
        //
        // Se anula, no se borra. Es precedente firme del proyecto: destruir un
        // registro de dinero deja el balance cuadrando por arte de magia y sin
        // rastro de qué pasó.
        $entradas = InventoryMovement::withoutTenantScope()
            ->where('device_id', $inventory->id)
            ->where('type', InventoryMovement::TYPE_ENTRADA)
            ->pluck('id');

        foreach ($entradas as $movimientoId) {
            $this->expenseRecorder->voidForMovement($movimientoId);
        }

        $inventory->delete();

        return response()->json([
            'message' => 'Equipo eliminado correctamente. ✅'
        ]);
    }

    /**
     * Reglas de alta y edición.
     *
     * El serial y la MAC son únicos DENTRO del tenant, no en toda la base: dos
     * empresas distintas pueden tener equipos con el mismo serial y la carga
     * masiva (InventoryImport) ya deduplica así. Sin el where, un serial ajeno
     * bloqueaba el alta con un "ya está en uso" que el cliente no podía explicar
     * porque ese equipo no aparecía por ningún lado en su inventario.
     */
    private function rules(Request $request, ?InventoryDevice $device = null): array
    {
        $tenantId = $request->user()?->tenant_id;

        $uniquePerTenant = fn (string $column) => Rule::unique('inventory_device', $column)
            ->where(fn ($query) => $query->where('tenant_id', $tenantId))
            ->ignore($device?->id);

        return [
            'stock_id'    => 'nullable|integer|exists:inventory_stock,id',
            'provider_id' => 'nullable|integer|exists:inventory_provider,id',
            'user_id'     => 'nullable|integer|exists:users,id',
            'branch_id'   => 'nullable|integer|exists:inventory_branch,id',
            'serial'      => ['nullable', 'string', 'max:255', $uniquePerTenant('serial')],
            'mac'         => ['nullable', 'string', 'max:255', $uniquePerTenant('mac')],
        ];
    }

    /** En español y diciendo QUÉ campo choca: "status code 422" no le sirve a nadie. */
    private function messages(): array
    {
        return [
            'serial.unique' => 'Ya tienes otro equipo registrado con este serial.',
            'mac.unique'    => 'Ya tienes otro equipo registrado con esta MAC.',
            'serial.max'    => 'El serial no puede superar los 255 caracteres.',
            'mac.max'       => 'La MAC no puede superar los 255 caracteres.',
        ];
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
