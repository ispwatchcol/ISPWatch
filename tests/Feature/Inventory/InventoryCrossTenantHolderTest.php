<?php

namespace Tests\Feature\Inventory;

use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\InstallationEquipment;
use App\Models\InventoryBalance;
use App\Models\InventoryBranch;
use App\Models\InventoryDevice;
use App\Models\InventoryMovement;
use App\Models\InventoryProvider;
use App\Models\InventoryStock;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\TicketEquipment;
use App\Models\User;
use App\Services\Inventory\InventoryLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ningún movimiento de inventario puede dejar existencias con una bodega o una
 * persona de OTRA empresa como custodio.
 *
 * Los permisos del ledger no lo cubrían: para una bodega sólo preguntaban si el
 * actor administra inventario, no de quién es la bodega, y `User` no tiene
 * scope de tenant. El caso que lo destapó fue el retiro desde un ticket: con el
 * id de una bodega ajena, el equipo del cliente quedaba con `branch_id` de otra
 * empresa.
 */
class InventoryCrossTenantHolderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $customer;
    private InventoryBranch $branch;
    private SupportTicket $ticket;
    private CustomerInstallation $installation;

    private Tenant $otroTenant;
    private InventoryBranch $bodegaAjena;
    private User $personaAjena;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $adminRole = Role::create([
            'name' => 'Admin', 'code' => 'admin', 'permissions' => ['*'], 'tenant_id' => $this->tenant->id,
        ]);

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'role_id' => $adminRole->id,
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
            'staff_id'  => $this->admin->id,
            'subject'   => 'Cambio de router',
            'status'    => 'open', 'priority' => 'medium', 'category' => 'technical',
        ]);

        $this->installation = CustomerInstallation::create([
            'tenant_id'      => $this->tenant->id,
            'customer_id'    => $this->customer->id,
            'technician_id'  => $this->admin->id,
            'scheduled_date' => now()->toDateString(),
            'status'         => 'pendiente',
        ]);

        // La otra empresa. OJO: `tenant_id` no es fillable; por `create()` el
        // hook estamparía el tenant autenticado y la bodega acabaría siendo
        // propia, con lo que la prueba no probaría nada.
        $this->otroTenant = Tenant::factory()->create();

        $this->bodegaAjena = new InventoryBranch(['name' => 'Bodega de otra empresa']);
        $this->bodegaAjena->tenant_id = $this->otroTenant->id;
        $this->bodegaAjena->save();

        $this->personaAjena = User::factory()->create(['tenant_id' => $this->otroTenant->id]);

        $this->assertNotSame((int) $this->tenant->id, (int) $this->bodegaAjena->tenant_id);
    }

    private function serializedStock(): InventoryStock
    {
        return InventoryStock::create([
            'brand' => 'MIKROTIK', 'model' => 'LDF', 'price' => 150000, 'is_serialized' => true,
        ]);
    }

    private function cable(): InventoryStock
    {
        return InventoryStock::create([
            'brand' => 'GENÉRICO', 'model' => 'UTP CAT6', 'price' => 1200,
            'is_serialized' => false, 'unit' => 'metro',
        ]);
    }

    private function saldo(InventoryStock $stock, string $type, int $id, float $quantity): InventoryBalance
    {
        return InventoryBalance::create([
            'stock_id' => $stock->id, 'holder_type' => $type, 'holder_id' => $id, 'quantity' => $quantity,
        ]);
    }

    private function routerInstaladoEnElCliente(): InventoryDevice
    {
        return InventoryDevice::create([
            'stock_id'    => $this->serializedStock()->id,
            'serial'      => 'SN-VIEJO',
            'customer_id' => $this->customer->id,
            'status'      => InventoryDevice::STATUS_INSTALLED,
        ]);
    }

    // ── Retiro desde el ticket: el caso que destapó el problema ───────────

    #[Test]
    public function a_device_returned_from_a_ticket_cannot_go_to_another_tenants_branch(): void
    {
        $router = $this->routerInstaladoEnElCliente();

        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'direction'   => 'in',
            'device_id'   => $router->id,
            'source_type' => 'branch',
            'source_id'   => $this->bodegaAjena->id,
        ])->assertStatus(422)->assertJsonValidationErrors('destination');

        $router->refresh();
        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $router->status);
        $this->assertSame((int) $this->customer->id, (int) $router->customer_id);
        $this->assertNull($router->branch_id);
        $this->assertSame(0, TicketEquipment::withoutTenantScope()->count());
        $this->assertSame(0, InventoryMovement::withoutTenantScope()->count());
    }

    #[Test]
    public function a_device_returned_from_a_ticket_cannot_go_to_a_person_of_another_tenant(): void
    {
        // El técnico asignado es la única persona ajena al actor que el retiro
        // admite como destino. Se fuerza por BD un `staff_id` de otra empresa
        // para probar que el ledger no lo acepta aunque llegue por ahí.
        $this->ticket->forceFill(['staff_id' => $this->personaAjena->id])->save();
        $router = $this->routerInstaladoEnElCliente();

        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'direction'   => 'in',
            'device_id'   => $router->id,
            'source_type' => 'user',
            'source_id'   => $this->personaAjena->id,
        ])->assertStatus(422)->assertJsonValidationErrors('destination');

        $router->refresh();
        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $router->status);
        $this->assertNull($router->user_id);
    }

    #[Test]
    public function a_device_returned_from_a_ticket_still_goes_to_an_own_branch_or_the_unlocated_shelf(): void
    {
        // Control positivo: la comprobación no puede romper los destinos buenos,
        // incluida la bodega «sin sucursal», que no tiene id.
        $router = $this->routerInstaladoEnElCliente();

        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'direction'   => 'in',
            'device_id'   => $router->id,
            'source_type' => 'branch',
            'source_id'   => $this->branch->id,
        ])->assertCreated();

        $router->refresh();
        $this->assertSame(InventoryDevice::STATUS_STOCK, $router->status);
        $this->assertSame((int) $this->branch->id, (int) $router->branch_id);

        $otro = InventoryDevice::create([
            'stock_id'    => $router->stock_id,
            'serial'      => 'SN-OTRO',
            'customer_id' => $this->customer->id,
            'status'      => InventoryDevice::STATUS_INSTALLED,
        ]);

        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'direction'   => 'in',
            'device_id'   => $otro->id,
            'source_type' => 'branch',
        ])->assertCreated();

        $otro->refresh();
        $this->assertSame(InventoryDevice::STATUS_STOCK, $otro->status);
        $this->assertNull($otro->branch_id);
    }

    // ── Consumo de material: el origen también es del tenant ──────────────

    #[Test]
    public function material_cannot_be_taken_from_another_tenants_branch_in_a_ticket(): void
    {
        $cable = $this->cable();
        $propio = $this->saldo($cable, 'branch', $this->branch->id, 100);

        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'stock_id'    => $cable->id,
            'quantity'    => 20,
            'source_type' => 'branch',
            'source_id'   => $this->bodegaAjena->id,
        ])->assertStatus(422)->assertJsonValidationErrors('source');

        $this->assertEquals(100, (float) $propio->fresh()->quantity);
        $this->assertSame(0, TicketEquipment::withoutTenantScope()->count());
        $this->assertSame(0, InventoryMovement::withoutTenantScope()->count());
    }

    #[Test]
    public function material_cannot_be_taken_from_another_tenants_branch_in_an_installation(): void
    {
        $cable = $this->cable();
        $propio = $this->saldo($cable, 'branch', $this->branch->id, 100);

        $this->postJson("/api/installations/{$this->installation->id}/equipment", [
            'stock_id'    => $cable->id,
            'quantity'    => 20,
            'source_type' => 'branch',
            'source_id'   => $this->bodegaAjena->id,
        ])->assertStatus(422)->assertJsonValidationErrors('source');

        $this->assertEquals(100, (float) $propio->fresh()->quantity);
        $this->assertSame(0, InstallationEquipment::withoutTenantScope()->count());
        $this->assertSame(0, InventoryMovement::withoutTenantScope()->count());
    }

    #[Test]
    public function material_from_another_tenants_catalog_cannot_be_consumed(): void
    {
        $cableAjeno = new InventoryStock([
            'brand' => 'GENÉRICO', 'model' => 'UTP AJENO', 'price' => 1000,
            'is_serialized' => false, 'unit' => 'metro',
        ]);
        $cableAjeno->tenant_id = $this->otroTenant->id;
        $cableAjeno->save();

        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'stock_id'    => $cableAjeno->id,
            'quantity'    => 5,
            'source_type' => 'branch',
            'source_id'   => $this->branch->id,
        ])->assertNotFound();

        $this->postJson("/api/installations/{$this->installation->id}/equipment", [
            'stock_id'    => $cableAjeno->id,
            'quantity'    => 5,
            'source_type' => 'branch',
            'source_id'   => $this->branch->id,
        ])->assertNotFound();

        $this->assertSame(0, InventoryMovement::withoutTenantScope()->count());
    }

    // ── Entregas (Inventario → Entregas) ──────────────────────────────────

    #[Test]
    public function a_transfer_cannot_hand_equipment_or_material_to_another_tenant(): void
    {
        $device = InventoryDevice::create([
            'stock_id'  => $this->serializedStock()->id,
            'serial'    => 'SN-BODEGA',
            'branch_id' => $this->branch->id,
            'status'    => InventoryDevice::STATUS_STOCK,
        ]);
        $cable  = $this->cable();
        $propio = $this->saldo($cable, 'branch', $this->branch->id, 100);

        // A una persona de otra empresa: `User` no tiene scope de tenant, así
        // que esto pasaba antes.
        $this->postJson('/api/inventory/transfers', [
            'to_type'    => 'user',
            'to_id'      => $this->personaAjena->id,
            'device_ids' => [$device->id],
            'materials'  => [[
                'stock_id' => $cable->id, 'quantity' => 10,
                'source_type' => 'branch', 'source_id' => $this->branch->id,
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('to_id');

        // A una bodega de otra empresa.
        $this->postJson('/api/inventory/transfers', [
            'to_type'    => 'branch',
            'to_id'      => $this->bodegaAjena->id,
            'device_ids' => [$device->id],
        ])->assertStatus(422)->assertJsonValidationErrors('to_id');

        $device->refresh();
        $this->assertSame(InventoryDevice::STATUS_STOCK, $device->status);
        $this->assertSame((int) $this->branch->id, (int) $device->branch_id);
        $this->assertNull($device->user_id);
        $this->assertEquals(100, (float) $propio->fresh()->quantity);
        $this->assertSame(0, InventoryBalance::withoutTenantScope()
            ->where('holder_type', 'user')->where('holder_id', $this->personaAjena->id)->count());
        $this->assertSame(0, InventoryMovement::withoutTenantScope()->count());
    }

    // ── Rescate de saldos huérfanos (P-19): la excepción del ORIGEN ───────
    //
    // `transferQuantity` no comprueba que el origen exista, a propósito: es la
    // única forma de rescatar el saldo de una bodega o persona borrada. Estas
    // pruebas fijan que esa excepción sólo alcanza filas de saldo del PROPIO
    // tenant, sólo por Entregas y sólo con permiso de inventario.

    /** Saldo del tenant ajeno, escrito a mano: sin sesión suya el hook pondría el nuestro. */
    private function saldoAjeno(float $quantity): array
    {
        $stockAjeno = new InventoryStock([
            'brand' => 'GENÉRICO', 'model' => 'UTP AJENO', 'is_serialized' => false, 'unit' => 'metro',
        ]);
        $stockAjeno->tenant_id = $this->otroTenant->id;
        $stockAjeno->save();

        $saldo = new InventoryBalance([
            'stock_id' => $stockAjeno->id, 'holder_type' => 'branch',
            'holder_id' => $this->bodegaAjena->id, 'quantity' => $quantity,
        ]);
        $saldo->tenant_id = $this->otroTenant->id;
        $saldo->save();

        return [$stockAjeno, $saldo];
    }

    /** Saldo propio cuya bodega se borró: el caso legítimo de rescate. */
    private function saldoHuerfano(InventoryStock $stock, float $quantity): int
    {
        $vieja = InventoryBranch::create(['name' => 'Bodega cerrada']);
        $this->saldo($stock, 'branch', $vieja->id, $quantity);
        $id = $vieja->id;
        $vieja->delete();

        return $id;
    }

    #[Test]
    public function a_rescue_cannot_pull_our_material_from_another_tenants_branch(): void
    {
        [, $saldoAjeno] = $this->saldoAjeno(300);
        $cable = $this->cable();

        $this->postJson('/api/inventory/transfers', [
            'to_type'   => 'branch',
            'to_id'     => $this->branch->id,
            'materials' => [[
                'stock_id' => $cable->id, 'quantity' => 50,
                'source_type' => 'branch', 'source_id' => $this->bodegaAjena->id,
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('materials');

        $this->assertEquals(300, (float) InventoryBalance::withoutTenantScope()->find($saldoAjeno->id)->quantity);
        $this->assertSame(0, InventoryBalance::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(0, InventoryMovement::withoutTenantScope()->count());
    }

    #[Test]
    public function a_rescue_cannot_take_another_tenants_material(): void
    {
        [$stockAjeno, $saldoAjeno] = $this->saldoAjeno(300);

        $this->postJson('/api/inventory/transfers', [
            'to_type'   => 'branch',
            'to_id'     => $this->branch->id,
            'materials' => [[
                'stock_id' => $stockAjeno->id, 'quantity' => 50,
                'source_type' => 'branch', 'source_id' => $this->bodegaAjena->id,
            ]],
        ])->assertNotFound();

        $this->assertEquals(300, (float) InventoryBalance::withoutTenantScope()->find($saldoAjeno->id)->quantity);
        $this->assertSame(0, InventoryMovement::withoutTenantScope()->count());
    }

    #[Test]
    public function another_tenant_cannot_rescue_our_orphan_balance(): void
    {
        $cable = $this->cable();
        $huerfano = $this->saldoHuerfano($cable, 80);

        $rolAjeno = Role::create([
            'name' => 'Admin', 'code' => 'admin', 'permissions' => ['*'], 'tenant_id' => $this->otroTenant->id,
        ]);
        $adminAjeno = User::factory()->create([
            'tenant_id' => $this->otroTenant->id, 'role_id' => $rolAjeno->id,
        ]);
        Sanctum::actingAs($adminAjeno);

        $this->postJson('/api/inventory/transfers', [
            'to_type'   => 'branch',
            'to_id'     => $this->bodegaAjena->id,
            'materials' => [[
                'stock_id' => $cable->id, 'quantity' => 80,
                'source_type' => 'branch', 'source_id' => $huerfano,
            ]],
        ])->assertNotFound();

        $this->getJson('/api/inventory/orphan-balances')->assertOk()->assertJsonCount(0);

        $this->assertEquals(80, (float) InventoryBalance::withoutTenantScope()
            ->where('holder_type', 'branch')->where('holder_id', $huerfano)->value('quantity'));
        $this->assertSame(0, InventoryMovement::withoutTenantScope()->count());
    }

    #[Test]
    public function a_rescue_requires_inventory_management_permission(): void
    {
        $cable = $this->cable();
        $huerfano = $this->saldoHuerfano($cable, 80);

        $rolTecnico = Role::create([
            'name' => 'Técnico', 'code' => 'technician', 'tenant_id' => $this->tenant->id,
            'permissions' => ['view_support', 'ticket_view', 'ticket_equipment'],
        ]);
        $tecnico = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $rolTecnico->id]);
        Sanctum::actingAs($tecnico);

        $this->postJson('/api/inventory/transfers', [
            'to_type'   => 'user',
            'to_id'     => $tecnico->id,
            'materials' => [[
                'stock_id' => $cable->id, 'quantity' => 80,
                'source_type' => 'branch', 'source_id' => $huerfano,
            ]],
        ])->assertForbidden();

        $this->assertEquals(80, (float) InventoryBalance::heldBy('branch', $huerfano)->value('quantity'));
        $this->assertSame(0, InventoryMovement::withoutTenantScope()->count());
    }

    #[Test]
    public function an_orphan_balance_cannot_be_consumed_in_a_ticket_or_an_installation(): void
    {
        // El rescate va por Entregas, con su nota y su permiso. Gastar el saldo
        // de una bodega borrada directamente en una visita se saltaría ese paso.
        $cable = $this->cable();
        $huerfano = $this->saldoHuerfano($cable, 80);

        $this->postJson("/api/support/{$this->ticket->id}/equipment", [
            'stock_id' => $cable->id, 'quantity' => 10,
            'source_type' => 'branch', 'source_id' => $huerfano,
        ])->assertStatus(422)->assertJsonValidationErrors('source');

        $this->postJson("/api/installations/{$this->installation->id}/equipment", [
            'stock_id' => $cable->id, 'quantity' => 10,
            'source_type' => 'branch', 'source_id' => $huerfano,
        ])->assertStatus(422)->assertJsonValidationErrors('source');

        $this->assertEquals(80, (float) InventoryBalance::heldBy('branch', $huerfano)->value('quantity'));
        $this->assertSame(0, InventoryMovement::withoutTenantScope()->count());
    }

    #[Test]
    public function material_misrouted_to_a_foreign_person_is_rescued_without_touching_the_other_tenant(): void
    {
        // Antes de P-67, Entregas aceptaba como destino a una persona de otra
        // empresa. Esas filas son NUESTRAS (nuestro tenant, nuestro material);
        // la persona ajena nunca pudo verlas ni usarlas. Salen en huérfanos y
        // el rescate las devuelve sin crear ni tocar nada del otro tenant.
        $cable = $this->cable();
        $this->saldo($cable, 'user', $this->personaAjena->id, 25);

        $this->getJson('/api/inventory/orphan-balances')
            ->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.holder_id', $this->personaAjena->id);

        $this->postJson('/api/inventory/transfers', [
            'to_type'   => 'branch',
            'to_id'     => $this->branch->id,
            'materials' => [[
                'stock_id' => $cable->id, 'quantity' => 25,
                'source_type' => 'user', 'source_id' => $this->personaAjena->id,
            ]],
        ])->assertCreated();

        $this->assertEquals(25, (float) InventoryBalance::heldBy('branch', $this->branch->id)->value('quantity'));
        $this->assertEquals(0, (float) InventoryBalance::heldBy('user', $this->personaAjena->id)->value('quantity'));
        $this->assertSame(0, InventoryBalance::withoutTenantScope()->where('tenant_id', $this->otroTenant->id)->count());
        $this->assertSame(0, InventoryMovement::withoutTenantScope()->where('tenant_id', '!=', $this->tenant->id)->count());
    }

    // ── Tipos y custodios nulos, directo contra el ledger ─────────────────
    //
    // Por HTTP los tipos llegan filtrados por `in:branch,user`, pero el ledger
    // es público: un comando, un job o el próximo controlador lo pueden llamar
    // con cualquier cosa. Estas pruebas no pasan por la validación del request.

    private function ledger(): InventoryLedger
    {
        return app(InventoryLedger::class);
    }

    /** Ejecuta y exige un 422 en `$campo`, sin dejar rastro en el kardex. */
    private function assertRechaza(callable $operacion, string $campo): void
    {
        try {
            $operacion();
            $this->fail("Se esperaba un rechazo en «{$campo}».");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors());
        }

        $this->assertSame(0, InventoryMovement::withoutTenantScope()->count());
    }

    #[Test]
    public function the_ledger_rejects_holder_types_that_are_not_a_branch_or_a_person(): void
    {
        $device = InventoryDevice::create([
            'stock_id'  => $this->serializedStock()->id,
            'serial'    => 'SN-BODEGA',
            'branch_id' => $this->branch->id,
            'status'    => InventoryDevice::STATUS_STOCK,
        ]);

        // `customer` es un extremo válido del kardex, pero no un custodio
        // interno. Antes, transferDevice lo trataba como bodega: el equipo
        // quedaba con branch_id = id del cliente, sin comprobar nada.
        foreach (['customer', 'supplier', 'scrap', 'inventado', ''] as $tipo) {
            $this->assertRechaza(
                fn () => $this->ledger()->transferDevice($device, $tipo, (int) $this->customer->id, $this->admin),
                'to_id'
            );
        }

        $device->refresh();
        $this->assertSame(InventoryDevice::STATUS_STOCK, $device->status);
        $this->assertSame((int) $this->branch->id, (int) $device->branch_id);

        $cable = $this->cable();
        $saldo = $this->saldo($cable, 'branch', $this->branch->id, 100);

        $this->assertRechaza(
            fn () => $this->ledger()->transferQuantity($cable, 'branch', $this->branch->id, 'customer', (int) $this->customer->id, 10, $this->admin),
            'to_id'
        );
        $this->assertRechaza(
            fn () => $this->ledger()->assignMaterialToTicket($this->ticket, $cable, 10, 'supplier', 1, $this->admin),
            'source'
        );
        $this->assertRechaza(
            fn () => $this->ledger()->assignMaterialToInstallation($this->installation, $cable, 10, 'customer', (int) $this->customer->id, $this->admin),
            'source'
        );

        $this->assertEquals(100, (float) $saldo->fresh()->quantity);
        $this->assertSame(1, InventoryBalance::withoutTenantScope()->count());

        $router = $this->routerInstaladoEnElCliente();

        $this->assertRechaza(
            fn () => $this->ledger()->returnDeviceFromTicket($this->ticket, $router, 'customer', (int) $this->customer->id, $this->admin),
            'destination'
        );

        $router->refresh();
        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $router->status);
        $this->assertSame((int) $this->customer->id, (int) $router->customer_id);
        $this->assertSame(0, TicketEquipment::withoutTenantScope()->count());
    }

    #[Test]
    public function a_null_holder_is_only_the_unlocated_shelf_never_a_person(): void
    {
        $router = $this->routerInstaladoEnElCliente();

        // Retiro a «persona sin id»: antes lo frenaba sólo el `(int) null` de
        // canTakeFrom, con un mensaje sobre otro técnico que no venía al caso.
        $this->assertRechaza(
            fn () => $this->ledger()->returnDeviceFromTicket($this->ticket, $router, 'user', null, $this->admin),
            'destination'
        );

        $router->refresh();
        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $router->status);
        $this->assertNull($router->user_id);

        $device = InventoryDevice::create([
            'stock_id'  => $router->stock_id,
            'serial'    => 'SN-BODEGA',
            'branch_id' => $this->branch->id,
            'status'    => InventoryDevice::STATUS_STOCK,
        ]);

        $this->assertRechaza(
            fn () => $this->ledger()->transferDevice($device, 'user', null, $this->admin),
            'to_id'
        );
        $this->assertSame((int) $this->branch->id, (int) $device->fresh()->branch_id);

        // Y la bodega sin id sigue valiendo donde tiene sentido: los equipos.
        $this->ledger()->transferDevice($device, 'branch', null, $this->admin);

        $device->refresh();
        $this->assertSame(InventoryDevice::STATUS_STOCK, $device->status);
        $this->assertNull($device->branch_id);
        $this->assertSame(1, InventoryMovement::withoutTenantScope()->count());
    }

    // ── Alta y edición de equipos ─────────────────────────────────────────

    #[Test]
    public function a_device_cannot_be_registered_with_another_tenants_catalog_provider_branch_or_person(): void
    {
        $stockAjeno = new InventoryStock(['brand' => 'TP-LINK', 'model' => 'AJENO', 'is_serialized' => true]);
        $stockAjeno->tenant_id = $this->otroTenant->id;
        $stockAjeno->save();

        $proveedorAjeno = new InventoryProvider(['name' => 'Proveedor ajeno']);
        $proveedorAjeno->tenant_id = $this->otroTenant->id;
        $proveedorAjeno->save();

        $this->postJson('/api/inventory', [
            'serial'      => 'SN-NUEVO',
            'stock_id'    => $stockAjeno->id,
            'provider_id' => $proveedorAjeno->id,
            'branch_id'   => $this->bodegaAjena->id,
            'user_id'     => $this->personaAjena->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['stock_id', 'provider_id', 'branch_id', 'user_id']);

        $this->assertSame(0, InventoryDevice::withoutTenantScope()->count());

        $propio = InventoryDevice::create([
            'stock_id'  => $this->serializedStock()->id,
            'serial'    => 'SN-PROPIO',
            'branch_id' => $this->branch->id,
            'status'    => InventoryDevice::STATUS_STOCK,
        ]);

        $this->putJson("/api/inventory/{$propio->id}", [
            'branch_id' => $this->bodegaAjena->id,
        ])->assertStatus(422)->assertJsonValidationErrors('branch_id');

        $this->assertSame((int) $this->branch->id, (int) $propio->fresh()->branch_id);
    }
}
