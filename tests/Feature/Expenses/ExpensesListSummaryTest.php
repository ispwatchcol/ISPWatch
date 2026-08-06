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
 * Listado de gastos: paginación y agregados del resumen.
 *
 * El endpoint devolvía TODO sin paginar, y la vista sumaba el array completo en
 * el cliente para pintar "Total del período filtrado". Al paginar, ese mismo
 * cálculo habría pasado a mostrar el total de la página visible bajo ese mismo
 * rótulo: un importe incorrecto con la apariencia de uno correcto. Por eso los
 * agregados se calculan en SQL sobre el filtro completo y viajan en `summary`,
 * en la MISMA respuesta que la página — así es imposible que el total
 * corresponda a un filtro distinto del que produjo la lista.
 */
class ExpensesListSummaryTest extends TestCase
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

    private function expense(array $attributes = []): Expense
    {
        return Expense::create(array_merge([
            'expense_date' => '2026-07-15',
            'amount'       => 1000,
            'status'       => Expense::STATUS_ACTIVE,
        ], $attributes));
    }

    #[Test]
    public function pagina_los_resultados_sin_repetirlos_entre_paginas(): void
    {
        Sanctum::actingAs($this->staff);

        foreach (range(1, 25) as $i) {
            $this->expense(['description' => "Gasto $i"]);
        }

        $response = $this->getJson('/api/expenses?per_page=10');

        $response->assertStatus(200)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('total', 25)
            ->assertJsonPath('last_page', 3);

        // Todos comparten `expense_date`: sin el desempate por id, las páginas
        // podían repetir u omitir gastos.
        $page1 = collect($response->json('data'))->pluck('id');
        $page2 = collect($this->getJson('/api/expenses?per_page=10&page=2')->json('data'))->pluck('id');

        $this->assertCount(10, $page2);
        $this->assertEmpty($page1->intersect($page2), 'Las páginas no deben repetir gastos.');
    }

    #[Test]
    public function el_total_del_resumen_cubre_todo_el_filtro_no_solo_la_pagina(): void
    {
        Sanctum::actingAs($this->staff);

        // 25 gastos de $1.000 = $25.000 en total; la página trae sólo 10.
        foreach (range(1, 25) as $i) {
            $this->expense(['description' => "Gasto $i"]);
        }

        $response = $this->getJson('/api/expenses?per_page=10')->assertStatus(200);

        $this->assertCount(10, $response->json('data'), 'La página debe traer 10 gastos.');

        // Ésta es la regresión que la fase existe para evitar: si el total se
        // calculara sobre `data`, aquí saldría 10.000.
        $this->assertEquals(25000, $response->json('summary.total'));
        $this->assertEquals(25, $response->json('summary.count'));
    }

    #[Test]
    public function el_resumen_excluye_los_anulados_aunque_aparezcan_en_la_lista(): void
    {
        Sanctum::actingAs($this->staff);

        $this->expense(['amount' => 1000, 'description' => 'Activo uno']);
        $this->expense(['amount' => 1000, 'description' => 'Activo dos']);
        $this->expense([
            'amount'      => 500000,
            'description' => 'Anulado carísimo',
            'status'      => Expense::STATUS_VOID,
        ]);

        $response = $this->getJson('/api/expenses')->assertStatus(200);

        // El anulado sigue listándose (queda el rastro de la corrección)...
        $this->assertCount(3, $response->json('data'));

        // ...pero no cuenta como dinero gastado. Es la misma regla que aplicaba
        // la vista con `activeItems`, ahora resuelta en SQL.
        $this->assertEquals(2000, $response->json('summary.total'));
        $this->assertEquals(2, $response->json('summary.count'));
    }

    #[Test]
    public function el_desglose_por_categoria_cubre_todo_el_filtro_y_ordena_de_mayor_a_menor(): void
    {
        Sanctum::actingAs($this->staff);

        $arriendo = ExpenseCategory::create(['name' => 'Arriendo']);
        $viaticos = ExpenseCategory::create(['name' => 'Viáticos']);

        $this->expense(['amount' => 900000, 'expense_category_id' => $arriendo->id]);
        $this->expense(['amount' => 50000,  'expense_category_id' => $viaticos->id]);
        $this->expense(['amount' => 30000,  'expense_category_id' => $viaticos->id]);
        $this->expense(['amount' => 7000]); // sin categoría

        // per_page=1: el desglose no puede salir de la página.
        $breakdown = $this->getJson('/api/expenses?per_page=1')
            ->assertStatus(200)
            ->json('summary.by_category');

        $this->assertSame(['Arriendo', 'Viáticos', 'Sin categoría'], array_column($breakdown, 'name'));
        $this->assertEquals([900000, 80000, 7000], array_column($breakdown, 'total'));
    }

    #[Test]
    public function el_resumen_respeta_los_filtros_aplicados(): void
    {
        Sanctum::actingAs($this->staff);

        $this->expense(['amount' => 1000, 'expense_date' => '2026-07-10']);
        $this->expense(['amount' => 2000, 'expense_date' => '2026-07-20']);
        $this->expense(['amount' => 999000, 'expense_date' => '2026-06-10']); // fuera del rango

        $response = $this->getJson('/api/expenses?date_from=2026-07-01&date_to=2026-07-31')
            ->assertStatus(200);

        $this->assertEquals(3000, $response->json('summary.total'));
        $this->assertEquals(2, $response->json('summary.count'));
    }

    #[Test]
    public function el_resumen_no_suma_gastos_de_otro_tenant(): void
    {
        Sanctum::actingAs($this->staff);

        $this->expense(['amount' => 1000, 'description' => 'Propio']);

        $otroTenant = Tenant::factory()->create();
        $ajeno = new Expense([
            'expense_date' => '2026-07-15',
            'amount'       => 999000,
            'description'  => 'Ajeno',
            'status'       => Expense::STATUS_ACTIVE,
        ]);
        $ajeno->tenant_id = $otroTenant->id;
        $ajeno->save();

        $response = $this->getJson('/api/expenses')->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals(1000, $response->json('summary.total'));
    }
}
