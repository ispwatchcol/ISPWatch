<?php

namespace Tests\Feature\Expenses;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Listado de gastos: búsqueda de texto y su combinación con los filtros que ya
 * existían.
 *
 * El listado sólo permitía acotar por fecha, categoría y estado: para encontrar
 * un gasto puntual del que no se recuerda la fecha había que recorrer la tabla a
 * ojo. La búsqueda cubre lo que la vista muestra como texto libre (descripción,
 * observaciones y beneficiario).
 */
class ExpensesListFilterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $role = Role::create(['name' => 'Admin', 'permissions' => ['*']]);

        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
        ]);
    }

    /** El tenant_id lo pone el trait BelongsToTenant desde el usuario autenticado. */
    private function expense(array $attributes = []): Expense
    {
        return Expense::create(array_merge([
            'expense_date' => '2026-07-15',
            'amount'       => 50000,
            'status'       => Expense::STATUS_ACTIVE,
        ], $attributes));
    }

    #[Test]
    public function busca_por_descripcion_sin_distinguir_mayusculas(): void
    {
        Sanctum::actingAs($this->staff);

        $this->expense(['description' => 'Arriendo de la oficina']);
        $this->expense(['description' => 'Combustible camioneta']);

        // En PostgreSQL `LIKE` distingue mayúsculas: la búsqueda usa las macros
        // whereLike/orWhereLike justamente para que "arriendo" encuentre
        // "Arriendo" también en producción, no sólo en SQLite.
        $this->getJson('/api/expenses?search=arriendo')
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.description', 'Arriendo de la oficina');
    }

    #[Test]
    public function busca_por_observaciones(): void
    {
        Sanctum::actingAs($this->staff);

        $this->expense(['description' => 'Compra varios', 'notes' => 'Factura 8891 del proveedor']);
        $this->expense(['description' => 'Otro gasto', 'notes' => 'Sin soporte']);

        $this->getJson('/api/expenses?search=8891')
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.description', 'Compra varios');
    }

    #[Test]
    public function busca_por_beneficiario(): void
    {
        Sanctum::actingAs($this->staff);

        $tecnico = User::factory()->create([
            'tenant_id'     => $this->tenant->id,
            'user_name'     => 'Andrés',
            'user_lastname' => 'Rojas',
        ]);

        $this->expense(['description' => 'Viáticos', 'user_id' => $tecnico->id]);
        $this->expense(['description' => 'Arriendo']);

        $this->getJson('/api/expenses?search=' . urlencode('Rojas'))
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.description', 'Viáticos');
    }

    #[Test]
    public function la_busqueda_se_combina_con_los_filtros_existentes(): void
    {
        Sanctum::actingAs($this->staff);

        $servicios = ExpenseCategory::create(['name' => 'Servicios públicos']);
        $otra      = ExpenseCategory::create(['name' => 'Transporte']);

        // Mismo texto, distinta categoría y distinta fecha: sólo uno cumple las
        // tres condiciones a la vez.
        $this->expense([
            'description'         => 'Pago energía sede',
            'expense_category_id' => $servicios->id,
            'expense_date'        => '2026-07-10',
        ]);
        $this->expense([
            'description'         => 'Pago energía sede',
            'expense_category_id' => $otra->id,
            'expense_date'        => '2026-07-10',
        ]);
        $this->expense([
            'description'         => 'Pago energía sede',
            'expense_category_id' => $servicios->id,
            'expense_date'        => '2026-06-10',
        ]);

        $this->getJson(
            '/api/expenses?search=' . urlencode('energía')
            . '&expense_category_id=' . $servicios->id
            . '&date_from=2026-07-01&date_to=2026-07-31'
        )
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.expense_category_id', $servicios->id);
    }

    #[Test]
    public function la_busqueda_no_devuelve_gastos_de_otro_tenant(): void
    {
        Sanctum::actingAs($this->staff);

        $this->expense(['description' => 'Arriendo propio']);

        $otroTenant = Tenant::factory()->create();
        $ajeno = new Expense([
            'expense_date' => '2026-07-15',
            'amount'       => 50000,
            'description'  => 'Arriendo ajeno',
            'status'       => Expense::STATUS_ACTIVE,
        ]);
        // tenant_id no es fillable: se asigna directo para saltarse el hook que
        // lo tomaría del usuario autenticado.
        $ajeno->tenant_id = $otroTenant->id;
        $ajeno->save();

        $this->getJson('/api/expenses?search=arriendo')
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.description', 'Arriendo propio');
    }

    #[Test]
    public function sin_search_el_listado_sigue_devolviendo_todo(): void
    {
        Sanctum::actingAs($this->staff);

        $this->expense(['description' => 'Uno']);
        $this->expense(['description' => 'Dos']);
        $this->expense(['description' => 'Tres']);

        $this->getJson('/api/expenses')
            ->assertStatus(200)
            ->assertJsonCount(3);
    }
}
