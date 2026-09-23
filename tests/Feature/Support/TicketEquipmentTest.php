<?php

namespace Tests\Feature\Support;

use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\InstallationEquipment;
use App\Models\InventoryBalance;
use App\Models\InventoryBranch;
use App\Models\InventoryDevice;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\SupportTicketHistory;
use App\Models\Tenant;
use App\Models\TicketEquipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Equipos movidos en una visita de soporte.
 *
 * El caso que motivó el módulo es el cambio de router: el técnico llega, deja
 * uno nuevo y se lleva el viejo. Antes de esto el sistema sólo sabía sacar
 * equipos por una orden de instalación, así que la entrega se cobraba a mano y
 * el aparato seguía figurando disponible en la bodega para siempre — y el que
 * se retiraba no salía nunca de la casa del cliente.
 */
class TicketEquipmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $technician;
    private User $customer;
    private InventoryBranch $branch;
    private SupportTicket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $adminRole = Role::create([
            'name' => 'Admin', 'code' => 'admin', 'permissions' => ['*'], 'tenant_id' => $this->tenant->id,
        ]);
        // El técnico de campo: ve soporte pero NO administra inventario. Es el
        // rol que tiene que poder usar esto y el que no debe tocar la bodega.
        $techRole = Role::create([
            'name'        => 'Técnico',
            'code'        => 'technician',
            'permissions' => ['view_support'],
            'tenant_id'   => $this->tenant->id,
        ]);

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role_id' => $adminRole->id,
        ]);
        $this->technician = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role_id' => $techRole->id,
        ]);

        $this->customer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create([
            'user_id' => $this->customer->id, 'name' => 'Ana', 'last_name' => 'Ruiz', 'status' => true,
        ]);

        Sanctum::actingAs($this->admin);

        $this->branch = InventoryBranch::create(['name' => 'Bodega Principal']);

        $this->ticket = SupportTicket::create([
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->customer->id,
            'staff_id'  => $this->technician->id,
            'subject'   => 'Se quemó el router con el rayo del domingo',
            'status'    => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);
    }

    private function serializedStock(string $model = 'LDF'): InventoryStock
    {
        return InventoryStock::create([
            'brand' => 'MIKROTIK', 'model' => $model, 'price' => 150000, 'is_serialized' => true,
        ]);
    }

    private function consumableStock(): InventoryStock
    {
        return InventoryStock::create([
            'brand' => 'GENÉRICO', 'model' => 'RJ45 CAT5E', 'price' => 500,
            'is_serialized' => false, 'unit' => 'unidad',
        ]);
    }

    private function deviceHeldBy(InventoryStock $stock, ?User $holder, string $serial): InventoryDevice
    {
        return InventoryDevice::create([
            'stock_id'  => $stock->id,
            'serial'    => $serial,
            'user_id'   => $holder?->id,
            'branch_id' => $holder ? null : $this->branch->id,
            'status'    => $holder ? InventoryDevice::STATUS_ASSIGNED : InventoryDevice::STATUS_STOCK,
        ]);
    }

    private function deviceInstalledAt(InventoryStock $stock, User $customer, string $serial): InventoryDevice
    {
        return InventoryDevice::create([
            'stock_id'    => $stock->id,
            'serial'      => $serial,
            'customer_id' => $customer->id,
            'status'      => InventoryDevice::STATUS_INSTALLED,
        ]);
    }

    #[Test]
    public function a_technician_delivers_a_device_from_the_ticket(): void
    {
        $device = $this->deviceHeldBy($this->serializedStock(), $this->technician, 'SN-NUEVO');

        Sanctum::actingAs($this->technician);

        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'device_id' => $device->id,
        ])->assertCreated()
          ->assertJsonPath('item.is_return', false)
          // El precio del catálogo viaja en la respuesta: es lo que permite
          // precargar el cargo del ticket sin volver a teclearlo.
          ->assertJsonPath('item.unit_price', fn ($precio) => (float) $precio === 150000.0);

        $device->refresh();
        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $device->status);
        $this->assertSame((int) $this->customer->id, (int) $device->customer_id);

        $movimiento = InventoryMovement::withoutTenantScope()
            ->where('type', InventoryMovement::TYPE_INSTALACION)
            ->firstOrFail();

        $this->assertSame((int) $this->ticket->id, (int) $movimiento->support_ticket_id);
        $this->assertSame((int) $this->customer->id, (int) $movimiento->customer_id);
        $this->assertSame(InventoryMovement::HOLDER_CUSTOMER, $movimiento->to_type);
    }

    #[Test]
    public function delivering_a_device_is_written_in_the_ticket_history(): void
    {
        $device = $this->deviceHeldBy($this->serializedStock(), $this->admin, 'SN-NUEVO');

        $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $device->id])
            ->assertCreated();

        $evento = SupportTicketHistory::withoutTenantScope()
            ->where('event_type', SupportTicketHistory::EQUIPMENT_ADDED)
            ->firstOrFail();

        $this->assertSame('out', $evento->metadata['direction']);
        $this->assertStringContainsString('SN-NUEVO', $evento->metadata['label']);
    }

    #[Test]
    public function the_old_device_is_retrieved_from_the_customer(): void
    {
        $viejo = $this->deviceInstalledAt($this->serializedStock('RB941'), $this->customer, 'SN-VIEJO');

        Sanctum::actingAs($this->technician);

        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'direction'   => 'in',
            'device_id'   => $viejo->id,
            'source_type' => 'user',
            'source_id'   => $this->technician->id,
        ])->assertCreated()
          ->assertJsonPath('item.is_return', true)
          // Un retiro no se cobra: sin precio no hay nada que arrastrar al cargo.
          ->assertJsonPath('item.unit_price', null);

        $viejo->refresh();
        $this->assertSame(InventoryDevice::STATUS_ASSIGNED, $viejo->status);
        $this->assertSame((int) $this->technician->id, (int) $viejo->user_id);
        $this->assertNull($viejo->customer_id, 'El equipo retirado ya no está en casa del cliente.');

        $movimiento = InventoryMovement::withoutTenantScope()
            ->where('type', InventoryMovement::TYPE_DEVOLUCION)
            ->firstOrFail();

        $this->assertSame(InventoryMovement::HOLDER_CUSTOMER, $movimiento->from_type);
        $this->assertSame((int) $this->ticket->id, (int) $movimiento->support_ticket_id);
    }

    #[Test]
    public function a_device_installed_at_another_customer_cannot_be_retrieved(): void
    {
        $otro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $ajeno = $this->deviceInstalledAt($this->serializedStock(), $otro, 'SN-AJENO');

        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'direction'   => 'in',
            'device_id'   => $ajeno->id,
            'source_type' => 'branch',
            'source_id'   => $this->branch->id,
        ])->assertStatus(422);

        $ajeno->refresh();
        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $ajeno->status);
        $this->assertSame((int) $otro->id, (int) $ajeno->customer_id);
    }

    #[Test]
    public function the_available_list_offers_what_the_customer_already_has(): void
    {
        $instalado = $this->deviceInstalledAt($this->serializedStock('RB941'), $this->customer, 'SN-VIEJO');
        $otro      = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $ajeno     = $this->deviceInstalledAt($this->serializedStock(), $otro, 'SN-AJENO');

        $respuesta = $this->getJson("/api/support/{$this->ticket->id}/equipment/available")->assertOk();

        $ids = collect($respuesta->json('installed'))->pluck('id')->all();

        $this->assertContains($instalado->id, $ids);
        $this->assertNotContains($ajeno->id, $ids, 'Sólo se retiran equipos del cliente de este ticket.');
    }

    #[Test]
    public function a_technician_cannot_take_another_technicians_device(): void
    {
        $alguien = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $device  = $this->deviceHeldBy($this->serializedStock(), $alguien, 'SN-AJENA');

        Sanctum::actingAs($this->technician);

        $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $device->id])
            ->assertStatus(422);

        $this->assertSame(InventoryDevice::STATUS_ASSIGNED, $device->fresh()->status);
        $this->assertSame(0, TicketEquipment::withoutTenantScope()->count());
    }

    #[Test]
    public function a_technician_cannot_reach_the_warehouse_without_inventory_permission(): void
    {
        $enBodega = $this->deviceHeldBy($this->serializedStock(), null, 'SN-BODEGA');

        Sanctum::actingAs($this->technician);

        $respuesta = $this->getJson("/api/support/{$this->ticket->id}/equipment/available")->assertOk();
        $this->assertNotContains(
            $enBodega->id,
            collect($respuesta->json('devices'))->pluck('id')->all()
        );

        $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $enBodega->id])
            ->assertStatus(422);
    }

    #[Test]
    public function consumables_are_discounted_by_quantity(): void
    {
        $stock = $this->consumableStock();
        InventoryBalance::create([
            'tenant_id'   => $this->tenant->id,
            'stock_id'    => $stock->id,
            'holder_type' => InventoryMovement::HOLDER_USER,
            'holder_id'   => $this->technician->id,
            'quantity'    => 10,
        ]);

        Sanctum::actingAs($this->technician);

        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'stock_id'    => $stock->id,
            'quantity'    => 4,
            'source_type' => 'user',
            'source_id'   => $this->technician->id,
        ])->assertCreated();

        $saldo = InventoryBalance::withoutTenantScope()
            ->where('stock_id', $stock->id)
            ->where('holder_id', $this->technician->id)
            ->firstOrFail();

        $this->assertEquals(6, (float) $saldo->quantity);
    }

    #[Test]
    public function undoing_a_delivery_returns_the_device_to_its_holder(): void
    {
        $device = $this->deviceHeldBy($this->serializedStock(), $this->technician, 'SN-NUEVO');

        Sanctum::actingAs($this->technician);

        $creado = $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $device->id])
            ->assertCreated();

        $itemId = $creado->json('item.id');

        $this->deleteJson("/api/support/{$this->ticket->id}/equipment/{$itemId}")->assertOk();

        $device->refresh();
        $this->assertSame(InventoryDevice::STATUS_ASSIGNED, $device->status);
        $this->assertSame((int) $this->technician->id, (int) $device->user_id);
        $this->assertNull($device->customer_id);
        $this->assertSame(0, TicketEquipment::withoutTenantScope()->count());
    }

    #[Test]
    public function undoing_a_retrieval_puts_the_device_back_at_the_customer(): void
    {
        $viejo = $this->deviceInstalledAt($this->serializedStock('RB941'), $this->customer, 'SN-VIEJO');

        Sanctum::actingAs($this->technician);

        $creado = $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'direction'   => 'in',
            'device_id'   => $viejo->id,
            'source_type' => 'user',
            'source_id'   => $this->technician->id,
        ])->assertCreated();

        $this->deleteJson("/api/support/{$this->ticket->id}/equipment/" . $creado->json('item.id'))
            ->assertOk();

        $viejo->refresh();
        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $viejo->status);
        $this->assertSame((int) $this->customer->id, (int) $viejo->customer_id);
    }

    /**
     * El bug que hizo falta destapar para que el retiro sirviera de algo:
     * `installation_equipment.device_id` era ÚNICO, así que un equipo que
     * volvía de casa de un cliente no se podía instalar nunca más en otro. El
     * INSERT de la segunda instalación reventaba y nadie entendía por qué.
     */
    #[Test]
    public function a_retrieved_device_can_be_installed_at_another_customer(): void
    {
        $stock = $this->serializedStock();
        $viejo = $this->deviceInstalledAt($stock, $this->customer, 'SN-REUSO');

        // La instalación por la que llegó a casa del primer cliente.
        $primera = CustomerInstallation::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'completada',
        ]);
        InstallationEquipment::create([
            'tenant_id' => $this->tenant->id, 'installation_id' => $primera->id,
            'stock_id' => $stock->id, 'device_id' => $viejo->id, 'quantity' => 1,
        ]);

        // Se retira en el ticket y vuelve a la bodega.
        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'direction'   => 'in',
            'device_id'   => $viejo->id,
            'source_type' => 'branch',
            'source_id'   => $this->branch->id,
        ])->assertCreated();

        // Y se instala en otro cliente, con su propia hoja.
        $otroCliente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $segunda = CustomerInstallation::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $otroCliente->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'pendiente',
        ]);

        $this->postJson("/api/installations/{$segunda->id}/equipment", ['device_id' => $viejo->id])
            ->assertCreated();

        $viejo->refresh();
        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $viejo->status);
        $this->assertSame((int) $otroCliente->id, (int) $viejo->customer_id);

        // La hoja vieja sigue contando lo que pasó ese día: el retiro no borra
        // la historia de la visita en la que el equipo se entregó.
        $this->assertSame(
            2,
            InstallationEquipment::withoutTenantScope()->where('device_id', $viejo->id)->count()
        );
    }

    #[Test]
    public function an_archived_ticket_does_not_move_inventory(): void
    {
        $device = $this->deviceHeldBy($this->serializedStock(), $this->admin, 'SN-NUEVO');

        $this->ticket->delete();

        $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $device->id])
            ->assertStatus(422);

        $this->assertSame(InventoryDevice::STATUS_ASSIGNED, $device->fresh()->status);

        // Pero el expediente archivado sigue siendo consultable.
        $this->getJson("/api/support/{$this->ticket->id}/equipment")->assertOk();
    }

    /**
     * El router que vuelve quemado no es una devolución: si entrara a bodega
     * como disponible, alguien lo prometería en la siguiente instalación.
     * (Caso recogido de la rama KAN-92, que sí lo contemplaba.)
     */
    #[Test]
    public function a_dead_device_is_retrieved_and_scrapped(): void
    {
        $quemado = $this->deviceInstalledAt($this->serializedStock('RB941'), $this->customer, 'SN-QUEMADO');

        Sanctum::actingAs($this->technician);

        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'direction'   => 'in',
            'device_id'   => $quemado->id,
            'source_type' => 'scrap',
            'notes'       => 'Le entró el rayo por la línea.',
        ])->assertCreated()
          ->assertJsonPath('item.is_return', true)
          ->assertJsonPath('item.is_scrapped', true);

        $quemado->refresh();
        $this->assertSame(InventoryDevice::STATUS_RETIRED, $quemado->status);
        $this->assertNull($quemado->customer_id);
        $this->assertNull($quemado->user_id, 'Un equipo de baja no queda a nombre de nadie.');

        $movimiento = InventoryMovement::withoutTenantScope()
            ->where('type', InventoryMovement::TYPE_BAJA)
            ->firstOrFail();

        $this->assertSame(InventoryMovement::HOLDER_CUSTOMER, $movimiento->from_type);
        $this->assertSame((int) $this->ticket->id, (int) $movimiento->support_ticket_id);

        // Y deja de estar disponible para cualquier otra visita.
        $disponibles = $this->getJson("/api/support/{$this->ticket->id}/equipment/available")->assertOk();
        $this->assertNotContains(
            $quemado->id,
            collect($disponibles->json('devices'))->pluck('id')->all()
        );
    }

    #[Test]
    public function undoing_a_scrap_puts_the_device_back_at_the_customer(): void
    {
        $quemado = $this->deviceInstalledAt($this->serializedStock('RB941'), $this->customer, 'SN-QUEMADO');

        $creado = $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'direction'   => 'in',
            'device_id'   => $quemado->id,
            'source_type' => 'scrap',
        ])->assertCreated();

        $this->deleteJson("/api/support/{$this->ticket->id}/equipment/" . $creado->json('item.id'))
            ->assertOk();

        $quemado->refresh();
        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $quemado->status);
        $this->assertSame((int) $this->customer->id, (int) $quemado->customer_id);
    }

    #[Test]
    public function scrap_is_not_a_valid_source_for_a_delivery(): void
    {
        $device = $this->deviceHeldBy($this->serializedStock(), $this->admin, 'SN-NUEVO');

        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'device_id'   => $device->id,
            'source_type' => 'scrap',
        ])->assertStatus(422);

        $this->assertSame(InventoryDevice::STATUS_ASSIGNED, $device->fresh()->status);
    }

    #[Test]
    public function a_device_already_installed_elsewhere_cannot_be_delivered(): void
    {
        $otro   = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $device = $this->deviceInstalledAt($this->serializedStock(), $otro, 'SN-OCUPADO');

        $this->postJson("/api/support/{$this->ticket->id}/equipment", ['device_id' => $device->id])
            ->assertStatus(422);

        $this->assertSame((int) $otro->id, (int) $device->fresh()->customer_id);
    }
}
