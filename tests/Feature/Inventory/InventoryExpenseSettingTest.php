<?php

namespace Tests\Feature\Inventory;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quién puede decidir que el inventario mueva el balance financiero (KAN-91).
 *
 * La ruta de configuración pide `manage_tenant`. Pero este interruptor concreto
 * hace que dar de alta un equipo genere un gasto, así que exige ADEMÁS
 * `view_expenses`: la decisión de que inventario toque finanzas la toma quien
 * maneja finanzas.
 *
 * El almacenista no pierde nada — ingresar equipos sigue pidiendo sólo
 * `view_inventory`. Lo que queda protegido es la decisión, que se toma una vez,
 * no el trabajo diario.
 *
 * La prueba que más importa acá es la última: que exigir el permiso nuevo NO se
 * derrame sobre el resto de la configuración. Sería muy fácil dejar a un admin
 * sin poder cambiarle el nombre a la empresa por un cambio que no iba de eso.
 */
class InventoryExpenseSettingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
    }

    private function usuarioCon(array $permisos): User
    {
        $role = Role::create([
            'name'        => 'Rol ' . implode('-', $permisos),
            'permissions' => $permisos,
            'tenant_id'   => $this->tenant->id,
        ]);

        return User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
        ]);
    }

    #[Test]
    public function sin_view_expenses_no_puede_encender_el_interruptor(): void
    {
        Sanctum::actingAs($this->usuarioCon(['manage_tenant']));

        $this->patchJson('/api/tenant/config', [
            'inventory_entry_creates_expense' => true,
        ])->assertForbidden();

        $this->assertFalse(
            (bool) $this->tenant->fresh()->inventory_entry_creates_expense,
            'El interruptor no puede haber quedado encendido.'
        );
    }

    #[Test]
    public function con_view_expenses_si_puede(): void
    {
        Sanctum::actingAs($this->usuarioCon(['manage_tenant', 'view_expenses']));

        $this->patchJson('/api/tenant/config', [
            'inventory_entry_creates_expense' => true,
        ])->assertOk();

        $this->assertTrue((bool) $this->tenant->fresh()->inventory_entry_creates_expense);
    }

    #[Test]
    public function elegir_la_categoria_tambien_exige_view_expenses(): void
    {
        // La categoría decide dónde caen los gastos en el balance: no es un
        // ajuste cosmético y va por la misma puerta que el interruptor.
        Sanctum::actingAs($this->usuarioCon(['manage_tenant']));

        $this->patchJson('/api/tenant/config', [
            'inventory_expense_category_id' => null,
        ])->assertForbidden();
    }

    #[Test]
    public function no_se_puede_apuntar_a_la_categoria_de_otra_empresa(): void
    {
        // Si se pudiera, los gastos de esta empresa saldrían clasificados en el
        // catálogo de otra.
        $otra = Tenant::factory()->create();
        $categoriaAjena = new \App\Models\ExpenseCategory(['name' => 'Ajena']);
        $categoriaAjena->tenant_id = $otra->id;
        $categoriaAjena->save();

        Sanctum::actingAs($this->usuarioCon(['manage_tenant', 'view_expenses']));

        $this->patchJson('/api/tenant/config', [
            'inventory_expense_category_id' => $categoriaAjena->id,
        ])->assertStatus(422)->assertJsonValidationErrors('inventory_expense_category_id');
    }

    #[Test]
    public function el_resto_de_la_configuracion_no_empieza_a_exigir_view_expenses(): void
    {
        // La regresión que hay que evitar: dejar a un admin sin poder cambiar el
        // nombre de su empresa por un cambio que no iba de eso.
        Sanctum::actingAs($this->usuarioCon(['manage_tenant']));

        $this->patchJson('/api/tenant/config', [
            'name' => 'Nuevo Nombre S.A.S.',
        ])->assertOk();

        $this->assertSame('Nuevo Nombre S.A.S.', $this->tenant->fresh()->name);
    }
}
