<?php

namespace App\Http\Controllers;

use App\Models\InventoryBalance;
use App\Models\InventoryBranch;
use App\Models\InventoryDevice;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\SupportTicket;
use App\Models\SupportTicketHistory;
use App\Models\TicketEquipment;
use App\Models\User;
use App\Services\Inventory\InventoryLedger;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Equipos y materiales movidos en una visita de soporte.
 *
 * Hermano de InstallationEquipmentController, con la diferencia que define el
 * módulo: aquí el movimiento tiene dos sentidos. La visita entrega equipos
 * (`direction = out`) y también retira los que el cliente ya tenía
 * (`direction = in`), que es lo que pasa en el 90% de los tickets de cambio de
 * router y lo que el sistema no sabía escribir en ninguna parte.
 *
 * Todas las escrituras pasan por InventoryLedger: es el único sitio que mueve
 * existencias y deja el rastro en el kardex a la vez.
 */
class TicketEquipmentController extends Controller
{
    public function __construct(private InventoryLedger $ledger)
    {
    }

    /**
     * El ticket sobre el que se opera.
     *
     * withTrashed a propósito para `index`: un expediente archivado se consulta
     * entero, y los equipos que se movieron en él son parte del expediente. Las
     * escrituras lo comprueban aparte —ver assertOperable— porque consultar un
     * archivado es legítimo y modificarlo no.
     */
    private function resolveTicket(Request $request, $ticketId): SupportTicket
    {
        $tenantId = $request->user()?->tenant_id;
        abort_if(!$tenantId, 403, 'No autorizado.');

        return SupportTicket::withTrashed()
            ->where('tenant_id', $tenantId)
            ->findOrFail($ticketId);
    }

    /**
     * Un ticket archivado está fuera de la operación: se lee, no se toca. Y un
     * ticket sin cliente no tiene a quién entregarle nada.
     */
    private function assertOperable(SupportTicket $ticket): void
    {
        if ($ticket->trashed()) {
            throw ValidationException::withMessages([
                'ticket' => 'Este ticket está archivado. Restáuralo si necesitas mover equipos en él.',
            ]);
        }

        if (!$ticket->user_id) {
            throw ValidationException::withMessages([
                'ticket' => 'El ticket no tiene un cliente asociado, así que no hay a quién entregarle ni a quién retirarle equipos.',
            ]);
        }
    }

    /** Líneas ya cargadas en el ticket, entregas y retiros. */
    public function index(Request $request, $ticketId)
    {
        $ticket = $this->resolveTicket($request, $ticketId);

        return response()->json($this->rows($ticket));
    }

    /**
     * Lo que este usuario puede mover en este ticket concreto:
     *
     *   devices   → equipos con serial disponibles para ENTREGAR
     *   materials → consumibles con saldo para gastar
     *   installed → lo que el cliente ya tiene encima, para RETIRAR
     *
     * La lista de retirables sale de `inventory_device` y no de las hojas de
     * instalación: lo que importa es dónde está el equipo hoy, no por qué papel
     * llegó ahí. Así también aparece un aparato que entró por carga masiva.
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

        // Consumibles: un renglón por modelo y custodio con saldo. Los saldos
        // siempre tienen custodio, así que la "bodega sin sucursal" no aplica.
        $holders = array_values(array_filter(
            $sources,
            fn ($source) => $source['id'] !== null
        ));

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

        $installed = $ticket->user_id
            ? $this->ledger->devicesInstalledFor((int) $ticket->user_id)
                ->map(fn (InventoryDevice $device) => [
                    'id'       => $device->id,
                    'serial'   => $device->serial,
                    'mac'      => $device->mac,
                    'stock_id' => $device->stock_id,
                    'brand'    => $device->stock?->brand,
                    'model'    => $device->stock?->model,
                ])
                ->values()
            : collect();

        return response()->json([
            'sources'   => $sources,
            'devices'   => $devices,
            'materials' => $balances,
            'installed' => $installed,
            // Destinos válidos de un RETIRO: los custodios de arriba más la
            // baja, que no es un custodio y por eso no va en `sources` —ahí
            // sólo hay sitios de los que además se puede TOMAR—.
            'return_targets' => array_merge($sources, [[
                'type'  => InventoryMovement::HOLDER_SCRAP,
                'id'    => null,
                'label' => 'Dar de baja (dañado o perdido)',
            ]]),
        ]);
    }

    /**
     * Mueve una línea. Tres casos, y el `direction` es lo que los separa:
     *
     *   out + device_id → se le entrega un equipo con serial al cliente
     *   out + stock_id  → se gasta material en la visita
     *   in  + device_id → se le retira al cliente un equipo que ya tenía
     */
    public function store(Request $request, $ticketId)
    {
        $ticket = $this->resolveTicket($request, $ticketId);
        $this->assertOperable($ticket);

        $actor = $request->user();

        $data = $request->validate([
            'direction'   => 'nullable|in:out,in',
            'device_id'   => 'nullable|integer',
            'stock_id'    => 'nullable|integer',
            'quantity'    => 'nullable|numeric|min:0.01',
            // `scrap` sólo vale en un retiro: es la baja del equipo que vuelve
            // quemado. Como origen no significa nada — de la chatarra no sale
            // nada que entregar — y `retirar()` lo comprueba.
            'source_type' => 'nullable|in:branch,user,scrap',
            'source_id'   => 'nullable|integer',
            'notes'       => 'nullable|string|max:255',
        ]);

        $direction = $data['direction'] ?? TicketEquipment::DIRECTION_OUT;

        if (($data['source_type'] ?? null) === InventoryMovement::HOLDER_SCRAP
            && $direction !== TicketEquipment::DIRECTION_IN) {
            throw ValidationException::withMessages([
                'source_type' => 'De la chatarra no sale nada: la baja sólo vale al retirarle un equipo al cliente.',
            ]);
        }

        if ($direction === TicketEquipment::DIRECTION_IN) {
            $item = $this->retirar($ticket, $actor, $data);
        } elseif (!empty($data['device_id'])) {
            $device = InventoryDevice::with('stock')->findOrFail($data['device_id']);
            $item   = $this->ledger->assignDeviceToTicket($ticket, $device, $actor, $data['notes'] ?? null);
        } elseif (!empty($data['stock_id'])) {
            $item = $this->gastarMaterial($ticket, $actor, $data);
        } else {
            throw ValidationException::withMessages([
                'device_id' => 'Indica el equipo del inventario o el material que se usó.',
            ]);
        }

        $item = $item->fresh(['stock', 'device.stock']);

        // El expediente tiene que contar lo que salió de la bodega por su culpa.
        // Sin esto, el kardex sabe el movimiento y el historial del ticket no, y
        // quien audita el ticket no se entera de que hubo un equipo de por medio.
        SupportTicketHistory::registrar(
            $ticket,
            SupportTicketHistory::EQUIPMENT_ADDED,
            metadata: [
                'direction' => $item->direction,
                'label'     => $item->label(),
                'quantity'  => (float) $item->quantity,
                'serial'    => $item->device?->serial,
                'scrapped'  => $item->source_type === InventoryMovement::HOLDER_SCRAP,
            ],
        );

        return response()->json([
            'message'   => $item->isReturn()
                ? ($item->source_type === InventoryMovement::HOLDER_SCRAP
                    ? 'Equipo retirado del cliente y dado de baja: no vuelve a circular.'
                    : 'Equipo retirado del cliente y devuelto al inventario.')
                : 'Equipo cargado al ticket y descontado del inventario.',
            'item'      => $this->row($item),
            'equipment' => $this->rows($ticket),
            'avisos'    => $this->ledger->avisosDeGasto(),
        ], 201);
    }

    /** Retira del cliente un equipo que ya tenía instalado. */
    private function retirar(SupportTicket $ticket, User $actor, array $data): TicketEquipment
    {
        if (empty($data['device_id'])) {
            throw ValidationException::withMessages([
                'device_id' => 'Indica qué equipo se le retira al cliente. Los materiales no se retiran.',
            ]);
        }

        if (empty($data['source_type'])) {
            throw ValidationException::withMessages([
                'source_type' => 'Indica a dónde va el equipo: a una bodega, a nombre de un técnico, o de baja si volvió inservible.',
            ]);
        }

        if ($data['source_type'] === InventoryMovement::HOLDER_USER && empty($data['source_id'])) {
            throw ValidationException::withMessages([
                'source_id' => 'Indica a nombre de qué técnico queda el equipo retirado.',
            ]);
        }

        $device = InventoryDevice::with('stock')->findOrFail($data['device_id']);

        return $this->ledger->returnDeviceFromTicket(
            $ticket,
            $device,
            $data['source_type'],
            isset($data['source_id']) ? (int) $data['source_id'] : null,
            $actor,
            $data['notes'] ?? null
        );
    }

    /** Gasta un consumible del inventario en la visita. */
    private function gastarMaterial(SupportTicket $ticket, User $actor, array $data): TicketEquipment
    {
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

        return $this->ledger->assignMaterialToTicket(
            $ticket,
            $stock,
            (float) ($data['quantity'] ?? 1),
            $data['source_type'],
            (int) $data['source_id'],
            $actor,
            $data['notes'] ?? null
        );
    }

    /**
     * Quita una línea y deja el inventario como estaba: la entrega vuelve a
     * quien la aportó, el retiro vuelve a casa del cliente.
     */
    public function destroy(Request $request, $ticketId, $itemId)
    {
        $ticket = $this->resolveTicket($request, $ticketId);
        $this->assertOperable($ticket);

        $item = TicketEquipment::where('ticket_id', $ticket->id)->findOrFail($itemId);

        $etiqueta = $item->fresh(['stock', 'device.stock'])->label();
        $eraRetiro = $item->isReturn();

        $this->ledger->releaseFromTicket($item, $request->user());

        SupportTicketHistory::registrar(
            $ticket,
            SupportTicketHistory::EQUIPMENT_REMOVED,
            metadata: [
                'direction' => $eraRetiro ? TicketEquipment::DIRECTION_IN : TicketEquipment::DIRECTION_OUT,
                'label'     => $etiqueta,
            ],
        );

        return response()->json([
            'message'   => $eraRetiro
                ? 'Retiro deshecho: el equipo vuelve a figurar en casa del cliente.'
                : 'Equipo devuelto al inventario.',
            'equipment' => $this->rows($ticket),
        ]);
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

        if ($this->ledger->canTakeFrom($actor, InventoryMovement::HOLDER_BRANCH, null, $ticket)) {
            foreach (InventoryBranch::orderBy('name')->get() as $branch) {
                $sources[] = [
                    'type'  => InventoryMovement::HOLDER_BRANCH,
                    'id'    => (int) $branch->id,
                    'label' => $branch->name ?: "Sucursal #{$branch->id}",
                ];
            }

            // Equipos cargados sin sucursal: siguen siendo tomables desde bodega.
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
            'direction'   => $item->direction,
            'is_return'   => $item->isReturn(),
            'is_scrapped' => $item->isScrapped(),
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
