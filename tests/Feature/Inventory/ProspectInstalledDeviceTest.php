<?php

namespace Tests\Feature\Inventory;

use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\InventoryDevice;
use App\Models\InventoryStock;
use App\Models\Prospect;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Equipo instalado en la orden de un PROSPECTO (P-74).
 *
 * El caso real: un MikroTik registrado en la orden de un prospecto salía en el
 * inventario como «Instalado · cliente», sin nombre, y al convertir al
 * prospecto el equipo seguía con customer_id en null — no aparecía en la ficha
 * del cliente ni se podía retirar en un ticket.
 */
class ProspectInstalledDeviceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $technician;
    private Prospect $prospect;
    private CustomerInstallation $installation;
    private InventoryStock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $adminRole = Role::create(['name' => 'Admin', 'permissions' => ['*'], 'tenant_id' => $this->tenant->id]);
        $techRole  = Role::create([
            'name' => 'Técnico', 'code' => 'technician', 'permissions' => ['view_support'], 'tenant_id' => $this->tenant->id,
        ]);

        $this->admin      = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $adminRole->id]);
        $this->technician = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $techRole->id]);

        Sanctum::actingAs($this->admin);

        $this->prospect = Prospect::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'JAVIER MANUEL',
            'last_name' => 'GUTIERREZ GARCIA',
            'status'    => 'agendado',
        ]);

        $this->installation = CustomerInstallation::create([
            'tenant_id'      => $this->tenant->id,
            'customer_id'    => null,
            'prospect_id'    => $this->prospect->id,
            'technician_id'  => $this->technician->id,
            'scheduled_date' => now()->toDateString(),
            'status'         => 'pendiente',
        ]);

        $this->stock = InventoryStock::create([
            'brand' => 'MIKROTIK', 'model' => 'LDF 5', 'price' => 150000, 'is_serialized' => true,
        ]);
    }

    private function installOnProspectOrder(string $serial = 'HJ80A5E3779'): InventoryDevice
    {
        $device = InventoryDevice::create([
            'stock_id' => $this->stock->id,
            'serial'   => $serial,
            'user_id'  => $this->technician->id,
            'status'   => InventoryDevice::STATUS_ASSIGNED,
        ]);

        Sanctum::actingAs($this->technician);
        $this->postJson("/api/installations/{$this->installation->id}/equipment", [
            'device_id' => $device->id,
        ])->assertCreated();
        Sanctum::actingAs($this->admin);

        return $device->fresh();
    }

    private function convertProspect(): User
    {
        $customer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create([
            'user_id' => $customer->id, 'name' => 'Javier', 'last_name' => 'Gutiérrez', 'status' => true,
        ]);

        $this->postJson("/api/prospects/{$this->prospect->id}/mark-converted", [
            'user_id' => $customer->id,
        ])->assertOk();

        return $customer;
    }

    #[Test]
    public function the_inventory_list_names_the_prospect_instead_of_an_anonymous_customer(): void
    {
        $device = $this->installOnProspectOrder();

        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $device->status);
        $this->assertNull($device->customer_id);

        $row = collect($this->getJson('/api/inventory')->assertOk()->json())
            ->firstWhere('id', $device->id);

        $this->assertSame(
            "Instalado · Prospecto: JAVIER MANUEL GUTIERREZ GARCIA (orden #{$this->installation->id})",
            $row['holder_label']
        );
        $this->assertSame('prospect', $row['installed_for']['type']);
        $this->assertSame($this->prospect->id, $row['installed_for']['id']);
        $this->assertSame($this->installation->id, $row['installed_for']['installation_id']);
        $this->assertArrayNotHasKey('latest_installation_line', $row);
    }

    #[Test]
    public function the_ledger_names_the_prospect_on_the_installation_movement(): void
    {
        $device = $this->installOnProspectOrder();

        $mov = collect($this->getJson("/api/inventory/movements?device_id={$device->id}")->assertOk()->json('data'))
            ->firstWhere('type', 'instalacion');

        $this->assertSame(
            "Prospecto: JAVIER MANUEL GUTIERREZ GARCIA (orden #{$this->installation->id})",
            $mov['to']
        );
    }

    #[Test]
    public function converting_the_prospect_hands_the_device_to_the_new_customer(): void
    {
        $device   = $this->installOnProspectOrder();
        $customer = $this->convertProspect();

        $device->refresh();
        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $device->status);
        $this->assertSame((int) $customer->id, (int) $device->customer_id);

        $row = collect($this->getJson('/api/inventory')->json())->firstWhere('id', $device->id);
        $this->assertSame('customer', $row['installed_for']['type']);
        $this->assertStringStartsWith('Instalado · Cliente: ', $row['holder_label']);

        // El kardex no se reescribe, pero ya nombra al cliente de la orden.
        $mov = collect($this->getJson("/api/inventory/movements?device_id={$device->id}")->json('data'))
            ->firstWhere('type', 'instalacion');
        $this->assertStringNotContainsString('Prospecto', $mov['to']);
        $this->assertNotSame('Cliente', $mov['to']);
    }

    #[Test]
    public function conversion_does_not_steal_a_device_that_already_moved_to_someone_else(): void
    {
        $device = $this->installOnProspectOrder();
        $otro   = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $device->forceFill(['customer_id' => $otro->id])->save();

        $this->convertProspect();

        $this->assertSame((int) $otro->id, (int) $device->fresh()->customer_id);
    }

    #[Test]
    public function the_backfill_command_repairs_prospects_converted_before_the_fix(): void
    {
        $device   = $this->installOnProspectOrder();
        $customer = User::factory()->create(['tenant_id' => $this->tenant->id]);

        // Como quedaba antes del arreglo: la orden ya tiene cliente, el equipo no.
        $this->installation->forceFill(['customer_id' => $customer->id])->save();

        $this->artisan('inventory:adopt-converted-prospect-devices')->assertSuccessful();
        $this->assertNull($device->fresh()->customer_id, 'Sin --apply no debe tocar nada.');

        $this->artisan('inventory:adopt-converted-prospect-devices', ['--apply' => true])->assertSuccessful();
        $this->assertSame((int) $customer->id, (int) $device->fresh()->customer_id);
    }
}
