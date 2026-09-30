<?php

namespace Tests\Feature\Inventory;

use App\Models\CustomerDocument;
use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\InstallationEquipment;
use App\Models\InstallationPlannedItem;
use App\Models\InventoryBalance;
use App\Models\InventoryBranch;
use App\Models\InventoryDevice;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Selector de inventario en instalaciones — entrega A.
 *
 * Tres ideas que estas pruebas sostienen, y que el código no puede perder sin
 * que alguna se ponga en rojo:
 *
 *  1. PLANIFICAR no mueve existencias. El plan es una lista de productos del
 *     tenant con cantidad; no descuenta, no reserva, no escribe en el kardex.
 *  2. USAR descuenta una sola vez, con las reglas de siempre del ledger, y
 *     COBRAR lo usado no vuelve a descontar.
 *  3. Una orden que ya consumió inventario, se firmó o se facturó no se borra
 *     ni se cancela: perdería el respaldo de ese consumo (P-69).
 */
class InstallationInventorySelectorTest extends TestCase
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
        // Agenda y carga la hoja, pero NO administra inventario: es quien no
        // debe ver precios ni en qué bodega está cada cosa.
        $techRole = Role::create([
            'name'        => 'Técnico',
            'code'        => 'technician',
            'permissions' => ['view_support'],
            'tenant_id'   => $this->tenant->id,
        ]);

        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $adminRole->id]);
        $this->technician = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $techRole->id]);

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

    // ── Ayudas ───────────────────────────────────────────────────────────

    private function cable(): InventoryStock
    {
        return InventoryStock::create([
            'brand' => 'GENÉRICO', 'model' => 'CABLE UTP CAT5E', 'price' => 1200,
            'is_serialized' => false, 'unit' => 'm',
        ]);
    }

    private function router(): InventoryStock
    {
        return InventoryStock::create([
            'brand' => 'TP-LINK', 'model' => 'ARCHER C6', 'price' => 150000, 'is_serialized' => true,
        ]);
    }

    private function balance(InventoryStock $stock, string $type, int $id, float $qty): InventoryBalance
    {
        return InventoryBalance::create([
            'stock_id' => $stock->id, 'holder_type' => $type, 'holder_id' => $id, 'quantity' => $qty,
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

    /** Otra empresa con su propio cable y su saldo, para las pruebas de aislamiento. */
    private function otherTenantWithCable(float $qty = 500): array
    {
        $tenant = Tenant::factory()->create();
        $role   = Role::create(['name' => 'Admin', 'permissions' => ['*'], 'tenant_id' => $tenant->id]);
        $admin  = User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]);

        Sanctum::actingAs($admin);
        $branch = InventoryBranch::create(['name' => 'Bodega Ajena']);
        $stock  = InventoryStock::create([
            'brand' => 'AJENO', 'model' => 'CABLE AJENO', 'price' => 999, 'is_serialized' => false, 'unit' => 'm',
        ]);
        InventoryBalance::create([
            'stock_id' => $stock->id, 'holder_type' => 'branch', 'holder_id' => $branch->id, 'quantity' => $qty,
        ]);
        Sanctum::actingAs($this->admin);

        return [$tenant, $stock, $branch];
    }

    private function movements(): int
    {
        return InventoryMovement::withoutTenantScope()->count();
    }

    private function useMaterial(InventoryStock $stock, float $qty, string $type, int $id)
    {
        return $this->postJson("/api/installations/{$this->installation->id}/equipment", [
            'stock_id' => $stock->id, 'quantity' => $qty, 'source_type' => $type, 'source_id' => $id,
        ]);
    }

    private function plan(array $items)
    {
        return $this->putJson("/api/customers/installations/{$this->installation->id}", [
            'scheduled_date' => now()->toDateString(),
            'planned_items'  => $items,
        ]);
    }

    // ── Catálogo de planificación ────────────────────────────────────────

    #[Test]
    public function the_planning_catalog_only_shows_this_tenants_products_with_aggregated_availability(): void
    {
        $cable  = $this->cable();
        $router = $this->router();
        $this->balance($cable, 'branch', $this->branch->id, 100);
        $this->balance($cable, 'user', $this->technician->id, 20);
        $this->deviceHeldBy($router, null, 'SN-1');
        $this->deviceHeldBy($router, $this->technician, 'SN-2');
        // Un instalado no está disponible.
        InventoryDevice::create([
            'stock_id' => $router->id, 'serial' => 'SN-3', 'customer_id' => $this->customer->id,
            'status' => InventoryDevice::STATUS_INSTALLED,
        ]);

        [, $ajeno] = $this->otherTenantWithCable();

        $products = collect($this->getJson('/api/installations/planning-catalog')->assertOk()->json('products'))
            ->keyBy('id');

        $this->assertFalse($products->has($ajeno->id), 'El catálogo no puede listar productos de otra empresa.');
        $this->assertEquals(120, $products[$cable->id]['available']);
        $this->assertSame('m', $products[$cable->id]['unit']);
        $this->assertFalse($products[$cable->id]['is_serialized']);
        $this->assertEquals(2, $products[$router->id]['available']);
    }

    #[Test]
    public function without_view_inventory_the_catalog_hides_prices_and_holders(): void
    {
        $cable = $this->cable();
        $this->balance($cable, 'branch', $this->branch->id, 100);

        Sanctum::actingAs($this->technician);
        $response = $this->getJson('/api/installations/planning-catalog')->assertOk();

        $this->assertFalse($response->json('can_view_details'));
        $product = $response->json('products.0');
        $this->assertEquals(100, $product['available'], 'La disponibilidad agregada sí se ve.');
        $this->assertArrayNotHasKey('price', $product);
        $this->assertArrayNotHasKey('holders', $product);
        $this->assertStringNotContainsString('Bodega Principal', $response->getContent());
    }

    #[Test]
    public function with_view_inventory_the_catalog_shows_prices_and_holders(): void
    {
        $cable = $this->cable();
        $this->balance($cable, 'branch', $this->branch->id, 100);

        $response = $this->getJson('/api/installations/planning-catalog')->assertOk();

        $this->assertTrue($response->json('can_view_details'));
        $this->assertEquals(1200, $response->json('products.0.price'));
        $this->assertSame('Bodega Principal', $response->json('products.0.holders.0.label'));
        $this->assertEquals(100, $response->json('products.0.holders.0.quantity'));
    }

    #[Test]
    public function the_planning_catalog_requires_view_support(): void
    {
        $role = Role::create(['name' => 'Clientes', 'permissions' => ['view_clients'], 'tenant_id' => $this->tenant->id]);
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $role->id]));

        $this->getJson('/api/installations/planning-catalog')->assertForbidden();
    }

    // ── Planificar no mueve inventario ───────────────────────────────────

    #[Test]
    public function scheduling_with_a_plan_moves_no_inventory(): void
    {
        $cable  = $this->cable();
        $router = $this->router();
        $this->balance($cable, 'user', $this->technician->id, 50);
        $device = $this->deviceHeldBy($router, $this->technician, 'SN-PLAN');

        $response = $this->postJson('/api/installations', [
            'name'           => 'Prospecto Plan',
            'scheduled_date' => now()->toDateString(),
            'technician_id'  => $this->technician->id,
            'planned_items'  => [
                ['stock_id' => $cable->id, 'quantity' => 30],
                ['stock_id' => $router->id, 'quantity' => 1, 'notes' => 'El del cliente se quemó'],
            ],
        ])->assertCreated();

        $this->assertSame([], $response->json('planning_warnings'));
        $this->assertCount(2, $response->json('installation.planned_items'));
        $this->assertSame('GENÉRICO CABLE UTP CAT5E', $response->json('installation.planned_items.0.label'));
        $this->assertSame('m', $response->json('installation.planned_items.0.unit'));
        $this->assertArrayNotHasKey('price', $response->json('installation.planned_items.0'));

        $this->assertSame(0, $this->movements(), 'Planificar no escribe en el kardex.');
        $this->assertEquals(50, (float) InventoryBalance::withoutTenantScope()->firstOrFail()->quantity);
        $this->assertSame(InventoryDevice::STATUS_ASSIGNED, $device->fresh()->status, 'Planificar no reserva equipos.');
    }

    #[Test]
    public function planning_more_than_available_is_allowed_with_a_warning(): void
    {
        $cable = $this->cable();
        $this->balance($cable, 'branch', $this->branch->id, 20);

        $response = $this->plan([['stock_id' => $cable->id, 'quantity' => 30]])->assertOk();

        $this->assertCount(1, $response->json('planning_warnings'));
        $this->assertStringContainsString('Planificar no reserva', $response->json('planning_warnings.0'));
        $this->assertEquals(30, $response->json('installation.planned_items.0.quantity'));
        $this->assertSame(0, $this->movements());
    }

    #[Test]
    public function a_product_of_another_tenant_cannot_be_planned(): void
    {
        [, $ajeno] = $this->otherTenantWithCable();

        $this->plan([['stock_id' => $ajeno->id, 'quantity' => 5]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('planned_items.0.stock_id');

        $this->assertSame(0, InstallationPlannedItem::withoutTenantScope()->count());
    }

    #[Test]
    public function the_plan_of_another_tenants_installation_is_not_reachable(): void
    {
        $cable = $this->cable();
        [$tenant] = $this->otherTenantWithCable();

        $role  = Role::create(['name' => 'Admin', 'permissions' => ['*'], 'tenant_id' => $tenant->id]);
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]));

        $this->plan([['stock_id' => $cable->id, 'quantity' => 5]])->assertNotFound();
        $this->getJson("/api/installations/{$this->installation->id}/equipment/available")->assertNotFound();
    }

    #[Test]
    public function serialized_products_are_planned_in_whole_units(): void
    {
        $router = $this->router();

        $this->plan([['stock_id' => $router->id, 'quantity' => 1.5]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('planned_items.0.quantity');
    }

    #[Test]
    public function the_planned_label_survives_renaming_and_deleting_the_product(): void
    {
        $cable = $this->cable();

        $id = $this->plan([['stock_id' => $cable->id, 'quantity' => 30]])->assertOk()
            ->json('installation.planned_items.0.id');

        $cable->update(['model' => 'NOMBRE NUEVO', 'unit' => 'rollo']);

        // Se reenvía la misma línea con otra cantidad: conserva su etiqueta.
        $response = $this->plan([['id' => $id, 'quantity' => 25]])->assertOk();
        $this->assertSame($id, $response->json('installation.planned_items.0.id'));
        $this->assertSame('GENÉRICO CABLE UTP CAT5E', $response->json('installation.planned_items.0.label'));
        $this->assertSame('m', $response->json('installation.planned_items.0.unit'));
        $this->assertEquals(25, $response->json('installation.planned_items.0.quantity'));

        $cable->delete();

        $row = $this->getJson("/api/installations/{$this->installation->id}")->assertOk()->json('planned_items.0');
        $this->assertNull($row['stock_id']);
        $this->assertSame('GENÉRICO CABLE UTP CAT5E', $row['label']);
    }

    #[Test]
    public function a_plan_line_of_another_installation_cannot_be_hijacked(): void
    {
        $cable = $this->cable();
        $other = CustomerInstallation::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'scheduled_date' => now()->toDateString(), 'status' => 'pendiente',
        ]);

        $foreignId = $this->putJson("/api/customers/installations/{$other->id}", [
            'scheduled_date' => now()->toDateString(),
            'planned_items'  => [['stock_id' => $cable->id, 'quantity' => 3]],
        ])->assertOk()->json('installation.planned_items.0.id');

        $this->plan([['id' => $foreignId, 'quantity' => 99]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('planned_items.0.id');

        $this->assertEquals(3, (float) InstallationPlannedItem::withoutTenantScope()->findOrFail($foreignId)->quantity);
    }

    #[Test]
    public function an_update_without_the_plan_key_keeps_the_plan_and_the_legacy_text(): void
    {
        $cable = $this->cable();
        $this->installation->update(['equipment' => 'Router TP-Link; cable 20m (texto de antes)']);
        $this->plan([['stock_id' => $cable->id, 'quantity' => 10]])->assertOk();

        // Un cliente que no conoce el plan (la API, una pantalla anterior).
        $response = $this->putJson("/api/customers/installations/{$this->installation->id}", [
            'scheduled_date' => now()->toDateString(),
            'equipment'      => 'Router TP-Link; cable 20m (texto de antes)',
        ])->assertOk();

        $this->assertCount(1, $response->json('installation.planned_items'));
        $this->assertSame('Router TP-Link; cable 20m (texto de antes)', $response->json('installation.equipment'));
    }

    #[Test]
    public function an_old_installation_without_plan_still_reads_as_before(): void
    {
        $this->installation->update(['equipment' => 'Antena LDF + 30 m de cable']);

        $row = $this->getJson("/api/installations/{$this->installation->id}")->assertOk();

        $row->assertJsonPath('equipment', 'Antena LDF + 30 m de cable')
            ->assertJsonPath('planned_items', [])
            ->assertJsonPath('equipment_items_count', 0)
            ->assertJsonPath('is_signed', false);
    }

    #[Test]
    public function the_plan_of_a_signed_installation_cannot_change(): void
    {
        $cable = $this->cable();
        $id = $this->plan([['stock_id' => $cable->id, 'quantity' => 30]])->assertOk()
            ->json('installation.planned_items.0.id');

        $this->installation->forceFill(['signed_at' => now(), 'status' => 'completada'])->save();

        $this->plan([['id' => $id, 'quantity' => 5]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('planned_items');

        // Reenviar el mismo plan (el formulario de edición lo hace) sí vale.
        $this->plan([['id' => $id, 'quantity' => 30]])->assertOk();
    }

    // ── Consumibles visibles y explicados ────────────────────────────────

    #[Test]
    public function the_available_list_explains_when_there_are_no_consumable_products(): void
    {
        $this->router();

        $this->getJson("/api/installations/{$this->installation->id}/equipment/available")
            ->assertOk()
            ->assertJsonPath('materials', [])
            ->assertJsonPath('materials_status.code', 'no_consumable_products');
    }

    #[Test]
    public function the_available_list_explains_when_consumables_have_no_stock(): void
    {
        $this->cable();

        $this->getJson("/api/installations/{$this->installation->id}/equipment/available")
            ->assertOk()
            ->assertJsonPath('materials_status.code', 'no_stock');
    }

    #[Test]
    public function the_available_list_explains_stock_the_user_cannot_take(): void
    {
        $cable = $this->cable();
        $this->balance($cable, 'branch', $this->branch->id, 100);

        Sanctum::actingAs($this->technician);

        $response = $this->getJson("/api/installations/{$this->installation->id}/equipment/available")
            ->assertOk()
            ->assertJsonPath('materials', [])
            ->assertJsonPath('materials_status.code', 'not_accessible')
            ->assertJsonPath('materials_status.inaccessible_products', 1);

        // Explica sin revelar dónde está ni cuánto hay.
        $this->assertStringNotContainsString('Bodega Principal', $response->getContent());
    }

    #[Test]
    public function the_available_list_is_ok_when_the_user_has_stock(): void
    {
        $cable = $this->cable();
        $this->balance($cable, 'user', $this->technician->id, 40);

        Sanctum::actingAs($this->technician);

        $this->getJson("/api/installations/{$this->installation->id}/equipment/available")
            ->assertOk()
            ->assertJsonCount(1, 'materials')
            ->assertJsonPath('materials_status.code', 'ok');
    }

    #[Test]
    public function another_tenants_stock_does_not_change_the_explanation(): void
    {
        $this->cable();
        $this->otherTenantWithCable(1000);

        $this->getJson("/api/installations/{$this->installation->id}/equipment/available")
            ->assertOk()
            ->assertJsonPath('materials', [])
            ->assertJsonPath('materials_status.code', 'no_stock');
    }

    // ── Usar descuenta una vez; cobrar no descuenta ──────────────────────

    #[Test]
    public function using_planned_material_discounts_it_exactly_once(): void
    {
        $cable = $this->cable();
        $this->balance($cable, 'user', $this->technician->id, 50);
        $this->plan([['stock_id' => $cable->id, 'quantity' => 30]])->assertOk();

        Sanctum::actingAs($this->technician);
        $this->useMaterial($cable, 30, 'user', $this->technician->id)->assertCreated();

        $saldo = fn () => (float) InventoryBalance::withoutTenantScope()->where('stock_id', $cable->id)->firstOrFail()->quantity;
        $this->assertEquals(20, $saldo());
        $this->assertSame(1, $this->movements());

        // Volver a guardar la orden con su plan no descuenta otra vez.
        Sanctum::actingAs($this->admin);
        $id = InstallationPlannedItem::withoutTenantScope()->firstOrFail()->id;
        $this->plan([['id' => $id, 'quantity' => 30]])->assertOk();

        $this->assertEquals(20, $saldo());
        $this->assertSame(1, $this->movements());
    }

    #[Test]
    public function using_material_still_validates_balance_on_the_server_even_if_planned(): void
    {
        $cable = $this->cable();
        $this->balance($cable, 'user', $this->technician->id, 10);
        $this->plan([['stock_id' => $cable->id, 'quantity' => 30]])->assertOk();

        Sanctum::actingAs($this->technician);
        $this->useMaterial($cable, 30, 'user', $this->technician->id)->assertStatus(422);

        $this->assertSame(0, $this->movements());
    }

    #[Test]
    public function charging_used_lines_writes_no_inventory_movement(): void
    {
        $cable = $this->cable();
        $this->balance($cable, 'user', $this->technician->id, 50);
        $this->useMaterial($cable, 30, 'user', $this->technician->id)->assertCreated();

        $antes = $this->movements();

        // Lo que hace «Cobrar equipo de la instalación»: el concepto viaja como
        // adicional de la cartera, junto a un servicio manual.
        $this->putJson("/api/installations/{$this->installation->id}/billing", [
            'installation_cost' => 80000,
            'additional_items'  => [
                ['description' => '30 m · GENÉRICO CABLE UTP CAT5E', 'amount' => 36000],
                ['description' => 'Visita técnica', 'amount' => 20000],
            ],
        ])->assertOk();

        $this->assertSame($antes, $this->movements(), 'Cobrar no descuenta inventario otra vez.');
        $this->assertEquals(20, (float) InventoryBalance::withoutTenantScope()->firstOrFail()->quantity);
        $this->assertNotNull(Invoice::where('installation_id', $this->installation->id)->first());
    }

    // ── Bloqueos preventivos ─────────────────────────────────────────────

    #[Test]
    public function an_installation_with_used_lines_cannot_be_deleted(): void
    {
        $router = $this->router();
        $device = $this->deviceHeldBy($router, $this->technician, 'SN-BORRAR');

        Sanctum::actingAs($this->technician);
        $this->postJson("/api/installations/{$this->installation->id}/equipment", ['device_id' => $device->id])
            ->assertCreated();

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/customers/installations/{$this->installation->id}")
            ->assertStatus(409)
            ->assertJsonPath('error', 'installation_has_history')
            ->assertJsonPath('blocked_by', ['equipment']);

        $this->assertNotNull($this->installation->fresh());
        $this->assertSame(1, InstallationEquipment::withoutTenantScope()->count());
        $this->assertSame(InventoryDevice::STATUS_INSTALLED, $device->fresh()->status);
    }

    #[Test]
    public function a_signed_installation_cannot_be_deleted(): void
    {
        $this->installation->forceFill(['signed_at' => now(), 'status' => 'completada'])->save();

        $this->deleteJson("/api/customers/installations/{$this->installation->id}")
            ->assertStatus(409)
            ->assertJsonPath('blocked_by', ['signed']);
    }

    #[Test]
    public function an_installation_with_a_signed_sheet_document_cannot_be_deleted(): void
    {
        CustomerDocument::create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'installation_id' => $this->installation->id, 'type' => 'instalacion',
            'file_name' => 'hoja.pdf', 'file_path' => 'x/hoja.pdf', 'signed' => true,
        ]);

        $this->deleteJson("/api/customers/installations/{$this->installation->id}")
            ->assertStatus(409)
            ->assertJsonPath('blocked_by', ['signed']);
    }

    #[Test]
    public function an_invoiced_installation_cannot_be_deleted(): void
    {
        $this->putJson("/api/installations/{$this->installation->id}/billing", ['installation_cost' => 80000])
            ->assertOk();

        $this->deleteJson("/api/customers/installations/{$this->installation->id}")
            ->assertStatus(409)
            ->assertJsonPath('blocked_by', ['invoice']);
    }

    #[Test]
    public function a_clean_installation_with_only_a_plan_can_still_be_deleted(): void
    {
        $cable = $this->cable();
        $this->plan([['stock_id' => $cable->id, 'quantity' => 10]])->assertOk();

        $this->deleteJson("/api/customers/installations/{$this->installation->id}")->assertOk();

        $this->assertNull(CustomerInstallation::find($this->installation->id));
        $this->assertSame(0, InstallationPlannedItem::withoutTenantScope()->count());
    }

    #[Test]
    public function the_model_refuses_to_delete_an_installation_with_used_lines(): void
    {
        $cable = $this->cable();
        $this->balance($cable, 'user', $this->admin->id, 10);
        $this->useMaterial($cable, 4, 'user', $this->admin->id)->assertCreated();

        $this->expectException(\RuntimeException::class);
        $this->installation->fresh()->delete();
    }

    #[Test]
    public function an_installation_with_real_consumption_cannot_be_cancelled(): void
    {
        $cable = $this->cable();
        $this->balance($cable, 'user', $this->admin->id, 10);
        $this->useMaterial($cable, 4, 'user', $this->admin->id)->assertCreated();

        $response = $this->putJson("/api/customers/installations/{$this->installation->id}", [
            'scheduled_date' => now()->toDateString(),
            'status'         => 'cancelada',
        ])->assertStatus(422)->assertJsonValidationErrors('status');

        // No sugiere deshacer el consumo para poder cancelar.
        $this->assertStringNotContainsString('devuelve', mb_strtolower($response->json('errors.status.0')));
        $this->assertStringNotContainsString('quita', mb_strtolower($response->json('errors.status.0')));

        $this->assertSame('pendiente', $this->installation->fresh()->status);
        $this->assertEquals(6, (float) InventoryBalance::withoutTenantScope()->firstOrFail()->quantity);
    }

    #[Test]
    public function a_signed_installation_cannot_be_cancelled(): void
    {
        $this->installation->forceFill(['signed_at' => now(), 'status' => 'completada'])->save();

        $this->putJson("/api/customers/installations/{$this->installation->id}", [
            'scheduled_date' => now()->toDateString(),
            'status'         => 'cancelada',
        ])->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame('completada', $this->installation->fresh()->status);
    }

    #[Test]
    public function a_clean_installation_can_still_be_cancelled(): void
    {
        $this->putJson("/api/customers/installations/{$this->installation->id}", [
            'scheduled_date' => now()->toDateString(),
            'status'         => 'cancelada',
        ])->assertOk();

        $this->assertSame('cancelada', $this->installation->fresh()->status);
    }

    #[Test]
    public function the_lines_of_a_signed_installation_cannot_be_added_or_removed(): void
    {
        $cable = $this->cable();
        $this->balance($cable, 'user', $this->admin->id, 10);
        $itemId = $this->useMaterial($cable, 4, 'user', $this->admin->id)->assertCreated()->json('item.id');

        $this->installation->forceFill(['signed_at' => now(), 'status' => 'completada'])->save();

        $this->useMaterial($cable, 2, 'user', $this->admin->id)
            ->assertStatus(422)->assertJsonValidationErrors('installation');
        $this->deleteJson("/api/installations/{$this->installation->id}/equipment/{$itemId}")
            ->assertStatus(422)->assertJsonValidationErrors('installation');

        $this->assertSame(1, InstallationEquipment::withoutTenantScope()->count());
        $this->assertEquals(6, (float) InventoryBalance::withoutTenantScope()->firstOrFail()->quantity);
        $this->assertSame(1, $this->movements());

        $this->getJson("/api/installations/{$this->installation->id}/equipment/available")
            ->assertJsonPath('locked.is_locked', true)
            ->assertJsonPath('locked.reason', 'signed');
    }

    #[Test]
    public function a_cancelled_installation_takes_no_new_lines(): void
    {
        $cable = $this->cable();
        $this->balance($cable, 'user', $this->admin->id, 10);
        $this->installation->update(['status' => 'cancelada']);

        $this->useMaterial($cable, 2, 'user', $this->admin->id)
            ->assertStatus(422)->assertJsonValidationErrors('installation');

        $this->assertSame(0, $this->movements());
    }

    #[Test]
    public function removing_a_capture_error_before_signing_still_works_as_before(): void
    {
        $cable = $this->cable();
        $this->balance($cable, 'user', $this->admin->id, 10);
        $itemId = $this->useMaterial($cable, 4, 'user', $this->admin->id)->assertCreated()->json('item.id');

        $this->deleteJson("/api/installations/{$this->installation->id}/equipment/{$itemId}")->assertOk();

        $this->assertEquals(10, (float) InventoryBalance::withoutTenantScope()->firstOrFail()->quantity);
        $this->assertSame(1, InventoryMovement::withoutTenantScope()->where('type', 'devolucion')->count());
    }

    #[Test]
    public function list_rows_carry_what_the_screen_needs_to_explain_the_locks(): void
    {
        $cable = $this->cable();
        $this->balance($cable, 'user', $this->admin->id, 10);
        $this->useMaterial($cable, 4, 'user', $this->admin->id)->assertCreated();

        $row = collect($this->getJson('/api/installations')->assertOk()->json())
            ->firstWhere('id', $this->installation->id);

        $this->assertSame(1, $row['equipment_items_count']);
        $this->assertFalse($row['is_signed']);
        $this->assertSame([], $row['planned_items']);

        $row = collect($this->getJson("/api/customers/{$this->customer->id}/installations")->assertOk()->json())
            ->firstWhere('id', $this->installation->id);
        $this->assertSame(1, $row['equipment_items_count']);
    }
}
