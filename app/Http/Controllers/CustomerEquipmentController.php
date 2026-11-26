<?php

namespace App\Http\Controllers;

use App\Models\CustomerInstallation;
use App\Models\InstallationEquipment;
use App\Models\InventoryDevice;
use App\Models\InventoryMovement;
use App\Models\SupportTicket;
use App\Models\TicketEquipment;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Pestaña «Equipos» de la ficha del cliente: qué tiene en casa y qué se le ha
 * gastado. Sólo lectura — los equipos se instalan y retiran desde la hoja de
 * la orden o desde un ticket, que es donde queda la firma y el kardex.
 *
 * Antes, para saber qué router tenía un cliente había que ir a Inventario y
 * buscar por nombre; los materiales sólo se veían orden por orden.
 */
class CustomerEquipmentController extends Controller
{
    public function index(Request $request, $customerId)
    {
        $tenantId = $request->user()?->tenant_id;
        abort_if(!$tenantId, 403, 'No autorizado.');

        $customer = User::where('tenant_id', $tenantId)->findOrFail($customerId);

        $installationIds = CustomerInstallation::where('customer_id', $customer->id)->pluck('id');
        $ticketIds       = SupportTicket::where('user_id', $customer->id)->pluck('id');

        return response()->json([
            'devices'   => $this->installedDevices($customer->id),
            'materials' => $this->materials($installationIds, $ticketIds),
            'retired'   => $this->retiredDevices($ticketIds),
        ]);
    }

    /** Equipos con serial que hoy están en casa del cliente, y de dónde salieron. */
    private function installedDevices(int $customerId): array
    {
        $devices = InventoryDevice::with('stock:id,brand,model,price')
            ->where('status', InventoryDevice::STATUS_INSTALLED)
            ->where('customer_id', $customerId)
            ->orderBy('id')
            ->get();

        if ($devices->isEmpty()) {
            return [];
        }

        // La última instalación de cada equipo dice cuándo y por qué orden o
        // ticket llegó. Se busca por tipo y no por to_id: los equipos que
        // vinieron de una orden de prospecto tienen to_id null (P-74).
        $lastInstall = InventoryMovement::whereIn('device_id', $devices->pluck('id'))
            ->where('type', InventoryMovement::TYPE_INSTALACION)
            ->orderBy('id')
            ->get(['id', 'device_id', 'installation_id', 'support_ticket_id', 'created_at'])
            ->keyBy('device_id');

        return $devices->map(function (InventoryDevice $d) use ($lastInstall) {
            $mov = $lastInstall->get($d->id);

            return [
                'id'          => $d->id,
                'item'        => $d->stock?->label() ?? 'Equipo',
                'serial'      => $d->serial,
                'mac'         => $d->mac,
                'unit_price'  => $d->stock?->price !== null ? (float) $d->stock->price : null,
                'since'       => $mov?->created_at,
                'origin'      => $this->origin($mov?->installation_id, $mov?->support_ticket_id),
            ];
        })->values()->all();
    }

    /**
     * Consumibles (sin serial) gastados en sus órdenes y en sus tickets,
     * agrupados por producto con el detalle de cada visita.
     */
    private function materials($installationIds, $ticketIds): array
    {
        $lines = collect();

        if ($installationIds->isNotEmpty()) {
            InstallationEquipment::with('stock:id,brand,model,unit')
                ->whereIn('installation_id', $installationIds)
                ->whereNull('device_id')
                ->get()
                ->each(fn ($l) => $lines->push([
                    'stock'    => $l->stock,
                    'stock_id' => $l->stock_id,
                    'quantity' => (float) $l->quantity,
                    'date'     => $l->created_at,
                    'origin'   => $this->origin($l->installation_id, null),
                ]));
        }

        if ($ticketIds->isNotEmpty()) {
            TicketEquipment::with('stock:id,brand,model,unit')
                ->whereIn('ticket_id', $ticketIds)
                ->whereNull('device_id')
                ->where('direction', TicketEquipment::DIRECTION_OUT)
                ->whereNull('reversed_at')
                ->get()
                ->each(fn ($l) => $lines->push([
                    'stock'    => $l->stock,
                    'stock_id' => $l->stock_id,
                    'quantity' => (float) $l->quantity,
                    'date'     => $l->created_at,
                    'origin'   => $this->origin(null, $l->ticket_id),
                ]));
        }

        return $lines
            ->groupBy('stock_id')
            ->map(function ($group) {
                $stock = $group->first()['stock'];

                return [
                    'stock_id' => $group->first()['stock_id'],
                    'item'     => $stock?->label() ?? 'Material',
                    'unit'     => $stock?->unit,
                    'total'    => round($group->sum('quantity'), 2),
                    'lines'    => $group
                        ->sortByDesc('date')
                        ->map(fn ($l) => ['quantity' => $l['quantity'], 'date' => $l['date'], 'origin' => $l['origin']])
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy('item')
            ->values()
            ->all();
    }

    /** Equipos que se le retiraron en un ticket (cambio de router, daño…). */
    private function retiredDevices($ticketIds): array
    {
        if ($ticketIds->isEmpty()) {
            return [];
        }

        return TicketEquipment::with(['stock:id,brand,model', 'device:id,serial,mac,status'])
            ->whereIn('ticket_id', $ticketIds)
            ->whereNotNull('device_id')
            ->where('direction', TicketEquipment::DIRECTION_IN)
            ->whereNull('reversed_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (TicketEquipment $l) => [
                'id'      => $l->device_id,
                'item'    => $l->stock?->label() ?? 'Equipo',
                'serial'  => $l->device?->serial,
                'mac'     => $l->device?->mac,
                'date'    => $l->created_at,
                'status'  => $l->device?->status,
                'origin'  => $this->origin(null, $l->ticket_id),
            ])
            ->values()
            ->all();
    }

    /** @return array{type: string, id: int, label: string}|null */
    private function origin(?int $installationId, ?int $ticketId): ?array
    {
        if ($installationId) {
            return ['type' => 'installation', 'id' => $installationId, 'label' => "Orden #{$installationId}"];
        }

        if ($ticketId) {
            return ['type' => 'ticket', 'id' => $ticketId, 'label' => "Ticket #{$ticketId}"];
        }

        return null;
    }
}
