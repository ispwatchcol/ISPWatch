<?php

namespace App\Http\Controllers;

use App\Models\InventoryBalance;
use App\Models\InventoryBranch;
use App\Models\InventoryDevice;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\User;
use App\Services\Inventory\InventoryLedger;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Entregas, devoluciones y kardex del inventario.
 *
 * Todas estas rutas exigen permiso de inventario (ver routes/api.php), y por eso
 * aquí SÍ se puede mover un equipo que tiene otro técnico: recoger lo que Juan
 * no usó es justamente el trabajo de esta pantalla. Lo que no se permite en
 * ningún caso es consumir existencias ajenas sin dejar el traspaso escrito —de
 * eso se encarga InventoryLedger, que es el único que toca saldos.
 */
class InventoryMovementController extends Controller
{
    public function __construct(private InventoryLedger $ledger)
    {
    }

    /** Kardex: el historial, del más reciente al más antiguo. */
    public function index(Request $request)
    {
        $data = $request->validate([
            'device_id'   => 'nullable|integer',
            'stock_id'    => 'nullable|integer',
            'holder_type' => 'nullable|in:branch,user,customer,supplier,scrap',
            'holder_id'   => 'nullable|integer',
            'type'        => 'nullable|in:entrada,traspaso,instalacion,devolucion,baja',
            'from'        => 'nullable|date',
            'to'          => 'nullable|date',
            'per_page'    => 'nullable|integer|min:1|max:200',
        ]);

        $movements = InventoryMovement::with([
                'stock:id,brand,model,unit',
                'device:id,serial,mac',
                'creator:id,user_name,user_lastname,name',
            ])
            ->when($data['device_id'] ?? null, fn ($q, $v) => $q->where('device_id', $v))
            ->when($data['stock_id'] ?? null, fn ($q, $v) => $q->where('stock_id', $v))
            ->when($data['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            // Un custodio aparece tanto si el movimiento salió de él como si
            // llegó a él: "todo lo de Juan" son sus entradas y sus salidas.
            ->when(($data['holder_type'] ?? null) !== null, function ($q) use ($data) {
                $type = $data['holder_type'];
                $id   = $data['holder_id'] ?? null;

                $q->where(function ($sub) use ($type, $id) {
                    $sub->where(fn ($s) => $s->where('from_type', $type)->where('from_id', $id))
                        ->orWhere(fn ($s) => $s->where('to_type', $type)->where('to_id', $id));
                });
            })
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 50);

        $labels = $this->holderLabels($movements->getCollection());

        $movements->getCollection()->transform(fn (InventoryMovement $m) => [
            'id'            => $m->id,
            'type'          => $m->type,
            'quantity'      => (float) $m->quantity,
            'device_id'     => $m->device_id,
            'serial'        => $m->device?->serial ?? $m->device_serial,
            'mac'           => $m->device?->mac,
            'stock_id'      => $m->stock_id,
            'item'          => trim(($m->stock?->brand ?? '') . ' ' . ($m->stock?->model ?? '')) ?: 'Equipo',
            'unit'          => $m->stock?->unit,
            'from'          => $this->holderLabel($labels, $m->from_type, $m->from_id),
            'to'            => $this->holderLabel($labels, $m->to_type, $m->to_id),
            'installation_id' => $m->installation_id,
            'notes'         => $m->notes,
            'created_at'    => $m->created_at,
            'created_by'    => $m->creator
                ? (trim(($m->creator->user_name ?? '') . ' ' . ($m->creator->user_lastname ?? '')) ?: $m->creator->name)
                : null,
        ]);

        return response()->json($movements);
    }

    /**
     * Qué tiene encima un custodio: equipos serializados y saldos de material.
     * Es la pantalla "¿qué carga Juan?" y la base de la entrega.
     */
    public function holdings(Request $request)
    {
        $data = $request->validate([
            'holder_type' => 'required|in:branch,user',
            'holder_id'   => 'nullable|integer',
        ]);

        $type = $data['holder_type'];
        $id   = $data['holder_id'] ?? null;

        $devices = InventoryDevice::with(['stock:id,brand,model,price,unit'])
            ->when(
                $type === InventoryMovement::HOLDER_USER,
                fn ($q) => $q->heldByUser((int) $id),
                function ($q) use ($id) {
                    $q->where('status', InventoryDevice::STATUS_STOCK);
                    $id === null ? $q->whereNull('branch_id') : $q->where('branch_id', (int) $id);
                }
            )
            ->orderBy('id')
            ->get()
            ->map(fn (InventoryDevice $d) => [
                'id'     => $d->id,
                'serial' => $d->serial,
                'mac'    => $d->mac,
                'item'   => $d->stock?->label() ?? 'Equipo',
                'price'  => $d->stock?->price,
            ]);

        $materials = $id === null
            ? collect()
            : InventoryBalance::with(['stock:id,brand,model,price,unit'])
                ->heldBy($type, (int) $id)
                ->where('quantity', '>', 0)
                ->get()
                ->map(fn (InventoryBalance $b) => [
                    'stock_id' => $b->stock_id,
                    'item'     => $b->stock?->label() ?? 'Material',
                    'unit'     => $b->stock?->unit,
                    'quantity' => (float) $b->quantity,
                ]);

        return response()->json([
            'devices'   => $devices->values(),
            'materials' => $materials->values(),
        ]);
    }

    /**
     * Entrega/traspaso: mueve equipos y/o material a un custodio.
     * Sin origen en un material, el movimiento es una ENTRADA (compra o ajuste).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'to_type'                => 'required|in:branch,user',
            'to_id'                  => 'required|integer',
            'device_ids'             => 'nullable|array',
            'device_ids.*'           => 'integer',
            'materials'              => 'nullable|array',
            'materials.*.stock_id'   => 'required|integer',
            'materials.*.quantity'   => 'required|numeric|min:0.01',
            'materials.*.source_type' => 'nullable|in:branch,user',
            'materials.*.source_id'  => 'nullable|integer',
            'notes'                  => 'nullable|string|max:255',
        ]);

        $this->assertHolderExists($data['to_type'], (int) $data['to_id']);

        if (empty($data['device_ids']) && empty($data['materials'])) {
            throw ValidationException::withMessages([
                'device_ids' => 'Selecciona al menos un equipo o material para entregar.',
            ]);
        }

        $actor = $request->user();
        $moved = 0;

        foreach ($data['device_ids'] ?? [] as $deviceId) {
            $device = InventoryDevice::with('stock')->findOrFail($deviceId);
            $this->ledger->transferDevice(
                $device,
                $data['to_type'],
                (int) $data['to_id'],
                $actor,
                $data['notes'] ?? null
            );
            $moved++;
        }

        foreach ($data['materials'] ?? [] as $material) {
            $stock = InventoryStock::findOrFail($material['stock_id']);

            if ($stock->is_serialized) {
                throw ValidationException::withMessages([
                    'materials' => "{$stock->label()} se maneja por serial: entrégalo eligiendo sus equipos, no por cantidad.",
                ]);
            }

            $sourceType = $material['source_type'] ?? null;
            $sourceId   = $material['source_id'] ?? null;

            if ($sourceType !== null) {
                $this->assertHolderExists($sourceType, (int) $sourceId);
            }

            $this->ledger->transferQuantity(
                $stock,
                $sourceType,
                $sourceId === null ? null : (int) $sourceId,
                $data['to_type'],
                (int) $data['to_id'],
                (float) $material['quantity'],
                $actor,
                $data['notes'] ?? null
            );
            $moved++;
        }

        return response()->json([
            'message' => $moved === 1
                ? 'Movimiento registrado.'
                : "{$moved} movimientos registrados.",
        ], 201);
    }

    /** Baja de un equipo (dañado, perdido o devuelto al proveedor). */
    public function retire(Request $request, $deviceId)
    {
        $data = $request->validate([
            'notes' => 'nullable|string|max:255',
        ]);

        $device = InventoryDevice::with('stock')->findOrFail($deviceId);

        $this->ledger->retireDevice($device, $request->user(), $data['notes'] ?? null);

        return response()->json(['message' => 'Equipo dado de baja.']);
    }

    /**
     * El destino tiene que existir DENTRO del tenant. Sin esta comprobación,
     * un id de otra empresa se guardaría como custodio y las existencias
     * saldrían del inventario sin destino real.
     */
    private function assertHolderExists(string $type, int $id): void
    {
        $exists = $type === InventoryMovement::HOLDER_USER
            ? User::where('id', $id)->exists()
            : InventoryBranch::where('id', $id)->exists();

        if (!$exists) {
            throw ValidationException::withMessages([
                'to_id' => 'El destino seleccionado no existe en esta empresa.',
            ]);
        }
    }

    /**
     * Nombres de los custodios que aparecen EN ESTA PÁGINA del kardex, en dos
     * consultas. Cargar todos los usuarios del tenant sería traer también los
     * miles de clientes para pintar cincuenta filas.
     */
    private function holderLabels($movements): array
    {
        $ids = ['branch' => [], 'user' => []];

        foreach ($movements as $movement) {
            foreach ([[$movement->from_type, $movement->from_id], [$movement->to_type, $movement->to_id]] as [$type, $id]) {
                if ($id === null) {
                    continue;
                }
                if ($type === InventoryMovement::HOLDER_BRANCH) {
                    $ids['branch'][$id] = true;
                } elseif (in_array($type, [InventoryMovement::HOLDER_USER, InventoryMovement::HOLDER_CUSTOMER], true)) {
                    $ids['user'][$id] = true;
                }
            }
        }

        $branches = empty($ids['branch'])
            ? []
            : InventoryBranch::whereIn('id', array_keys($ids['branch']))->pluck('name', 'id')->toArray();

        $users = empty($ids['user'])
            ? []
            : User::whereIn('id', array_keys($ids['user']))
                ->get(['id', 'user_name', 'user_lastname', 'name'])
                ->mapWithKeys(fn ($u) => [
                    $u->id => trim(($u->user_name ?? '') . ' ' . ($u->user_lastname ?? '')) ?: $u->name,
                ])
                ->toArray();

        return ['branch' => $branches, 'user' => $users, 'customer' => $users];
    }

    private function holderLabel(array $labels, ?string $type, ?int $id): string
    {
        return match ($type) {
            InventoryMovement::HOLDER_SUPPLIER => 'Proveedor / compra',
            InventoryMovement::HOLDER_SCRAP    => 'Baja',
            InventoryMovement::HOLDER_BRANCH   => $labels['branch'][$id] ?? 'Bodega',
            InventoryMovement::HOLDER_USER     => $labels['user'][$id] ?? 'Usuario',
            InventoryMovement::HOLDER_CUSTOMER => $labels['customer'][$id] ?? 'Cliente',
            default                            => '—',
        };
    }
}
