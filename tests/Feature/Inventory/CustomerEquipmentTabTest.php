<?php

namespace Tests\Feature\Inventory;

use App\Models\CustomerInstallation;
use App\Models\InstallationEquipment;
use App\Models\InventoryDevice;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\TicketEquipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pestaña «Equipos» de la ficha del cliente: GET /api/customers/{id}/equipment.
 * Lo que tiene en casa, los materiales gastados en sus órdenes y tickets, y lo
 * que se le retiró.
 */
class CustomerEquipmentTabTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $customer;
    private CustomerInstallation $installation;
    private SupportTicket $ticket;
    private InventoryStock $router;
    private InventoryStock $cable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $role = Role::create(['name' => 'Admin', 'permissions' => ['*'], 'tenant_id' => $this->tenant->id]);
        $this->admin    = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $role->id]);
        $this->customer = User::factory()->create(['tenant_id' => $this->tenant->id]);

        Sanctum::actingAs($this->admin);

        $this->installation = CustomerInstallation::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'completada',
        ]);
        $this->ticket = SupportTicket::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->customer->id,
            'subject' => 'Cambio de router', 'status' => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);

        $this->router = InventoryStock::create(['brand' => 'MIKROTIK', 'model' => 'LDF 5', 'price' => 150000, 'is_serialized' => true]);
        $this->cable  = InventoryStock::create(['brand' => 'GENÉRICO', 'model' => 'UTP CAT5E', 'price' => 1000, 'is_serialized' => false, 'unit' => 'metro']);
    }

    private function installedDevice(string $serial, ?int $customerId = null): InventoryDevice
    {
        $device = InventoryDevice::create([
            'stock_id' => $this->router->id, 'serial' => $serial, 'mac' => 'AA:BB:CC:' . strtoupper(substr(md5($serial), 0, 2)) . ':00:01',
            'status' => InventoryDevice::STATUS_INSTALLED, 'customer_id' => $customerId ?? $this->customer->id,
        ]);

        InventoryMovement::create([
            'stock_id' => $this->router->id, 'device_id' => $device->id, 'device_serial' => $serial,
            'type' => InventoryMovement::TYPE_INSTALACION, 'quantity' => 1,
            'from_type' => 'user', 'from_id' => $this->admin->id,
            'to_type' => 'customer', 'to_id' => null, // como queda una orden de prospecto (P-74)
            'installation_id' => $this->installation->id, 'created_at' => now(),
        ]);

        return $device;
    }

    #[Test]
    public function it_lists_installed_devices_materials_and_retired_devices(): void
    {
        $this->installedDevice('HJ80A5E3779');
        $this->installedDevice('OTRO-CLIENTE', User::factory()->create(['tenant_id' => $this->tenant->id])->id);

        // 30 m en la orden + 12,5 m en el ticket = 42,5 m de cable.
        InstallationEquipment::create([
            'installation_id' => $this->installation->id, 'stock_id' => $this->cable->id, 'quantity' => 30,
        ]);
        TicketEquipment::create([
            'ticket_id' => $this->ticket->id, 'stock_id' => $this->cable->id,
            'direction' => TicketEquipment::DIRECTION_OUT, 'quantity' => 12.5,
        ]);
        // Una línea revertida no cuenta.
        TicketEquipment::create([
            'ticket_id' => $this->ticket->id, 'stock_id' => $this->cable->id,
            'direction' => TicketEquipment::DIRECTION_OUT, 'quantity' => 99, 'reversed_at' => now(),
        ]);

        // Router viejo retirado en el ticket.
        $viejo = InventoryDevice::create([
            'stock_id' => $this->router->id, 'serial' => 'VIEJO-1', 'status' => InventoryDevice::STATUS_RETIRED,
        ]);
        TicketEquipment::create([
            'ticket_id' => $this->ticket->id, 'stock_id' => $this->router->id, 'device_id' => $viejo->id,
            'direction' => TicketEquipment::DIRECTION_IN, 'quantity' => 1,
        ]);

        $res = $this->getJson("/api/customers/{$this->customer->id}/equipment")->assertOk();

        $res->assertJsonCount(1, 'devices')
            ->assertJsonPath('devices.0.serial', 'HJ80A5E3779')
            ->assertJsonPath('devices.0.item', 'MIKROTIK LDF 5')
            ->assertJsonPath('devices.0.origin.type', 'installation')
            ->assertJsonPath('devices.0.origin.id', $this->installation->id);

        $res->assertJsonCount(1, 'materials')
            ->assertJsonPath('materials.0.item', 'GENÉRICO UTP CAT5E')
            ->assertJsonPath('materials.0.unit', 'metro')
            ->assertJsonCount(2, 'materials.0.lines');
        $this->assertEquals(42.5, $res->json('materials.0.total'));

        $res->assertJsonCount(1, 'retired')
            ->assertJsonPath('retired.0.serial', 'VIEJO-1')
            ->assertJsonPath('retired.0.status', 'retired')
            ->assertJsonPath('retired.0.origin.type', 'ticket');
    }

    #[Test]
    public function a_customer_without_equipment_gets_empty_lists(): void
    {
        $otro = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->getJson("/api/customers/{$otro->id}/equipment")
            ->assertOk()
            ->assertExactJson(['devices' => [], 'materials' => [], 'retired' => []]);
    }

    #[Test]
    public function another_tenants_customer_is_not_found(): void
    {
        $ajeno = User::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);

        $this->getJson("/api/customers/{$ajeno->id}/equipment")->assertNotFound();
    }

    #[Test]
    public function it_requires_a_permission_to_see_the_customer(): void
    {
        $role  = Role::create(['name' => 'Sin permisos', 'permissions' => [], 'tenant_id' => $this->tenant->id]);
        $nadie = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $role->id]);
        Sanctum::actingAs($nadie);

        $this->getJson("/api/customers/{$this->customer->id}/equipment")->assertForbidden();

        $role->update(['permissions' => ['view_support']]);
        Sanctum::actingAs($nadie->fresh());
        $this->getJson("/api/customers/{$this->customer->id}/equipment")->assertOk();
    }
}
