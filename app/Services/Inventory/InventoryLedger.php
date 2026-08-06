<?php

namespace App\Services\Inventory;

use App\Constants\Permissions;
use App\Models\CustomerInstallation;
use App\Models\InstallationEquipment;
use App\Models\InventoryBalance;
use App\Models\InventoryDevice;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El único sitio del sistema donde el inventario se mueve.
 *
 * Cada operación hace DOS cosas que tienen que pasar juntas o no pasar: cambia
 * dónde está la existencia (status del equipo, o saldo del consumible) y deja
 * la línea correspondiente en el kardex. Si eso se hiciera desde los
 * controladores, tarde o temprano alguno movería existencias sin registrar el
 * movimiento y el histórico dejaría de explicar el saldo — que es exactamente
 * el problema que este módulo vino a resolver. Por eso todo va por aquí y todo
 * va dentro de una transacción.
 *
 * Regla de visibilidad (decidida con el ISP): cada quien puede tomar lo que
 * tiene en custodia, y quien administre inventario puede además tomar de las
 * bodegas. Nadie toma equipos de la mochila de otro técnico sin traspasarlos
 * primero: si se pudiera, un técnico respondería por un equipo que desapareció
 * de su lista sin que él lo entregara.
 *
 * Con una excepción que el trabajo real exige: en una orden de instalación
 * también se puede descargar lo que carga el TÉCNICO ASIGNADO a esa orden,
 * aunque quien esté llenando la hoja sea la secretaria o el administrador.
 * Sin esto, cualquier hoja capturada en oficina obligaría a traspasar antes
 * los equipos a nombre de quien digita, que es una mentira en el kardex.
 */
class InventoryLedger
{
    /**
     * Traspasa un equipo serializado a un nuevo custodio (usuario o sucursal).
     * Es lo que ocurre cuando la bodega le entrega 10 LDF al técnico Juan.
     */
    public function transferDevice(
        InventoryDevice $device,
        string $toType,
        ?int $toId,
        ?User $actor = null,
        ?string $notes = null
    ): InventoryDevice {
        if ($device->status === InventoryDevice::STATUS_INSTALLED) {
            throw ValidationException::withMessages([
                'device_id' => "El equipo {$this->deviceName($device)} está instalado en un cliente. "
                    . 'Quítalo de la hoja de instalación antes de traspasarlo.',
            ]);
        }

        // Una sucursal puede quedar sin especificar ("bodega, sin ubicar"); un
        // custodio persona, no: sería un equipo entregado a nadie.
        if ($toType === InventoryMovement::HOLDER_USER && $toId === null) {
            throw ValidationException::withMessages([
                'to_id' => 'Indica a qué persona se le entrega el equipo.',
            ]);
        }

        return DB::transaction(function () use ($device, $toType, $toId, $actor, $notes) {
            $from = $this->currentHolderOf($device);

            if ($toType === InventoryMovement::HOLDER_USER) {
                $device->status  = InventoryDevice::STATUS_ASSIGNED;
                $device->user_id = $toId;
            } else {
                $device->status    = InventoryDevice::STATUS_STOCK;
                $device->user_id   = null;
                $device->branch_id = $toId;
            }
            $device->customer_id = null;
            $device->save();

            $this->record($device->tenant_id, [
                'stock_id'      => $device->stock_id,
                'device_id'     => $device->id,
                'device_serial' => $device->serial,
                'type'          => InventoryMovement::TYPE_TRASPASO,
                'quantity'      => 1,
                'from_type'     => $from['type'],
                'from_id'       => $from['id'],
                'to_type'       => $toType,
                'to_id'         => $toId,
                'notes'         => $notes,
            ], $actor);

            return $device;
        });
    }

    /**
     * Primer movimiento de un equipo recién dado de alta: entra del proveedor
     * al custodio con el que nació. Sin esta línea el kardex de un equipo
     * empezaría en su primer traspaso, sin decir de dónde salió.
     */
    public function recordInitialEntry(InventoryDevice $device, ?User $actor = null): InventoryMovement
    {
        $holder = $this->currentHolderOf($device);

        return $this->record($device->tenant_id, [
            'stock_id'      => $device->stock_id,
            'device_id'     => $device->id,
            'device_serial' => $device->serial,
            'type'          => InventoryMovement::TYPE_ENTRADA,
            'quantity'      => 1,
            'from_type'     => InventoryMovement::HOLDER_SUPPLIER,
            'from_id'       => $device->provider_id,
            'to_type'       => $holder['type'],
            'to_id'         => $holder['id'],
        ], $actor);
    }

    /**
     * Traspasa cantidad de un consumible entre custodios. El origen puede ser
     * null: eso es una ENTRADA (compra, ajuste inicial), no un traspaso.
     */
    public function transferQuantity(
        InventoryStock $stock,
        ?string $fromType,
        ?int $fromId,
        string $toType,
        int $toId,
        float $quantity,
        ?User $actor = null,
        ?string $notes = null
    ): void {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'La cantidad debe ser mayor que cero.',
            ]);
        }

        DB::transaction(function () use ($stock, $fromType, $fromId, $toType, $toId, $quantity, $actor, $notes) {
            if ($fromType !== null && $fromId !== null) {
                $this->decrementBalance($stock, $fromType, $fromId, $quantity);
            }

            $this->incrementBalance($stock, $toType, $toId, $quantity);

            $this->record($stock->tenant_id, [
                'stock_id'  => $stock->id,
                'type'      => $fromType === null
                    ? InventoryMovement::TYPE_ENTRADA
                    : InventoryMovement::TYPE_TRASPASO,
                'quantity'  => $quantity,
                'from_type' => $fromType ?? InventoryMovement::HOLDER_SUPPLIER,
                'from_id'   => $fromId,
                'to_type'   => $toType,
                'to_id'     => $toId,
                'notes'     => $notes,
            ], $actor);
        });
    }

    /**
     * Descarga un equipo serializado del custodio y lo deja instalado en el
     * cliente de la orden. Devuelve la línea creada en la hoja.
     */
    public function assignDeviceToInstallation(
        CustomerInstallation $installation,
        InventoryDevice $device,
        User $actor,
        ?string $notes = null
    ): InstallationEquipment {
        if ($device->status === InventoryDevice::STATUS_INSTALLED) {
            throw ValidationException::withMessages([
                'device_id' => "El equipo {$this->deviceName($device)} ya está instalado en otro cliente.",
            ]);
        }

        if ($device->status === InventoryDevice::STATUS_RETIRED) {
            throw ValidationException::withMessages([
                'device_id' => "El equipo {$this->deviceName($device)} está dado de baja.",
            ]);
        }

        $source = $this->currentHolderOf($device);
        $this->assertCanTakeFrom($actor, $source['type'], $source['id'], $installation);

        return DB::transaction(function () use ($installation, $device, $actor, $source, $notes) {
            $device->status      = InventoryDevice::STATUS_INSTALLED;
            $device->customer_id = $installation->customer_id;
            $device->save();

            $item = new InstallationEquipment([
                'installation_id' => $installation->id,
                'stock_id'        => $device->stock_id,
                'device_id'       => $device->id,
                'quantity'        => 1,
                'unit_price'      => $device->stock?->price,
                'source_type'     => $source['type'],
                'source_id'       => $source['id'],
                'notes'           => $notes,
                'created_by'      => $actor->id,
            ]);
            $item->tenant_id = $installation->tenant_id;
            $item->save();

            $this->record($installation->tenant_id, [
                'stock_id'        => $device->stock_id,
                'device_id'       => $device->id,
                'device_serial'   => $device->serial,
                'type'            => InventoryMovement::TYPE_INSTALACION,
                'quantity'        => 1,
                'from_type'       => $source['type'],
                'from_id'         => $source['id'],
                'to_type'         => InventoryMovement::HOLDER_CUSTOMER,
                'to_id'           => $installation->customer_id,
                'installation_id' => $installation->id,
                'customer_id'     => $installation->customer_id,
                'notes'           => $notes,
            ], $actor);

            return $item;
        });
    }

    /**
     * Descuenta cantidad de un consumible del custodio y la deja en la hoja.
     */
    public function assignMaterialToInstallation(
        CustomerInstallation $installation,
        InventoryStock $stock,
        float $quantity,
        string $sourceType,
        int $sourceId,
        User $actor,
        ?string $notes = null
    ): InstallationEquipment {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'La cantidad debe ser mayor que cero.',
            ]);
        }

        $this->assertCanTakeFrom($actor, $sourceType, $sourceId, $installation);

        return DB::transaction(function () use ($installation, $stock, $quantity, $sourceType, $sourceId, $actor, $notes) {
            $this->decrementBalance($stock, $sourceType, $sourceId, $quantity);

            $item = new InstallationEquipment([
                'installation_id' => $installation->id,
                'stock_id'        => $stock->id,
                'device_id'       => null,
                'quantity'        => $quantity,
                'unit_price'      => $stock->price,
                'source_type'     => $sourceType,
                'source_id'       => $sourceId,
                'notes'           => $notes,
                'created_by'      => $actor->id,
            ]);
            $item->tenant_id = $installation->tenant_id;
            $item->save();

            $this->record($installation->tenant_id, [
                'stock_id'        => $stock->id,
                'type'            => InventoryMovement::TYPE_INSTALACION,
                'quantity'        => $quantity,
                'from_type'       => $sourceType,
                'from_id'         => $sourceId,
                'to_type'         => InventoryMovement::HOLDER_CUSTOMER,
                'to_id'           => $installation->customer_id,
                'installation_id' => $installation->id,
                'customer_id'     => $installation->customer_id,
                'notes'           => $notes,
            ], $actor);

            return $item;
        });
    }

    /**
     * Quita una línea de la hoja y devuelve la existencia a quien la aportó.
     * Se usa al corregir un error del técnico ("cargué la LDF equivocada").
     */
    public function releaseFromInstallation(InstallationEquipment $item, User $actor): void
    {
        DB::transaction(function () use ($item, $actor) {
            $installation = $item->installation;
            $backType     = $item->source_type ?? InventoryMovement::HOLDER_USER;
            $backId       = $item->source_id   ?? $actor->id;

            if ($item->device_id) {
                $device = InventoryDevice::withoutTenantScope()->find($item->device_id);

                if ($device) {
                    if ($backType === InventoryMovement::HOLDER_USER) {
                        $device->status  = InventoryDevice::STATUS_ASSIGNED;
                        $device->user_id = $backId;
                    } else {
                        $device->status    = InventoryDevice::STATUS_STOCK;
                        $device->user_id   = null;
                        $device->branch_id = $backId;
                    }
                    $device->customer_id = null;
                    $device->save();
                }

                $this->record($item->tenant_id, [
                    'stock_id'        => $item->stock_id,
                    'device_id'       => $item->device_id,
                    'device_serial'   => $device?->serial,
                    'type'            => InventoryMovement::TYPE_DEVOLUCION,
                    'quantity'        => 1,
                    'from_type'       => InventoryMovement::HOLDER_CUSTOMER,
                    'from_id'         => $installation?->customer_id,
                    'to_type'         => $backType,
                    'to_id'           => $backId,
                    'installation_id' => $item->installation_id,
                    'customer_id'     => $installation?->customer_id,
                ], $actor);
            } else {
                $stock = InventoryStock::withoutTenantScope()->find($item->stock_id);

                if ($stock) {
                    $this->incrementBalance($stock, $backType, $backId, (float) $item->quantity);
                }

                $this->record($item->tenant_id, [
                    'stock_id'        => $item->stock_id,
                    'type'            => InventoryMovement::TYPE_DEVOLUCION,
                    'quantity'        => $item->quantity,
                    'from_type'       => InventoryMovement::HOLDER_CUSTOMER,
                    'from_id'         => $installation?->customer_id,
                    'to_type'         => $backType,
                    'to_id'           => $backId,
                    'installation_id' => $item->installation_id,
                    'customer_id'     => $installation?->customer_id,
                ], $actor);
            }

            $item->delete();
        });
    }

    /**
     * Da de baja un equipo (dañado, perdido, devuelto al proveedor).
     */
    public function retireDevice(InventoryDevice $device, ?User $actor = null, ?string $notes = null): InventoryDevice
    {
        return DB::transaction(function () use ($device, $actor, $notes) {
            $from = $this->currentHolderOf($device);

            $device->status = InventoryDevice::STATUS_RETIRED;
            $device->save();

            $this->record($device->tenant_id, [
                'stock_id'      => $device->stock_id,
                'device_id'     => $device->id,
                'device_serial' => $device->serial,
                'type'          => InventoryMovement::TYPE_BAJA,
                'quantity'      => 1,
                'from_type'     => $from['type'],
                'from_id'       => $from['id'],
                'to_type'       => InventoryMovement::HOLDER_SCRAP,
                'notes'         => $notes,
            ], $actor);

            return $device;
        });
    }

    /**
     * ¿Puede este usuario tomar existencias de ese custodio?
     * Lo suyo siempre; lo del técnico de la orden cuando hay orden; las bodegas
     * sólo con permiso de inventario.
     */
    public function canTakeFrom(
        User $actor,
        ?string $holderType,
        ?int $holderId,
        ?CustomerInstallation $installation = null
    ): bool {
        if ($holderType === InventoryMovement::HOLDER_USER) {
            if ((int) $holderId === (int) $actor->id) {
                return true;
            }

            return $installation?->technician_id !== null
                && (int) $holderId === (int) $installation->technician_id;
        }

        if ($holderType === InventoryMovement::HOLDER_BRANCH || $holderType === null) {
            return $this->managesInventory($actor);
        }

        return false;
    }

    /** Custodio actual de un equipo, en el vocabulario del kardex. */
    public function currentHolderOf(InventoryDevice $device): array
    {
        return match ($device->status) {
            InventoryDevice::STATUS_ASSIGNED  => ['type' => InventoryMovement::HOLDER_USER,     'id' => $device->user_id],
            InventoryDevice::STATUS_INSTALLED => ['type' => InventoryMovement::HOLDER_CUSTOMER, 'id' => $device->customer_id],
            InventoryDevice::STATUS_RETIRED   => ['type' => InventoryMovement::HOLDER_SCRAP,    'id' => null],
            default                           => ['type' => InventoryMovement::HOLDER_BRANCH,   'id' => $device->branch_id],
        };
    }

    /** Saldo actual de un consumible en poder de un custodio. */
    public function balanceOf(InventoryStock $stock, string $holderType, int $holderId): float
    {
        $row = InventoryBalance::withoutTenantScope()
            ->where('tenant_id', $stock->tenant_id)
            ->where('stock_id', $stock->id)
            ->heldBy($holderType, $holderId)
            ->first();

        return (float) ($row->quantity ?? 0);
    }

    private function assertCanTakeFrom(
        User $actor,
        ?string $holderType,
        ?int $holderId,
        ?CustomerInstallation $installation = null
    ): void {
        if ($this->canTakeFrom($actor, $holderType, $holderId, $installation)) {
            return;
        }

        throw ValidationException::withMessages([
            'source' => $holderType === InventoryMovement::HOLDER_USER
                ? 'Ese equipo lo tiene otro técnico. Pídele que te lo traspase desde Inventario → Entregas.'
                : 'No tienes permiso para tomar equipos de la bodega. Usa los que tengas asignados.',
        ]);
    }

    private function managesInventory(User $actor): bool
    {
        if ((int) $actor->role_id === 1) {
            return true;
        }

        $actor->loadMissing('role');

        return $actor->role?->hasPermission(Permissions::VIEW_INVENTORY) ?? false;
    }

    private function incrementBalance(InventoryStock $stock, string $holderType, int $holderId, float $quantity): void
    {
        $balance = $this->lockBalance($stock, $holderType, $holderId);

        if ($balance) {
            $balance->quantity = (float) $balance->quantity + $quantity;
            $balance->save();
            return;
        }

        $row = new InventoryBalance([
            'stock_id'    => $stock->id,
            'holder_type' => $holderType,
            'holder_id'   => $holderId,
            'quantity'    => $quantity,
        ]);
        $row->tenant_id = $stock->tenant_id;
        $row->save();
    }

    private function decrementBalance(InventoryStock $stock, string $holderType, int $holderId, float $quantity): void
    {
        $balance   = $this->lockBalance($stock, $holderType, $holderId);
        $available = (float) ($balance->quantity ?? 0);

        if ($available < $quantity) {
            throw ValidationException::withMessages([
                'quantity' => "No hay suficiente {$stock->label()}: disponible "
                    . rtrim(rtrim(number_format($available, 2, ',', '.'), '0'), ',')
                    . ', pedido ' . rtrim(rtrim(number_format($quantity, 2, ',', '.'), '0'), ',') . '.',
            ]);
        }

        $balance->quantity = $available - $quantity;
        $balance->save();
    }

    /**
     * Bloquea la fila de saldo mientras dura la transacción. Sin esto, dos
     * técnicos descargando el mismo material a la vez pueden leer el mismo
     * saldo y dejarlo en negativo. lockForUpdate() no existe en SQLite (donde
     * corre la suite de pruebas) pero allí tampoco hay concurrencia real.
     */
    private function lockBalance(InventoryStock $stock, string $holderType, int $holderId): ?InventoryBalance
    {
        $query = InventoryBalance::withoutTenantScope()
            ->where('tenant_id', $stock->tenant_id)
            ->where('stock_id', $stock->id)
            ->heldBy($holderType, $holderId);

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function record(int $tenantId, array $attributes, ?User $actor): InventoryMovement
    {
        $movement = new InventoryMovement($attributes + [
            'created_by' => $actor?->id,
            'created_at' => now(),
        ]);
        $movement->tenant_id = $tenantId;
        $movement->save();

        return $movement;
    }

    private function deviceName(InventoryDevice $device): string
    {
        $name = $device->stock?->label() ?? 'Equipo';

        return $device->serial ? "{$name} (S/N {$device->serial})" : $name;
    }
}
