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
 * Registrar lo que faltó en una orden ya firmada.
 *
 * Reporte del cliente: «hay órdenes que el técnico ya hizo firmar pero no se
 * cargaron correctamente los equipos» (amarres, router). La firma bloqueaba
 * las líneas para siempre, así que el inventario seguía dando en bodega lo que
 * ya estaba en la casa del cliente.
 *
 * Lo que estas pruebas sostienen:
 *  1. Quien administra inventario registra lo que faltó, CON motivo; descuenta
 *     igual que antes de firmar y queda en el kardex con el motivo.
 *  2. El técnico (sin permiso de inventario) sigue sin poder tocar una orden firmada.
 *  3. Lo que el cliente firmó no se quita; lo registrado tarde sí, como error de captura.
 */
class LateRegistrationAfterSignatureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $technician;
    private InventoryBranch $branch;
    private CustomerInstallation $installation;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();

        $adminRole = Role::create(['name' => 'Admin', 'permissions' => ['*'], 'tenant_id' => $tenant->id]);
        $techRole  = Role::create([
            'name' => 'Técnico', 'code' => 'technician', 'permissions' => ['view_support'], 'tenant_id' => $tenant->id,
        ]);

        $this->admin      = User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $adminRole->id]);
        $this->technician = User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $techRole->id]);

        $customer = User::factory()->create(['tenant_id' => $tenant->id]);
        CustomerProfile::create(['user_id' => $customer->id, 'name' => 'Flor', 'last_name' => 'Ramos', 'status' => true]);

        Sanctum::actingAs($this->admin);

        $this->branch = InventoryBranch::create(['name' => 'Chaguaní']);

        $this->installation = CustomerInstallation::create([
            'tenant_id'      => $tenant->id,
            'customer_id'    => $customer->id,
            'technician_id'  => $this->technician->id,
            'scheduled_date' => now()->toDateString(),
            'status'         => 'completada',
            'signed_at'      => now(),
        ]);
    }

    private function amarres(float $enBodega = 1400): InventoryStock
    {
        $stock = InventoryStock::create([
            'brand' => 'GENÉRICO', 'model' => 'AMARRES', 'price' => 100,
            'is_serialized' => false, 'unit' => 'unidad', 'quantity_decimals' => 0,
        ]);
        InventoryBalance::create([
            'stock_id' => $stock->id, 'holder_type' => 'branch', 'holder_id' => $this->branch->id, 'quantity' => $enBodega,
        ]);

        return $stock;
    }

    private function useAmarres(InventoryStock $stock, float $qty, ?string $reason)
    {
        return $this->postJson("/api/installations/{$this->installation->id}/equipment", array_filter([
            'stock_id' => $stock->id, 'quantity' => $qty, 'source_type' => 'branch', 'source_id' => $this->branch->id,
            'late_reason' => $reason,
        ]));
    }

    #[Test]
    public function an_inventory_manager_registers_what_was_missing_with_a_reason(): void
    {
        $amarres = $this->amarres();

        $this->useAmarres($amarres, 10, 'El técnico no alcanzó a cargar los amarres antes de la firma')
            ->assertCreated()
            ->assertJsonPath('item.after_signature', true)
            ->assertJsonPath('item.late_reason', 'El técnico no alcanzó a cargar los amarres antes de la firma');

        $this->assertEquals(1390, (float) InventoryBalance::withoutTenantScope()->firstOrFail()->quantity);

        $movement = InventoryMovement::withoutTenantScope()->firstOrFail();
        $this->assertSame((int) $this->installation->id, (int) $movement->installation_id);
        $this->assertStringStartsWith('Registrado después de la firma: El técnico no alcanzó', $movement->notes);
    }

    #[Test]
    public function a_signed_order_without_a_reason_still_rejects_new_lines(): void
    {
        $amarres = $this->amarres();

        $this->useAmarres($amarres, 10, null)->assertStatus(422)->assertJsonValidationErrors('installation');
        $this->useAmarres($amarres, 10, 'ok')->assertStatus(422)->assertJsonValidationErrors('installation');

        $this->assertSame(0, InstallationEquipment::withoutTenantScope()->count());
        $this->assertEquals(1400, (float) InventoryBalance::withoutTenantScope()->firstOrFail()->quantity);
    }

    #[Test]
    public function a_technician_cannot_register_on_a_signed_order_even_with_a_reason(): void
    {
        $stock = InventoryStock::create(['brand' => 'GENÉRICO', 'model' => 'RJ45', 'is_serialized' => false, 'unit' => 'unidad']);
        InventoryBalance::create([
            'stock_id' => $stock->id, 'holder_type' => 'user', 'holder_id' => $this->technician->id, 'quantity' => 50,
        ]);
        Sanctum::actingAs($this->technician);

        $this->postJson("/api/installations/{$this->installation->id}/equipment", [
            'stock_id' => $stock->id, 'quantity' => 4, 'source_type' => 'user', 'source_id' => $this->technician->id,
            'late_reason' => 'se me olvidó cargarlos',
        ])->assertStatus(422)->assertJsonValidationErrors('installation');

        $this->getJson("/api/installations/{$this->installation->id}/equipment/available")
            ->assertJsonPath('locked.is_locked', true)
            ->assertJsonPath('locked.late_allowed', false);

        $this->assertSame(0, InstallationEquipment::withoutTenantScope()->count());
    }

    #[Test]
    public function the_available_endpoint_tells_the_manager_that_late_registration_is_possible(): void
    {
        $this->getJson("/api/installations/{$this->installation->id}/equipment/available")
            ->assertOk()
            ->assertJsonPath('locked.is_locked', true)
            ->assertJsonPath('locked.reason', 'signed')
            ->assertJsonPath('locked.late_allowed', true);
    }

    #[Test]
    public function a_serial_device_can_be_registered_late_and_ends_up_at_the_customer(): void
    {
        $router = InventoryStock::create(['brand' => 'TP-LINK', 'model' => 'ARCHER C6', 'is_serialized' => true]);
        $device = InventoryDevice::create([
            'stock_id' => $router->id, 'serial' => 'SN-TARDE', 'branch_id' => $this->branch->id,
            'status' => InventoryDevice::STATUS_STOCK,
        ]);

        $this->postJson("/api/installations/{$this->installation->id}/equipment", [
            'device_id' => $device->id, 'late_reason' => 'Router instalado y no registrado en la hoja',
        ])->assertCreated()->assertJsonPath('item.after_signature', true);

        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $device->fresh()->status);
        $this->assertSame((int) $this->installation->customer_id, (int) $device->fresh()->customer_id);
    }

    #[Test]
    public function what_the_customer_signed_cannot_be_removed_but_a_late_capture_error_can(): void
    {
        $amarres = $this->amarres();

        // Línea firmada: se creó antes de la firma.
        $this->installation->forceFill(['signed_at' => null, 'status' => 'pendiente'])->save();
        $signedLine = $this->useAmarres($amarres, 5, null)->assertCreated()->json('item.id');
        $this->installation->forceFill(['signed_at' => now(), 'status' => 'completada'])->save();

        $lateLine = $this->useAmarres($amarres, 40, 'Faltaron los amarres del poste')->assertCreated()->json('item.id');

        $this->deleteJson("/api/installations/{$this->installation->id}/equipment/{$signedLine}")
            ->assertStatus(422)->assertJsonValidationErrors('installation');

        $this->deleteJson("/api/installations/{$this->installation->id}/equipment/{$lateLine}")->assertOk();

        $this->assertSame([$signedLine], InstallationEquipment::withoutTenantScope()->pluck('id')->all());
        $this->assertEquals(1395, (float) InventoryBalance::withoutTenantScope()->firstOrFail()->quantity);
    }

    #[Test]
    public function a_technician_cannot_remove_a_late_line_either(): void
    {
        $amarres = $this->amarres();
        $lateLine = $this->useAmarres($amarres, 40, 'Faltaron los amarres del poste')->assertCreated()->json('item.id');

        Sanctum::actingAs($this->technician);

        $this->deleteJson("/api/installations/{$this->installation->id}/equipment/{$lateLine}")
            ->assertStatus(422)->assertJsonValidationErrors('installation');

        $this->assertSame(1, InstallationEquipment::withoutTenantScope()->count());
    }

    #[Test]
    public function a_cancelled_order_takes_no_late_lines(): void
    {
        $amarres = $this->amarres();
        $this->installation->forceFill(['status' => 'cancelada', 'signed_at' => null])->save();

        $this->useAmarres($amarres, 10, 'Motivo cualquiera largo')->assertStatus(422);
        $this->assertSame(0, InstallationEquipment::withoutTenantScope()->count());
    }
}
