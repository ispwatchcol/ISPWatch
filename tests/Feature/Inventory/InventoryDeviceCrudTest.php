<?php

namespace Tests\Feature\Inventory;

use App\Models\CustomerInstallation;
use App\Models\InstallationEquipment;
use App\Models\InventoryDevice;
use App\Models\InventoryStock;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ver, editar y borrar un equipo desde la ficha de inventario.
 *
 * Estas pruebas existen por un fallo que llegó a producción: las rutas usaban
 * {inventory} mientras el controlador recibía InventoryDevice $inventoryDevice.
 * El route-model binding empareja por nombre, así que no ataba nada e inyectaba
 * un modelo VACÍO. Nada reventaba de forma visible —por eso pasó desapercibido—
 * pero ver un equipo devolvía un objeto en blanco, guardarlo respondía 422
 * diciendo que el serial ya estaba en uso (chocaba contra sí mismo) y borrarlo
 * contestaba "eliminado correctamente" dejando la fila intacta.
 *
 * Cada prueba de aquí falla si se vuelve a romper esa correspondencia.
 */
class InventoryDeviceCrudTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private InventoryStock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $role = Role::create(['name' => 'Admin', 'permissions' => ['*'], 'tenant_id' => $this->tenant->id]);
        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
        ]);

        Sanctum::actingAs($this->admin);

        $this->stock = InventoryStock::create([
            'brand' => 'MIKROTIK', 'model' => 'LDF', 'price' => 150000, 'is_serialized' => true,
        ]);
    }

    private function device(array $attributes = []): InventoryDevice
    {
        return InventoryDevice::create(array_merge([
            'stock_id' => $this->stock->id,
            'serial'   => 'SN-001',
            'mac'      => 'AA:BB:CC:DD:EE:01',
        ], $attributes));
    }

    /** Un equipo de otra empresa, escrito saltándose el scope de tenant. */
    private function foreignDevice(Tenant $otherTenant, array $attributes = []): InventoryDevice
    {
        $device = new InventoryDevice(array_merge(['serial' => 'AJENO-1'], $attributes));
        $device->tenant_id = $otherTenant->id;
        $device->save();

        return $device;
    }

    #[Test]
    public function ver_un_equipo_devuelve_sus_datos(): void
    {
        $device = $this->device();

        $this->getJson("/api/inventory/{$device->id}")
            ->assertOk()
            ->assertJsonPath('id', $device->id)
            ->assertJsonPath('serial', 'SN-001')
            ->assertJsonPath('stock.model', 'LDF');
    }

    #[Test]
    public function editar_sin_tocar_el_serial_no_choca_contra_si_mismo(): void
    {
        $device = $this->device();

        $this->putJson("/api/inventory/{$device->id}", [
            'stock_id' => $this->stock->id,
            'serial'   => 'SN-001',
            'mac'      => 'AA:BB:CC:DD:EE:01',
        ])->assertOk();
    }

    #[Test]
    public function editar_guarda_los_cambios_en_el_equipo_correcto(): void
    {
        $device = $this->device();

        $this->putJson("/api/inventory/{$device->id}", [
            'stock_id' => $this->stock->id,
            'serial'   => 'SN-999',
            'mac'      => 'AA:BB:CC:DD:EE:99',
        ])
            ->assertOk()
            ->assertJsonPath('device.id', $device->id)
            ->assertJsonPath('device.serial', 'SN-999');

        $this->assertDatabaseHas('inventory_device', [
            'id' => $device->id, 'serial' => 'SN-999', 'mac' => 'AA:BB:CC:DD:EE:99',
        ]);
    }

    #[Test]
    public function el_serial_repetido_dentro_de_la_empresa_se_rechaza_en_español(): void
    {
        $this->device(['serial' => 'SN-001', 'mac' => 'AA:BB:CC:DD:EE:01']);

        $this->postJson('/api/inventory', [
            'stock_id' => $this->stock->id,
            'serial'   => 'SN-001',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.serial.0', 'Ya tienes otro equipo registrado con este serial.');
    }

    #[Test]
    public function el_serial_de_otra_empresa_no_bloquea_el_alta(): void
    {
        $other = Tenant::factory()->create();
        $this->foreignDevice($other, ['serial' => 'SN-COMPARTIDO']);

        $this->postJson('/api/inventory', [
            'stock_id' => $this->stock->id,
            'serial'   => 'SN-COMPARTIDO',
        ])->assertCreated();
    }

    #[Test]
    public function borrar_un_equipo_lo_borra_de_verdad(): void
    {
        $device = $this->device();

        $this->deleteJson("/api/inventory/{$device->id}")->assertOk();

        $this->assertDatabaseMissing('inventory_device', ['id' => $device->id]);
    }

    #[Test]
    public function un_equipo_instalado_en_casa_de_un_cliente_no_se_puede_borrar(): void
    {
        $device = $this->device(['status' => InventoryDevice::STATUS_INSTALLED]);

        $this->deleteJson("/api/inventory/{$device->id}")->assertStatus(422);

        $this->assertDatabaseHas('inventory_device', ['id' => $device->id]);
    }

    #[Test]
    public function tampoco_se_borra_si_figura_en_una_instalacion_aunque_el_estado_diga_otra_cosa(): void
    {
        $device   = $this->device(['status' => InventoryDevice::STATUS_STOCK]);
        $customer = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $installation = CustomerInstallation::create([
            'tenant_id'      => $this->tenant->id,
            'customer_id'    => $customer->id,
            'technician_id'  => $this->admin->id,
            'scheduled_date' => now()->toDateString(),
            'status'         => 'pendiente',
        ]);

        InstallationEquipment::create([
            'installation_id' => $installation->id,
            'stock_id'        => $this->stock->id,
            'device_id'       => $device->id,
            'quantity'        => 1,
        ]);

        $this->deleteJson("/api/inventory/{$device->id}")->assertStatus(422);

        $this->assertDatabaseHas('inventory_device', ['id' => $device->id]);
    }

    #[Test]
    public function un_equipo_de_otra_empresa_no_se_ve_ni_se_edita_ni_se_borra(): void
    {
        $other   = Tenant::factory()->create();
        $foreign = $this->foreignDevice($other);

        $this->getJson("/api/inventory/{$foreign->id}")->assertNotFound();
        $this->putJson("/api/inventory/{$foreign->id}", ['serial' => 'HACKEADO'])->assertNotFound();
        $this->deleteJson("/api/inventory/{$foreign->id}")->assertNotFound();

        $this->assertDatabaseHas('inventory_device', ['id' => $foreign->id, 'serial' => 'AJENO-1']);
    }
}
