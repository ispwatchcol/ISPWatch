<?php

namespace App\Http\Controllers;

use App\Models\InventoryBalance;
use App\Models\InventoryBranch;
use App\Models\InventoryDevice;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\SupportTicket;
use App\Models\TicketEquipment;
use App\Models\User;
use App\Services\Inventory\InventoryLedger;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Equipos y materiales entregados en una visita de soporte.
 *
 * Espejo de InstallationEquipmentController: misma forma, mismas reglas de
 * custodia y las mismas escrituras pasando por InventoryLedger. La diferencia
 * está en `retrieve()`, que no tiene equivalente en instalaciones y es lo que
 * hace útil el módulo: un ticket casi nunca es «agregar un equipo», es un
 * REEMPLAZO — al cliente se le dañó el router y el técnico lo cambia. Sin poder
 * retirar el viejo, ese equipo quedaría asignado al cliente para siempre y el
 * inventario mentiría.
 */
class TicketEquipmentController extends Controller
{
    public function __construct(private InventoryLedger $ledger)
    {
    }

    private function resolveTicket(Request $request, $ticketId): SupportTicket
    {
        $tenantId = $request->user()?->tenant_id;
        abort_if(!$tenantId, 403, 'No autorizado.');

        return SupportTicket::where('tenant_id', $tenantId)->findOrFail($ticketId);
    }

    /** Líneas ya cargadas en el ticket. */
    public function index(Request $request, $ticketId)
    {
        return response()->json($this->rows($this->resolveTicket($request, $ticketId)));
    }

    /**
     * Qué puede descargar este usuario en este ticket: lo suyo, lo del técnico
     * asignado y —si administra inventario— las bodegas. Además, lo que el
     * CLIENTE ya tiene encima, que es de donde sale el equipo a reemplazar.
     */
    public function available(Request $request, $ticketId)
    {
        $ticket = $this->resolveTicket($request, $ticketId);
        $actor  = $request->user();

        $sources = $this->allowedSources($actor, $ticket);

        // El filtro por custodio va en SQL y no en PHP: un ISP con miles de
        // equipos no debe traerlos todos a memoria para descartar la mayoría.
        $devices = InventoryDevice::with(['stock:id,brand,model,price,is_serialized,unit'])
            ->available()
            ->where(function ($query) use ($sources) {
                foreach ($sources as $source) {
                    if ($source['type'] === InventoryMovement::HOLDER_USER) {
                        $query->orWhere(fn ($q) => $q
                            ->where('status', InventoryDevice::STATUS_ASSIGNED)
                            ->where('user_id', $source['id']));
                        continue;
                    }

                    $query->orWhere(function ($q) use ($source) {
                        $q->where('status', InventoryDevice::STATUS_STOCK);
                        $source['id'] === null
                            ? $q->whereNull('branch_id')
                            : $q->where('branch_id', $source['id']);
                    });
                }
            })
            ->orderBy('id')
            ->get()
            ->map(function (InventoryDevice $device) use ($sources) {
                $holder = $this->ledger->currentHolderOf($device);

                return [
                    'id'           => $device->id,
                    'serial'       => $device->serial,
                    'mac'          => $device->mac,
                    'stock_id'     => $device->stock_id,
                    'brand'        => $device->stock?->brand,
                    'model'        => $device->stock?->model,
                    'price'        => $device->stock?->price,
                    'source_type'  => $holder['type'],
                    'source_id'    => $holder['id'],
                    'source_label' => $this->sourceLabel($sources, $holder['type'], $holder['id']),
                ];
            })
            ->values();

        $holders = array_values(array_filter($sources, fn ($s) => $s['id'] !== null));

        $balances = InventoryBalance::with(['stock:id,brand,model,price,is_serialized,unit'])
            ->where('quantity', '>', 0)
            ->where(function ($query) use ($holders) {
                if (empty($holders)) {
                    $query->whereRaw('1 = 0');
                    return;
                }

                foreach ($holders as $holder) {
                    $query->orWhere(fn ($q) => $q
                        ->where('holder_type', $holder['type'])
                        ->where('holder_id', $holder['id']));
                }
            })
            ->get()
            ->map(fn (InventoryBalance $b) => [
                'stock_id'     => $b->stock_id,
                'brand'        => $b->stock?->brand,
                'model'        => $b->stock?->model,
                'unit'         => $b->stock?->unit,
                'price'        => $b->stock?->price,
                'quantity'     => (float) $b->quantity,
                'source_type'  => $b->holder_type,
                'source_id'    => $b->holder_id,
                'source_label' => $this->sourceLabel($sources, $b->holder_type, $b->holder_id),
            ])
            ->values();

        return response()->json([
            'sources'        => $sources,
            'devices'        => $devices,
            'materials'      => $balances,
            // Lo que el cliente ya tiene: la lista de la que se elige el equipo
            // dañado a retirar. Sin esto el reemplazo obligaría a saberse el
            // serial de memoria.
            'customerDevices' => $this->customerDevices($ticket),
        ]);
    }

    /** Descarga un equipo (device_id) o un material (stock_id + quantity). */
    public function store(Request $request, $ticketId)
    {
        $ticket = $this->resolveTicket($request, $ticketId);
        $actor  = $request->user();

        $data = $request->validate([
            'device_id'   => 'nullable|integer',
            'stock_id'    => 'nullable|integer',
            'quantity'    => 'nullable|numeric|min:0.01',
            'source_type' => 'nullable|in:branch,user',
            'source_id'   => 'nullable|integer',
            'notes'       => 'nullable|string|max:255',
        ]);

        if (empty($data['device_id']) && empty($data['stock_id'])) {
            throw ValidationException::withMessages([
                'device_id' => 'Indica el equipo del inventario o el material que se usó.',
            ]);
        }

        if (!empty($data['device_id'])) {
            $device = InventoryDevice::with('stock')->findOrFail($data['device_id']);
            $item   = $this->ledger->assignDeviceToTicket($ticket, $device, $actor, $data['notes'] ?? null);
        } else {
            $stock = InventoryStock::findOrFail($data['stock_id']);

            if ($stock->is_serialized) {
                throw ValidationException::withMessages([
                    'stock_id' => "{$stock->label()} se maneja por serial: elígelo de la lista de equipos, no como material.",
                ]);
            }

            if (empty($data['source_type']) || empty($data['source_id'])) {
                throw ValidationException::withMessages([
                    'source_type' => 'Indica de qué bodega o de quién sale el material.',
                ]);
            }

            $item = $this->ledger->assignMaterialToTicket(
                $ticket,
                $stock,
                (float) ($data['quantity'] ?? 1),
                $data['source_type'],
                (int) $data['source_id'],
                $actor,
                $data['notes'] ?? null
            );
        }

        return response()->json([
            'message'   => 'Equipo cargado al ticket y descontado del inventario.',
            'item'      => $this->row($item->fresh(['stock', 'device.stock'])),
            'equipment' => $this->rows($ticket),
        ], 201);
    }

    /**
     * Retira del cliente un equipo que YA tenía — la otra mitad del reemplazo.
     *
     * `disposition` distingue lo que pasó con el aparato: `devolucion` si vuelve
     * a circular (se lo lleva el técnico) o `baja` si está dañado o perdido.
     * Confundirlas dejaría equipos muertos contando como disponibles.
     */
    public function retrieve(Request $request, $ticketId)
    {
        $ticket = $this->resolveTicket($request, $ticketId);

        $data = $request->validate([
            'device_id'   => 'required|integer',
            'disposition' => 'required|in:devolucion,baja',
            'notes'       => 'nullable|string|max:255',
        ]);

        $device = InventoryDevice::with('stock')->findOrFail($data['device_id']);

        $this->ledger->retrieveFromCustomer(
            $ticket,
            $device,
            $request->user(),
            $data['disposition'] === 'baja',
            $data['notes'] ?? null
        );

        return response()->json([
            'message' => $data['disposition'] === 'baja'
                ? 'Equipo retirado del cliente y dado de baja.'
                : 'Equipo retirado del cliente y devuelto a tu inventario.',
            'equipment'       => $this->rows($ticket),
            'customerDevices' => $this->customerDevices($ticket),
        ]);
    }

    /** Quita una línea y devuelve la existencia a quien la aportó. */
    public function destroy(Request $request, $ticketId, $itemId)
    {
        $ticket = $this->resolveTicket($request, $ticketId);

        $item = TicketEquipment::where('ticket_id', $ticket->id)->findOrFail($itemId);

        $this->ledger->releaseFromTicket($item, $request->user());

        return response()->json([
            'message'   => 'Equipo devuelto al inventario.',
            'equipment' => $this->rows($ticket),
        ]);
    }

    /** Equipos que el cliente del ticket tiene instalados ahora mismo. */
    private function customerDevices(SupportTicket $ticket)
    {
        return InventoryDevice::with(['stock:id,brand,model,unit'])
            ->where('customer_id', $ticket->user_id)
            ->where('status', InventoryDevice::STATUS_INSTALLED)
            ->orderBy('id')
            ->get()
            ->map(fn (InventoryDevice $d) => [
                'id'     => $d->id,
                'serial' => $d->serial,
                'mac'    => $d->mac,
                'brand'  => $d->stock?->brand,
                'model'  => $d->stock?->model,
                'label'  => trim(($d->stock?->brand ?? '') . ' ' . ($d->stock?->model ?? '')) ?: 'Equipo',
            ])
            ->values();
    }

    /** Custodios de los que este usuario puede tomar en este ticket. */
    private function allowedSources(User $actor, SupportTicket $ticket): array
    {
        $sources = [[
            'type'  => InventoryMovement::HOLDER_USER,
            'id'    => (int) $actor->id,
            'label' => 'Mis equipos',
        ]];

        $techId = $ticket->staff_id;

        if ($techId && (int) $techId !== (int) $actor->id) {
            $tech = User::find($techId);
            $sources[] = [
                'type'  => InventoryMovement::HOLDER_USER,
                'id'    => (int) $techId,
                'label' => 'Técnico ' . (trim(($tech?->user_name ?? '') . ' ' . ($tech?->user_lastname ?? '')) ?: ($tech?->name ?? 'asignado')),
            ];
        }

        if ($this->ledger->canTakeFromHolder($actor, InventoryMovement::HOLDER_BRANCH, null, $techId)) {
            foreach (InventoryBranch::orderBy('name')->get() as $branch) {
                $sources[] = [
                    'type'  => InventoryMovement::HOLDER_BRANCH,
                    'id'    => (int) $branch->id,
                    'label' => $branch->name ?: "Sucursal #{$branch->id}",
                ];
            }

            $sources[] = [
                'type'  => InventoryMovement::HOLDER_BRANCH,
                'id'    => null,
                'label' => 'Bodega (sin sucursal)',
            ];
        }

        return $sources;
    }

    private function sourceLabel(array $sources, ?string $type, ?int $id): string
    {
        foreach ($sources as $source) {
            if ($source['type'] === $type && (int) $source['id'] === (int) $id) {
                return $source['label'];
            }
        }

        return 'Inventario';
    }

    private function rows(SupportTicket $ticket)
    {
        return TicketEquipment::with(['stock', 'device.stock'])
            ->where('ticket_id', $ticket->id)
            ->orderBy('id')
            ->get()
            ->map(fn (TicketEquipment $item) => $this->row($item));
    }

    private function row(TicketEquipment $item): array
    {
        $stock = $item->stock ?? $item->device?->stock;

        return [
            'id'          => $item->id,
            'device_id'   => $item->device_id,
            'stock_id'    => $item->stock_id,
            'label'       => $item->label(),
            'brand'       => $stock?->brand,
            'model'       => $stock?->model,
            'serial'      => $item->device?->serial,
            'mac'         => $item->device?->mac,
            'unit'        => $stock?->unit,
            'quantity'    => (float) $item->quantity,
            'unit_price'  => $item->unit_price !== null ? (float) $item->unit_price : null,
            'is_device'   => $item->device_id !== null,
            'source_type' => $item->source_type,
            'source_id'   => $item->source_id,
            'notes'       => $item->notes,
        ];
    }
}
