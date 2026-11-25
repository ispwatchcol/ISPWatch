<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryDevice;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «No puedo borrar ese router» (Chaguaní, 2026-11-25).
 *
 * Eliminar un equipo instalado o usado en un ticket se rechaza —a propósito— y
 * el mensaje manda a «darlo de baja». Pero la lista no tenía botón de baja, y
 * el endpoint de baja aceptaba un equipo INSTALADO: lo marcaba como chatarra
 * con el cliente todavía apuntándole. Se fija aquí la regla del endpoint.
 */
class InventoryRetireDeviceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->tenant = Tenant::factory()->create();
        Sanctum::actingAs(User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => Role::create(['name' => 'Admin', 'code' => 'admin', 'permissions' => ['*'], 'tenant_id' => $this->tenant->id])->id,
        ]));
    }

    private function device(string $status = InventoryDevice::STATUS_STOCK): InventoryDevice
    {
        $stock = InventoryStock::findOrFail($this->postJson('/api/inventory-stock', [
            'brand' => 'MIKROTIK', 'model' => 'LDF 5', 'price' => 200000, 'is_serialized' => true,
        ])->assertCreated()->json('id'));

        $device = InventoryDevice::findOrFail($this->postJson('/api/inventory', [
            'stock_id' => $stock->id, 'serial' => 'SN-' . uniqid(), 'mac' => null,
        ])->assertCreated()->json('device.id'));

        if ($status !== InventoryDevice::STATUS_STOCK) {
            $device->forceFill(['status' => $status])->save();
        }

        return $device->fresh();
    }

    #[Test]
    public function un_equipo_en_bodega_se_da_de_baja_y_queda_en_el_kardex(): void
    {
        $device = $this->device();

        $this->postJson("/api/inventory/{$device->id}/retire", ['notes' => 'Dañado por rayo'])->assertOk();

        $this->assertSame(InventoryDevice::STATUS_RETIRED, $device->fresh()->status);
        $this->assertSame(1, InventoryMovement::withoutTenantScope()
            ->where('device_id', $device->id)->where('type', InventoryMovement::TYPE_BAJA)->count());
    }

    #[Test]
    public function un_equipo_instalado_no_se_da_de_baja_desde_la_bodega(): void
    {
        $device = $this->device(InventoryDevice::STATUS_INSTALLED);

        $this->postJson("/api/inventory/{$device->id}/retire")
            ->assertStatus(422)
            ->assertJsonPath('errors.device.0', fn ($m) => str_contains($m, 'instalado en un cliente'));

        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $device->fresh()->status);
    }

    #[Test]
    public function uno_ya_dado_de_baja_no_se_da_de_baja_otra_vez(): void
    {
        $device = $this->device();
        $this->postJson("/api/inventory/{$device->id}/retire")->assertOk();

        $this->postJson("/api/inventory/{$device->id}/retire")->assertStatus(422);

        $this->assertSame(1, InventoryMovement::withoutTenantScope()
            ->where('device_id', $device->id)->where('type', InventoryMovement::TYPE_BAJA)->count(),
            'Una segunda baja duplicaría la línea del kardex.');
    }

    #[Test]
    public function un_equipo_instalado_tampoco_se_elimina(): void
    {
        $device = $this->device(InventoryDevice::STATUS_INSTALLED);

        $this->deleteJson("/api/inventory/{$device->id}")->assertStatus(422);
        $this->assertNotNull($device->fresh());
    }
}
