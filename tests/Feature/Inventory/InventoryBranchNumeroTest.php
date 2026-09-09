<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryBranch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El campo "Número" de una sucursal tiene que aguantar un teléfono.
 *
 * `inventory_branch.numero` nació como `integer` (int4, tope 2.147.483.647) y
 * todo celular colombiano lo desborda: 3001234567 es 3.001.234.567. Como la
 * interfaz llama al campo "Número" sin más, la gente escribía ahí el teléfono
 * de la sucursal y guardar reventaba con SQLSTATE[22003] — un 500 opaco que en
 * pantalla sólo decía "No se pudo guardar".
 *
 * Sin estas pruebas el campo puede volver en silencio a ser numérico y el
 * fallo reaparece sólo cuando un cliente real escribe su celular.
 */
class InventoryBranchNumeroTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $role = Role::create([
            'name' => 'Admin',
            'permissions' => ['*'],
            'tenant_id' => $this->tenant->id,
        ]);

        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
        ]);

        Sanctum::actingAs($this->admin);
    }

    #[Test]
    public function guarda_una_sucursal_con_un_celular_colombiano(): void
    {
        // 3.001.234.567 — por encima del tope de int4. Este es el caso que
        // rompía en producción.
        $this->postJson('/api/inventory-branches', [
            'name' => 'Chaguaní',
            'dir' => 'Calle 3 # 2-40',
            'numero' => '3001234567',
        ])->assertCreated();

        $this->assertDatabaseHas('inventory_branch', [
            'name' => 'Chaguaní',
            'numero' => '3001234567',
            'tenant_id' => $this->tenant->id,
        ]);
    }

    #[Test]
    public function conserva_indicativo_separadores_y_ceros_a_la_izquierda(): void
    {
        // Lo que se pierde al guardar un teléfono como número: el "+", los
        // espacios y el cero inicial del indicativo fijo.
        $this->postJson('/api/inventory-branches', [
            'name' => 'Sucursal Centro',
            'numero' => '+57 601 123 4567',
        ])->assertCreated();

        $this->assertDatabaseHas('inventory_branch', [
            'name' => 'Sucursal Centro',
            'numero' => '+57 601 123 4567',
        ]);
    }

    #[Test]
    public function sigue_aceptando_un_numero_de_sucursal_corriente(): void
    {
        // El uso original del campo — una sucursal numerada — no se rompe.
        $this->postJson('/api/inventory-branches', [
            'name' => 'Bodega 2',
            'numero' => '2',
        ])->assertCreated();

        $this->assertDatabaseHas('inventory_branch', ['name' => 'Bodega 2', 'numero' => '2']);
    }

    #[Test]
    public function un_valor_demasiado_largo_se_rechaza_con_422_y_no_con_500(): void
    {
        // Que el límite lo ponga la validación y no el motor de la base: un 422
        // le dice al usuario qué corregir, un 500 no le dice nada.
        $this->postJson('/api/inventory-branches', [
            'name' => 'Sucursal',
            'numero' => str_repeat('9', 31),
        ])->assertStatus(422)->assertJsonValidationErrors('numero');
    }

    #[Test]
    public function el_numero_es_opcional(): void
    {
        $this->postJson('/api/inventory-branches', ['name' => 'Sin teléfono'])
            ->assertCreated();

        $this->assertDatabaseHas('inventory_branch', [
            'name' => 'Sin teléfono',
            'numero' => null,
        ]);
    }

    #[Test]
    public function editar_una_sucursal_tambien_acepta_el_celular(): void
    {
        $branch = new InventoryBranch(['name' => 'Chaguaní']);
        $branch->tenant_id = $this->tenant->id;
        $branch->save();

        $this->putJson("/api/inventory-branches/{$branch->id}", [
            'name' => 'Chaguaní',
            'numero' => '3109876543',
        ])->assertOk();

        $this->assertDatabaseHas('inventory_branch', [
            'id' => $branch->id,
            'numero' => '3109876543',
        ]);
    }
}
