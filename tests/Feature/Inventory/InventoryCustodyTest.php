<?php

namespace Tests\Feature\Inventory;

use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\InstallationEquipment;
use App\Models\InventoryBalance;
use App\Models\InventoryBranch;
use App\Models\InventoryDevice;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Custodia del inventario y kardex.
 *
 * Lo que se prueba aquí es la regla de negocio que motivó el módulo: un técnico
 * sólo puede instalar lo que tiene encima, cada movimiento queda escrito, y los
 * consumibles se descuentan por cantidad en vez de por fila. Sin estas pruebas
 * el sistema volvería en silencio a "cualquiera usa cualquier equipo".
 */
class InventoryCustodyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $technician;
    private User $customer;
    private InventoryBranch $branch;
    private CustomerInstallation $installation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $adminRole = Role::create(['name' => 'Admin', 'permissions' => ['*'], 'tenant_id' => $this->tenant->id]);
        // El técnico ve soporte pero NO administra inventario: es justo el rol
        // que no debe poder tomar de la bodega.
        $techRole = Role::create([
            'name'        => 'Técnico',
            'code'        => 'technician',
            'permissions' => ['view_support'],
            'tenant_id'   => $this->tenant->id,
        ]);

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $adminRole->id,
        ]);
        $this->technician = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $techRole->id,
        ]);

        $this->customer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create([
            'user_id' => $this->customer->id, 'name' => 'Ana', 'last_name' => 'Ruiz', 'status' => true,
        ]);

        Sanctum::actingAs($this->admin);

        $this->branch = InventoryBranch::create(['name' => 'Bodega Principal']);

        $this->installation = CustomerInstallation::create([
            'tenant_id'      => $this->tenant->id,
            'customer_id'    => $this->customer->id,
            'technician_id'  => $this->technician->id,
            'scheduled_date' => now()->toDateString(),
            'status'         => 'pendiente',
        ]);
    }

    private function serializedStock(string $brand = 'MIKROTIK', string $model = 'LDF'): InventoryStock
    {
        return InventoryStock::create([
            'brand' => $brand, 'model' => $model, 'price' => 150000, 'is_serialized' => true,
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

    #[Test]
    public function a_technician_only_sees_the_equipment_in_his_custody(): void
    {
        $stock = $this->serializedStock();
        $mine  = $this->deviceHeldBy($stock, $this->technician, 'SN-MIA');
        $other = $this->deviceHeldBy($stock, User::factory()->create(['tenant_id' => $this->tenant->id]), 'SN-AJENA');
        $shelf = $this->deviceHeldBy($stock, null, 'SN-BODEGA');

        Sanctum::actingAs($this->technician);

        $response = $this->getJson("/api/installations/{$this->installation->id}/equipment/available")
            ->assertOk();

        $ids = collect($response->json('devices'))->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($other->id, $ids, 'Un técnico no debe ver los equipos de otro.');
        $this->assertNotContains($shelf->id, $ids, 'Sin permiso de inventario no se ve la bodega.');
    }

    #[Test]
    public function an_inventory_manager_also_sees_the_warehouse(): void
    {
        $stock = $this->serializedStock();
        $shelf = $this->deviceHeldBy($stock, null, 'SN-BODEGA');

        $response = $this->getJson("/api/installations/{$this->installation->id}/equipment/available")
            ->assertOk();

        $this->assertContains($shelf->id, collect($response->json('devices'))->pluck('id')->all());
    }

    #[Test]
    public function taking_another_technicians_device_is_rejected(): void
    {
        $stock   = $this->serializedStock();
        $someone = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $device  = $this->deviceHeldBy($stock, $someone, 'SN-AJENA');

        Sanctum::actingAs($this->technician);

        $this->postJson("/api/installations/{$this->installation->id}/equipment", [
            'device_id' => $device->id,
        ])->assertStatus(422);

        // El equipo sigue donde estaba y no se inventó ningún movimiento.
        $this->assertSame(InventoryDevice::STATUS_ASSIGNED, $device->fresh()->status);
        $this->assertSame((int) $someone->id, (int) $device->fresh()->user_id);
        $this->assertSame(0, InventoryMovement::withoutTenantScope()->where('type', 'instalacion')->count());
    }

    #[Test]
    public function installing_a_device_moves_custody_to_the_customer_and_writes_the_ledger(): void
    {
        $stock  = $this->serializedStock();
        $device = $this->deviceHeldBy($stock, $this->technician, 'SN-MIA');

        Sanctum::actingAs($this->technician);

        $this->postJson("/api/installations/{$this->installation->id}/equipment", [
            'device_id' => $device->id,
        ])->assertCreated();

        $device->refresh();
        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $device->status);
        $this->assertSame((int) $this->customer->id, (int) $device->customer_id);

        $movement = InventoryMovement::withoutTenantScope()->where('type', 'instalacion')->firstOrFail();
        $this->assertSame((int) $device->id, (int) $movement->device_id);
        $this->assertSame('user', $movement->from_type);
        $this->assertSame((int) $this->technician->id, (int) $movement->from_id);
        $this->assertSame('customer', $movement->to_type);
        $this->assertSame((int) $this->installation->id, (int) $movement->installation_id);
        // El serial se copia para que la traza sobreviva al borrado del equipo.
        $this->assertSame('SN-MIA', $movement->device_serial);
    }

    #[Test]
    public function the_same_device_cannot_be_installed_twice(): void
    {
        $stock  = $this->serializedStock();
        $device = $this->deviceHeldBy($stock, $this->technician, 'SN-UNICA');

        Sanctum::actingAs($this->technician);

        $this->postJson("/api/installations/{$this->installation->id}/equipment", ['device_id' => $device->id])
            ->assertCreated();

        $other = CustomerInstallation::create([
            'tenant_id'      => $this->tenant->id,
            'customer_id'    => $this->customer->id,
            'technician_id'  => $this->technician->id,
            'scheduled_date' => now()->toDateString(),
            'status'         => 'pendiente',
        ]);

        $this->postJson("/api/installations/{$other->id}/equipment", ['device_id' => $device->id])
            ->assertStatus(422);

        $this->assertSame(1, InstallationEquipment::withoutTenantScope()->count());
    }

    #[Test]
    public function consumables_are_discounted_by_quantity(): void
    {
        $stock = $this->consumableStock();

        // 50 RJ45 en poder del técnico.
        InventoryBalance::create([
            'stock_id'    => $stock->id,
            'holder_type' => 'user',
            'holder_id'   => $this->technician->id,
            'quantity'    => 50,
        ]);

        Sanctum::actingAs($this->technician);

        $this->postJson("/api/installations/{$this->installation->id}/equipment", [
            'stock_id'    => $stock->id,
            'quantity'    => 4,
            'source_type' => 'user',
            'source_id'   => $this->technician->id,
        ])->assertCreated();

        $balance = InventoryBalance::withoutTenantScope()
            ->where('stock_id', $stock->id)->where('holder_id', $this->technician->id)->firstOrFail();

        $this->assertEquals(46, (float) $balance->quantity);
    }

    #[Test]
    public function using_more_material_than_available_is_rejected(): void
    {
        $stock = $this->consumableStock();

        InventoryBalance::create([
            'stock_id'    => $stock->id,
            'holder_type' => 'user',
            'holder_id'   => $this->technician->id,
            'quantity'    => 3,
        ]);

        Sanctum::actingAs($this->technician);

        $this->postJson("/api/installations/{$this->installation->id}/equipment", [
            'stock_id'    => $stock->id,
            'quantity'    => 10,
            'source_type' => 'user',
            'source_id'   => $this->technician->id,
        ])->assertStatus(422);

        $balance = InventoryBalance::withoutTenantScope()->where('stock_id', $stock->id)->firstOrFail();
        $this->assertEquals(3, (float) $balance->quantity, 'Un rechazo no puede dejar el saldo tocado.');
    }

    #[Test]
    public function removing_a_line_returns_the_stock_to_whoever_provided_it(): void
    {
        $stock  = $this->serializedStock();
        $device = $this->deviceHeldBy($stock, $this->technician, 'SN-DEVUELTA');

        Sanctum::actingAs($this->technician);

        $created = $this->postJson("/api/installations/{$this->installation->id}/equipment", [
            'device_id' => $device->id,
        ])->assertCreated();

        $itemId = $created->json('item.id');

        $this->deleteJson("/api/installations/{$this->installation->id}/equipment/{$itemId}")->assertOk();

        $device->refresh();
        $this->assertSame(InventoryDevice::STATUS_ASSIGNED, $device->status);
        $this->assertSame((int) $this->technician->id, (int) $device->user_id);
        $this->assertNull($device->customer_id);

        $this->assertSame(1, InventoryMovement::withoutTenantScope()->where('type', 'devolucion')->count());
        $this->assertSame(0, InstallationEquipment::withoutTenantScope()->count());
    }

    #[Test]
    public function a_transfer_hands_the_device_over_and_leaves_a_trace(): void
    {
        $stock  = $this->serializedStock();
        $device = $this->deviceHeldBy($stock, null, 'SN-BODEGA');

        $this->postJson('/api/inventory/transfers', [
            'to_type'    => 'user',
            'to_id'      => $this->technician->id,
            'device_ids' => [$device->id],
            'notes'      => 'Entrega semanal',
        ])->assertCreated();

        $device->refresh();
        $this->assertSame(InventoryDevice::STATUS_ASSIGNED, $device->status);
        $this->assertSame((int) $this->technician->id, (int) $device->user_id);

        $movement = InventoryMovement::withoutTenantScope()->where('type', 'traspaso')->firstOrFail();
        $this->assertSame('branch', $movement->from_type);
        $this->assertSame((int) $this->branch->id, (int) $movement->from_id);
        $this->assertSame('user', $movement->to_type);
        $this->assertSame('Entrega semanal', $movement->notes);
    }

    #[Test]
    public function a_material_entry_without_origin_creates_the_balance(): void
    {
        $stock = $this->consumableStock();

        $this->postJson('/api/inventory/transfers', [
            'to_type'   => 'user',
            'to_id'     => $this->technician->id,
            'materials' => [['stock_id' => $stock->id, 'quantity' => 100]],
        ])->assertCreated();

        $balance = InventoryBalance::withoutTenantScope()->firstOrFail();
        $this->assertEquals(100, (float) $balance->quantity);
        $this->assertSame('user', $balance->holder_type);

        $movement = InventoryMovement::withoutTenantScope()->firstOrFail();
        $this->assertSame('entrada', $movement->type);
        $this->assertSame('supplier', $movement->from_type);
    }

    #[Test]
    public function an_installed_device_cannot_be_transferred_away(): void
    {
        $stock  = $this->serializedStock();
        $device = $this->deviceHeldBy($stock, $this->technician, 'SN-INSTALADA');

        Sanctum::actingAs($this->technician);
        $this->postJson("/api/installations/{$this->installation->id}/equipment", ['device_id' => $device->id])
            ->assertCreated();

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/inventory/transfers', [
            'to_type'    => 'user',
            'to_id'      => $this->admin->id,
            'device_ids' => [$device->id],
        ])->assertStatus(422);

        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $device->fresh()->status);
    }

    #[Test]
    public function the_ledger_lists_both_sides_of_a_holders_movements(): void
    {
        $stock  = $this->serializedStock();
        $device = $this->deviceHeldBy($stock, null, 'SN-KARDEX');

        $this->postJson('/api/inventory/transfers', [
            'to_type'    => 'user',
            'to_id'      => $this->technician->id,
            'device_ids' => [$device->id],
        ])->assertCreated();

        $response = $this->getJson('/api/inventory/movements?holder_type=user&holder_id=' . $this->technician->id)
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('traspaso', $response->json('data.0.type'));
        $this->assertSame('SN-KARDEX', $response->json('data.0.serial'));
    }
}
