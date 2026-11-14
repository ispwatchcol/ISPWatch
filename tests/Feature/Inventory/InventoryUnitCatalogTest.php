<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryStock;
use App\Models\InventoryUnit;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Catálogo de unidades de medida del inventario (P-92).
 *
 * «No hay un apartado donde se agreguen los valores como metros, unidad»: la
 * unidad era texto libre dentro del producto. Ahora es una lista por empresa,
 * con unas básicas del sistema, y el producto elige de ella.
 */
class InventoryUnitCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $role = Role::create(['name' => 'Admin', 'permissions' => ['*'], 'tenant_id' => $this->tenant->id]);
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $role->id]);

        Sanctum::actingAs($this->admin);
    }

    #[Test]
    public function the_catalog_starts_with_the_built_in_units(): void
    {
        $names = collect($this->getJson('/api/inventory-units')->assertOk()->json())
            ->where('built_in', true)->pluck('name')->all();

        $this->assertSame(['unidad', 'metro', 'rollo', 'caja', 'paquete'], $names);
    }

    #[Test]
    public function a_tenant_adds_its_own_unit_and_a_product_can_use_it(): void
    {
        $this->postJson('/api/inventory-units', ['name' => 'bolsa x100', 'decimals' => 0])->assertCreated();

        $this->postJson('/api/inventory-stock', [
            'brand' => 'GENÉRICO', 'model' => 'AMARRES', 'is_serialized' => false, 'unit' => 'bolsa x100',
            'quantity_decimals' => 0,
        ])->assertCreated();

        $row = collect($this->getJson('/api/inventory-units')->json())->firstWhere('name', 'bolsa x100');
        $this->assertFalse($row['built_in']);
        $this->assertSame(1, $row['products_count']);
    }

    #[Test]
    public function a_product_cannot_use_a_unit_outside_the_catalog(): void
    {
        $this->postJson('/api/inventory-stock', [
            'brand' => 'GENÉRICO', 'model' => 'CABLE', 'is_serialized' => false, 'unit' => 'mtrs.',
        ])->assertStatus(422)->assertJsonValidationErrors('unit');

        // Las básicas valen sin importar mayúsculas.
        $this->postJson('/api/inventory-stock', [
            'brand' => 'GENÉRICO', 'model' => 'CABLE', 'is_serialized' => false, 'unit' => 'Metro',
        ])->assertCreated();
    }

    #[Test]
    public function an_old_product_keeps_its_out_of_catalog_unit_while_it_is_not_changed(): void
    {
        $viejo = InventoryStock::create(['brand' => 'X', 'model' => 'FIBRA', 'is_serialized' => false, 'unit' => 'mts']);

        $this->putJson("/api/inventory-stock/{$viejo->id}", [
            'brand' => 'X', 'model' => 'FIBRA', 'price' => 900, 'is_serialized' => false, 'unit' => 'mts',
        ])->assertOk();

        $this->assertEquals(900, (float) $viejo->fresh()->price);
    }

    #[Test]
    public function serialized_products_do_not_need_a_unit_from_the_catalog(): void
    {
        $this->postJson('/api/inventory-stock', [
            'brand' => 'TP-LINK', 'model' => 'ARCHER', 'is_serialized' => true, 'unit' => null,
        ])->assertCreated();
    }

    #[Test]
    public function built_in_units_and_duplicates_cannot_be_created(): void
    {
        $this->postJson('/api/inventory-units', ['name' => 'Metro'])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->postJson('/api/inventory-units', ['name' => 'kilo'])->assertCreated();
        $this->postJson('/api/inventory-units', ['name' => 'KILO'])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    #[Test]
    public function renaming_a_unit_renames_it_in_the_products_that_use_it(): void
    {
        $unit = InventoryUnit::create(['name' => 'mts', 'decimals' => 2]);
        $a = InventoryStock::create(['brand' => 'X', 'model' => 'A', 'is_serialized' => false, 'unit' => 'mts']);
        $b = InventoryStock::create(['brand' => 'X', 'model' => 'B', 'is_serialized' => false, 'unit' => 'MTS']);
        $c = InventoryStock::create(['brand' => 'X', 'model' => 'C', 'is_serialized' => false, 'unit' => 'unidad']);

        $this->putJson("/api/inventory-units/{$unit->id}", ['name' => 'metros', 'decimals' => 2])
            ->assertOk()
            ->assertJsonPath('products_renamed', 2);

        $this->assertSame('metros', $a->fresh()->unit);
        $this->assertSame('metros', $b->fresh()->unit);
        $this->assertSame('unidad', $c->fresh()->unit);
    }

    #[Test]
    public function a_unit_in_use_cannot_be_deleted(): void
    {
        $unit = InventoryUnit::create(['name' => 'kilo', 'decimals' => 2]);
        InventoryStock::create(['brand' => 'X', 'model' => 'ALAMBRE', 'is_serialized' => false, 'unit' => 'kilo']);

        $this->deleteJson("/api/inventory-units/{$unit->id}")->assertStatus(409)->assertJsonPath('products_count', 1);
        $this->assertNotNull($unit->fresh());

        $libre = InventoryUnit::create(['name' => 'par']);
        $this->deleteJson("/api/inventory-units/{$libre->id}")->assertOk();
        $this->assertNull($libre->fresh());
    }

    #[Test]
    public function units_are_isolated_between_tenants(): void
    {
        $ajeno = Tenant::factory()->create();
        $role  = Role::create(['name' => 'Admin', 'permissions' => ['*'], 'tenant_id' => $ajeno->id]);
        $otro  = User::factory()->create(['tenant_id' => $ajeno->id, 'role_id' => $role->id]);

        Sanctum::actingAs($otro);
        $suya = InventoryUnit::create(['name' => 'bulto']);

        Sanctum::actingAs($this->admin);

        $this->assertNull(collect($this->getJson('/api/inventory-units')->json())->firstWhere('name', 'bulto'));
        $this->putJson("/api/inventory-units/{$suya->id}", ['name' => 'robado'])->assertNotFound();
        $this->deleteJson("/api/inventory-units/{$suya->id}")->assertNotFound();
        $this->postJson('/api/inventory-stock', [
            'brand' => 'X', 'model' => 'Y', 'is_serialized' => false, 'unit' => 'bulto',
        ])->assertStatus(422)->assertJsonValidationErrors('unit');
    }

    #[Test]
    public function the_migration_brings_the_units_already_in_use_into_each_tenants_catalog(): void
    {
        $migration = require database_path('migrations/2026_11_13_000002_create_inventory_units_table.php');
        $migration->down();

        $otro = Tenant::factory()->create()->id;
        $fila = fn (int $tenant, string $model, ?string $unit, bool $serial = false, int $dec = 2) => DB::table('inventory_stock')->insert([
            'tenant_id' => $tenant, 'brand' => 'X', 'model' => $model, 'is_serialized' => $serial,
            'unit' => $unit, 'quantity_decimals' => $dec, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $fila($this->tenant->id, 'FIBRA', 'mts');
        $fila($this->tenant->id, 'CABLE', 'MTS', false, 1);   // misma unidad, otra grafía: una sola fila
        $fila($this->tenant->id, 'RJ45', 'unidad', false, 0); // básica: no entra
        $fila($this->tenant->id, 'LDF', 'pieza', true);       // serializado: no entra
        $fila($otro, 'ALAMBRE', 'kilo');

        $migration->up();

        $filas = DB::table('inventory_units')->orderBy('tenant_id')->get(['tenant_id', 'name']);
        $this->assertCount(2, $filas);
        $this->assertSame([(int) $this->tenant->id, 'mts'], [(int) $filas[0]->tenant_id, mb_strtolower($filas[0]->name)]);
        $this->assertSame([(int) $otro, 'kilo'], [(int) $filas[1]->tenant_id, $filas[1]->name]);
    }

    #[Test]
    public function a_support_only_user_can_read_but_not_write_units(): void
    {
        $role = Role::create(['name' => 'Técnico', 'permissions' => ['view_support'], 'tenant_id' => $this->tenant->id]);
        $tech = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $role->id]);
        Sanctum::actingAs($tech);

        $this->getJson('/api/inventory-units')->assertOk();
        $this->postJson('/api/inventory-units', ['name' => 'kilo'])->assertForbidden();
    }
}
