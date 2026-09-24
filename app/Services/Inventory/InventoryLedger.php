<?php

namespace App\Services\Inventory;

use App\Constants\Permissions;
use App\Models\CustomerInstallation;
use App\Models\InstallationEquipment;
use App\Models\InventoryBalance;
use App\Models\InventoryDevice;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\SupportTicket;
use App\Models\TicketEquipment;
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
    /** @var array<int, string> Avisos de gastos que no se pudieron crear. */
    private array $avisosDeGasto = [];

    // Por constructor y no con app() dentro de record(): así la instancia vive lo
    // que dure la petición y puede memoizar los ajustes del tenant, en vez de
    // resolverlos de nuevo en cada movimiento.
    public function __construct(private InventoryExpenseRecorder $expenseRecorder)
    {
    }

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

    // ──────────────────────────────────────────────────────────────────────
    // Visita de soporte.
    //
    // Mismo kardex y mismos custodios que la instalación, con una diferencia
    // que manda sobre el diseño: la visita de soporte se mueve en DOS
    // sentidos. Un cambio de router entrega uno y retira otro, y si sólo se
    // registrara la entrega el equipo viejo se quedaría marcado como instalado
    // en casa del cliente para siempre.
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Descarga un equipo serializado del custodio y lo deja instalado en el
     * cliente del ticket. Devuelve la línea creada.
     */
    public function assignDeviceToTicket(
        SupportTicket $ticket,
        InventoryDevice $device,
        User $actor,
        ?string $notes = null
    ): TicketEquipment {
        $customerId = $this->ticketCustomerId($ticket);

        if ($device->status === InventoryDevice::STATUS_INSTALLED) {
            throw ValidationException::withMessages([
                'device_id' => (int) $device->customer_id === $customerId
                    ? "El equipo {$this->deviceName($device)} ya figura instalado en este cliente."
                    : "El equipo {$this->deviceName($device)} ya está instalado en otro cliente.",
            ]);
        }

        if ($device->status === InventoryDevice::STATUS_RETIRED) {
            throw ValidationException::withMessages([
                'device_id' => "El equipo {$this->deviceName($device)} está dado de baja.",
            ]);
        }

        $source = $this->currentHolderOf($device);
        $this->assertCanTakeFrom($actor, $source['type'], $source['id'], $ticket);

        return DB::transaction(function () use ($ticket, $device, $actor, $source, $notes, $customerId) {
            $device->status      = InventoryDevice::STATUS_INSTALLED;
            $device->customer_id = $customerId;
            $device->save();

            $item = new TicketEquipment([
                'ticket_id'   => $ticket->id,
                'stock_id'    => $device->stock_id,
                'device_id'   => $device->id,
                'direction'   => TicketEquipment::DIRECTION_OUT,
                'quantity'    => 1,
                'unit_price'  => $device->stock?->price,
                'source_type' => $source['type'],
                'source_id'   => $source['id'],
                'notes'       => $notes,
                'created_by'  => $actor->id,
            ]);
            $item->tenant_id = $ticket->tenant_id;
            $item->save();

            $this->record($ticket->tenant_id, [
                'stock_id'          => $device->stock_id,
                'device_id'         => $device->id,
                'device_serial'     => $device->serial,
                'type'              => InventoryMovement::TYPE_INSTALACION,
                'quantity'          => 1,
                'from_type'         => $source['type'],
                'from_id'           => $source['id'],
                'to_type'           => InventoryMovement::HOLDER_CUSTOMER,
                'to_id'             => $customerId,
                'support_ticket_id' => $ticket->id,
                'customer_id'       => $customerId,
                'notes'             => $notes,
            ], $actor);

            return $item;
        });
    }

    /**
     * Descuenta cantidad de un consumible del custodio y la deja en el ticket.
     */
    public function assignMaterialToTicket(
        SupportTicket $ticket,
        InventoryStock $stock,
        float $quantity,
        string $sourceType,
        int $sourceId,
        User $actor,
        ?string $notes = null
    ): TicketEquipment {
        $customerId = $this->ticketCustomerId($ticket);

        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'La cantidad debe ser mayor que cero.',
            ]);
        }

        $this->assertCanTakeFrom($actor, $sourceType, $sourceId, $ticket);

        return DB::transaction(function () use ($ticket, $stock, $quantity, $sourceType, $sourceId, $actor, $notes, $customerId) {
            $this->decrementBalance($stock, $sourceType, $sourceId, $quantity);

            $item = new TicketEquipment([
                'ticket_id'   => $ticket->id,
                'stock_id'    => $stock->id,
                'device_id'   => null,
                'direction'   => TicketEquipment::DIRECTION_OUT,
                'quantity'    => $quantity,
                'unit_price'  => $stock->price,
                'source_type' => $sourceType,
                'source_id'   => $sourceId,
                'notes'       => $notes,
                'created_by'  => $actor->id,
            ]);
            $item->tenant_id = $ticket->tenant_id;
            $item->save();

            $this->record($ticket->tenant_id, [
                'stock_id'          => $stock->id,
                'type'              => InventoryMovement::TYPE_INSTALACION,
                'quantity'          => $quantity,
                'from_type'         => $sourceType,
                'from_id'           => $sourceId,
                'to_type'           => InventoryMovement::HOLDER_CUSTOMER,
                'to_id'             => $customerId,
                'support_ticket_id' => $ticket->id,
                'customer_id'       => $customerId,
                'notes'             => $notes,
            ], $actor);

            return $item;
        });
    }

    /**
     * Retira de casa del cliente un equipo que estaba instalado y lo devuelve
     * al inventario: a la mochila de un técnico o a una bodega.
     *
     * Esto no existía en ninguna parte del sistema. Un equipo que llegaba a
     * `installed` sólo salía de ahí borrando la línea de la instalación, que es
     * una corrección de captura y no un retiro: borraba la historia de la visita
     * en la que se entregó. Aquí la entrega vieja se respeta y el retiro se
     * escribe como lo que es, un movimiento nuevo en sentido contrario.
     */
    public function returnDeviceFromTicket(
        SupportTicket $ticket,
        InventoryDevice $device,
        string $toType,
        ?int $toId,
        User $actor,
        ?string $notes = null
    ): TicketEquipment {
        // `scrap` es el tercer destino y no un custodio: el equipo que se recoge
        // quemado no vuelve a circular. Distinguirlo importa porque un aparato
        // muerto devuelto a bodega cuenta como disponible, y alguien lo va a
        // prometer en la siguiente instalación.
        $esBaja = $toType === InventoryMovement::HOLDER_SCRAP;

        $customerId = $this->ticketCustomerId($ticket);

        if ($device->status !== InventoryDevice::STATUS_INSTALLED) {
            throw ValidationException::withMessages([
                'device_id' => "El equipo {$this->deviceName($device)} no figura instalado en casa de ningún cliente, así que no hay nada que retirar.",
            ]);
        }

        if ((int) $device->customer_id !== $customerId) {
            throw ValidationException::withMessages([
                'device_id' => "El equipo {$this->deviceName($device)} está instalado en otro cliente. Desde este ticket sólo se retiran los equipos de su propio cliente.",
            ]);
        }

        // Quien recibe el equipo responde por él, así que valen las mismas
        // reglas de custodia que para tomarlo: nadie le mete un aparato en la
        // mochila a otro técnico sin que él lo sepa. La baja no tiene custodio
        // que responda, así que no se comprueba nada — y queda en el kardex
        // como `baja`, que es lo que la hace auditable.
        if (!$esBaja) {
            $this->assertCanHandOverTo($actor, $toType, $toId, $ticket);
        }

        return DB::transaction(function () use ($ticket, $device, $toType, $toId, $actor, $notes, $customerId, $esBaja) {
            if ($esBaja) {
                $device->status      = InventoryDevice::STATUS_RETIRED;
                $device->customer_id = null;
                $device->user_id     = null;
                $device->save();
            } else {
                $this->placeDeviceWith($device, $toType, $toId);
            }

            $item = new TicketEquipment([
                'ticket_id'   => $ticket->id,
                'stock_id'    => $device->stock_id,
                'device_id'   => $device->id,
                'direction'   => TicketEquipment::DIRECTION_IN,
                'quantity'    => 1,
                // Un retiro no se cobra. El precio va en blanco para que nadie
                // lo arrastre por descuido al cargo del ticket.
                'unit_price'  => null,
                'source_type' => $toType,
                'source_id'   => $toId,
                'notes'       => $notes,
                'created_by'  => $actor->id,
            ]);
            $item->tenant_id = $ticket->tenant_id;
            $item->save();

            $this->record($ticket->tenant_id, [
                'stock_id'          => $device->stock_id,
                'device_id'         => $device->id,
                'device_serial'     => $device->serial,
                'type'              => $esBaja ? InventoryMovement::TYPE_BAJA : InventoryMovement::TYPE_DEVOLUCION,
                'quantity'          => 1,
                'from_type'         => InventoryMovement::HOLDER_CUSTOMER,
                'from_id'           => $customerId,
                'to_type'           => $toType,
                'to_id'             => $esBaja ? null : $toId,
                'support_ticket_id' => $ticket->id,
                'customer_id'       => $customerId,
                'notes'             => $notes,
            ], $actor);

            return $item;
        });
    }

    /**
     * REVIERTE una línea del ticket: deja el inventario como estaba antes y
     * marca la línea como revertida, con actor, motivo y fecha.
     *
     * Deshacer una ENTREGA devuelve la existencia a quien la aportó: es el
     * «cargué el router equivocado» del técnico. Deshacer un RETIRO hace lo
     * contrario —el equipo vuelve a casa del cliente—, porque un retiro mal
     * anotado deja al cliente sin el aparato que sigue teniendo encima.
     *
     * LA LÍNEA NO SE BORRA, y ése es el cambio que pedía la auditoría. Antes
     * esto terminaba en `$item->delete()`: el kardex conservaba el movimiento y
     * su compensación, pero la hoja del ticket perdía la única prueba dentro
     * del expediente de que aquel aparato llegó a moverse. Ahora la línea se
     * queda, marcada, y quien audite el ticket ve las dos cosas: que hubo un
     * equipo y que alguien lo deshizo, cuándo y por qué.
     *
     * Reversa de una reversa: no. Una línea ya revertida no se vuelve a tocar;
     * si hay que rehacer el movimiento se carga de nuevo, y quedan las tres.
     */
    public function reverseTicketLine(TicketEquipment $item, User $actor, string $motivo): TicketEquipment
    {
        if ($item->isReversed()) {
            throw ValidationException::withMessages([
                'item' => 'Esta línea ya se revirtió el '
                    . $item->reversed_at->format('d/m/Y H:i')
                    . '. Si hay que volver a mover el equipo, cárgalo otra vez.',
            ]);
        }

        return DB::transaction(function () use ($item, $actor, $motivo) {
            // withTrashed: un ticket archivado sigue necesitando poder corregir
            // su hoja, y sin esto la relación devolvería null y el movimiento
            // quedaría sin cliente. Que el ticket archivado NO admita cambios lo
            // decide el controlador, que es quien conoce la operación.
            $ticket     = SupportTicket::withTrashed()->withoutTenantScope()->find($item->ticket_id);
            $customerId = $ticket?->user_id ? (int) $ticket->user_id : null;
            $backType   = $item->source_type ?? InventoryMovement::HOLDER_USER;
            $backId     = $item->source_id   ?? $actor->id;

            $device = $item->device_id
                ? InventoryDevice::withoutTenantScope()->find($item->device_id)
                : null;

            if ($item->isReturn()) {
                // Era un retiro: se revierte volviendo a instalarlo en el cliente.
                if ($customerId === null) {
                    throw ValidationException::withMessages([
                        'item' => 'El ticket ya no tiene cliente, así que no hay a quién devolverle el equipo. Muévelo desde Inventario.',
                    ]);
                }

                // El invariante manda incluso al deshacer: si mientras tanto el
                // aparato se instaló en OTRA casa, reponerlo aquí lo pondría en
                // dos a la vez. Es el mismo `status` que guardan las dos rutas
                // de entrega, comprobado también en el camino de vuelta.
                if ($device
                    && $device->status === InventoryDevice::STATUS_INSTALLED
                    && (int) $device->customer_id !== $customerId) {
                    throw ValidationException::withMessages([
                        'item' => "El equipo {$this->deviceName($device)} ya está instalado en otro cliente: "
                            . 'deshacer este retiro lo pondría en dos casas a la vez. '
                            . 'Retíralo de allí primero.',
                    ]);
                }

                if ($device) {
                    // Sirve igual para deshacer una baja: el equipo dado por
                    // muerto vuelve a figurar en casa del cliente, que es donde
                    // sigue estando mientras nadie pase a recogerlo.
                    $device->status      = InventoryDevice::STATUS_INSTALLED;
                    $device->customer_id = $customerId;
                    $device->save();
                }

                $this->record($item->tenant_id, [
                    'stock_id'          => $item->stock_id,
                    'device_id'         => $item->device_id,
                    'device_serial'     => $device?->serial,
                    'type'              => InventoryMovement::TYPE_INSTALACION,
                    'quantity'          => 1,
                    'from_type'         => $backType,
                    // La chatarra no tiene custodio: un id ahí señalaría a una
                    // persona que nunca tuvo el equipo.
                    'from_id'           => $backType === InventoryMovement::HOLDER_SCRAP ? null : $backId,
                    'to_type'           => InventoryMovement::HOLDER_CUSTOMER,
                    'to_id'             => $customerId,
                    'support_ticket_id' => $item->ticket_id,
                    'customer_id'       => $customerId,
                    'notes'             => 'Se deshace el retiro registrado en el ticket: ' . $motivo,
                ], $actor);

                return $this->marcarRevertida($item, $actor, $motivo);
            }

            // Era una entrega: la existencia vuelve a quien la aportó.
            if ($device) {
                $this->placeDeviceWith($device, $backType, $backId);
            } elseif ($stock = InventoryStock::withoutTenantScope()->find($item->stock_id)) {
                $this->incrementBalance($stock, $backType, (int) $backId, (float) $item->quantity);
            }

            $this->record($item->tenant_id, [
                'stock_id'          => $item->stock_id,
                'device_id'         => $item->device_id,
                'device_serial'     => $device?->serial,
                'type'              => InventoryMovement::TYPE_DEVOLUCION,
                'quantity'          => $item->quantity,
                'from_type'         => InventoryMovement::HOLDER_CUSTOMER,
                'from_id'           => $customerId,
                'to_type'           => $backType,
                'to_id'             => $backId,
                'support_ticket_id' => $item->ticket_id,
                'customer_id'       => $customerId,
                'notes'             => 'Se deshace la entrega registrada en el ticket: ' . $motivo,
            ], $actor);

            return $this->marcarRevertida($item, $actor, $motivo);
        });
    }

    /**
     * Estampa la reversa sobre la línea. El nombre del actor se congela por lo
     * mismo que en las intervenciones: dar de baja al empleado no puede dejar
     * la corrección sin autor.
     */
    private function marcarRevertida(TicketEquipment $item, User $actor, string $motivo): TicketEquipment
    {
        $nombre = trim(($actor->user_name ?? '') . ' ' . ($actor->user_lastname ?? ''))
            ?: ($actor->name ?? null);

        $item->forceFill([
            'reversed_at'      => now(),
            'reversed_by'      => $actor->id,
            'reversed_by_name' => $nombre,
            'reversal_reason'  => $motivo,
        ])->save();

        return $item;
    }

    /**
     * Equipos que el cliente tiene instalados hoy. Es la lista de lo que se le
     * puede retirar en una visita.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, InventoryDevice>
     */
    public function devicesInstalledFor(int $customerId)
    {
        return InventoryDevice::with(['stock:id,brand,model,price,is_serialized,unit'])
            ->where('status', InventoryDevice::STATUS_INSTALLED)
            ->where('customer_id', $customerId)
            ->orderBy('id')
            ->get();
    }

    /** Deja el equipo en manos de un custodio interno (usuario o bodega). */
    private function placeDeviceWith(InventoryDevice $device, ?string $holderType, ?int $holderId): void
    {
        if ($holderType === InventoryMovement::HOLDER_USER) {
            $device->status  = InventoryDevice::STATUS_ASSIGNED;
            $device->user_id = $holderId;
        } else {
            $device->status    = InventoryDevice::STATUS_STOCK;
            $device->user_id   = null;
            $device->branch_id = $holderId;
        }

        $device->customer_id = null;
        $device->save();
    }

    /** El cliente del ticket, o un error claro si el ticket no tiene ninguno. */
    private function ticketCustomerId(SupportTicket $ticket): int
    {
        if (!$ticket->user_id) {
            throw ValidationException::withMessages([
                'ticket' => 'El ticket no tiene un cliente asociado, así que no hay a quién entregarle ni a quién retirarle equipos.',
            ]);
        }

        return (int) $ticket->user_id;
    }

    /** El técnico asignado a la visita, cualquiera que sea su forma. */
    private function assignedTechnicianId(CustomerInstallation|SupportTicket|null $context): ?int
    {
        $id = match (true) {
            $context instanceof CustomerInstallation => $context->technician_id,
            $context instanceof SupportTicket        => $context->staff_id,
            default                                  => null,
        };

        return $id === null ? null : (int) $id;
    }

    /**
     * Espejo de assertCanTakeFrom para el sentido contrario. Misma regla —lo
     * mío y lo del técnico de la visita siempre, las bodegas con permiso de
     * inventario— con el mensaje que corresponde a estar entregando y no
     * tomando.
     */
    private function assertCanHandOverTo(
        User $actor,
        ?string $holderType,
        ?int $holderId,
        CustomerInstallation|SupportTicket|null $context = null
    ): void {
        if ($this->canTakeFrom($actor, $holderType, $holderId, $context)) {
            return;
        }

        throw ValidationException::withMessages([
            'destination' => $holderType === InventoryMovement::HOLDER_USER
                ? 'No puedes dejar el equipo a nombre de otro técnico. Recíbelo tú y traspásalo desde Inventario → Entregas.'
                : 'No tienes permiso para devolver equipos a la bodega. Recíbelo a tu nombre y que lo ingrese quien administre el inventario.',
        ]);
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
        CustomerInstallation|SupportTicket|null $context = null
    ): bool {
        if ($holderType === InventoryMovement::HOLDER_USER) {
            if ((int) $holderId === (int) $actor->id) {
                return true;
            }

            $tecnico = $this->assignedTechnicianId($context);

            return $tecnico !== null && (int) $holderId === $tecnico;
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
        CustomerInstallation|SupportTicket|null $context = null
    ): void {
        if ($this->canTakeFrom($actor, $holderType, $holderId, $context)) {
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

        // El gasto automático se engancha AQUÍ y no en cada método de entrada
        // porque este es el cuello de botella: tanto el alta de un equipo
        // serializado (recordInitialEntry) como la entrada de material sin
        // origen (transferQuantity) terminan pasando por acá. Un solo punto que
        // cubre los dos, y que cubrirá al siguiente que aparezca.
        //
        // OJO: la carga masiva NO pasa por el ledger — escribe los movimientos
        // a pelo — así que tiene su propia llamada al recorder. Si algún día se
        // enruta por acá, hay que quitar aquella para no cobrar dos veces (el
        // índice único lo impediría, pero mejor no llegar a depender de eso).
        $aviso = $this->expenseRecorder->forMovement($movement);

        if ($aviso !== null) {
            $this->avisosDeGasto[] = $aviso;
        }

        return $movement;
    }

    /**
     * Avisos acumulados de gastos que no se pudieron crear (material sin precio
     * de catálogo). Los consume el controlador para mostrárselos al usuario:
     * callarlos dejaría el balance descuadrado sin que nadie se entere.
     *
     * @return array<int, string>
     */
    public function avisosDeGasto(): array
    {
        return $this->avisosDeGasto;
    }

    private function deviceName(InventoryDevice $device): string
    {
        $name = $device->stock?->label() ?? 'Equipo';

        return $device->serial ? "{$name} (S/N {$device->serial})" : $name;
    }
}
