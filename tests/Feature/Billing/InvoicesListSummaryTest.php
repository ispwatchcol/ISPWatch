<?php

namespace Tests\Feature\Billing;

use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Listado de facturas: agregados del filtro completo.
 *
 * La vista mostraba el conteo de registros pero ningún total en dinero: había
 * que sumar a mano para saber cuánto se facturó o cuánto falta por cobrar. Los
 * totales viajan en `summary`, dentro de la MISMA respuesta que la página —
 * misma convención que Gastos— para que sea imposible que la cifra corresponda a
 * un filtro distinto del que produjo la lista.
 */
class InvoicesListSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $staff;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $role = Role::create(['name' => 'Admin', 'permissions' => ['*']]);

        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
        ]);

        $this->customer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        CustomerProfile::create([
            'user_id'   => $this->customer->id,
            'name'      => 'Juan',
            'last_name' => 'Pérez',
            'status'    => true,
        ]);
    }

    private function invoice(array $attributes = []): Invoice
    {
        static $n = 0;
        $n++;

        return Invoice::create(array_merge([
            'customer_id'  => $this->customer->id,
            'tenant_id'    => $this->tenant->id,
            'number'       => 'INV-TEST-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'issue_date'   => '2026-07-01',
            'due_date'     => '2026-07-10',
            'period_start' => '2026-07-01',
            'period_end'   => '2026-07-31',
            'total'        => 10000,
            'balance_due'  => 10000,
            'status'       => 'issued',
        ], $attributes));
    }

    #[Test]
    public function el_total_cubre_todo_el_filtro_no_solo_la_pagina(): void
    {
        Sanctum::actingAs($this->staff);

        // 25 facturas de $10.000: la página trae 20, el total debe ser de las 25.
        foreach (range(1, 25) as $i) {
            $this->invoice();
        }

        $response = $this->getJson('/api/billing/invoices')->assertStatus(200);

        $this->assertCount(20, $response->json('data'), 'La página trae 20 facturas.');

        // Si el total se calculara sobre `data`, aquí saldría 200.000.
        $this->assertEquals(250000, $response->json('summary.total'));
        $this->assertEquals(25, $response->json('summary.count'));
    }

    #[Test]
    public function las_facturas_anuladas_no_suman_en_los_totales(): void
    {
        Sanctum::actingAs($this->staff);

        $this->invoice(['total' => 10000, 'balance_due' => 10000]);
        $this->invoice(['total' => 500000, 'balance_due' => 500000, 'status' => 'void']);
        $this->invoice(['total' => 700000, 'balance_due' => 700000, 'status' => 'cancelled']);

        $response = $this->getJson('/api/billing/invoices')->assertStatus(200);

        // Las anuladas siguen listándose (existieron), pero no son dinero facturado.
        $this->assertCount(3, $response->json('data'));
        $this->assertEquals(10000, $response->json('summary.total'));
        $this->assertEquals(10000, $response->json('summary.balance_due'));
        $this->assertEquals(1, $response->json('summary.count'));
    }

    #[Test]
    public function el_saldo_pendiente_suma_solo_lo_que_falta_por_cobrar(): void
    {
        Sanctum::actingAs($this->staff);

        $this->invoice(['total' => 50000, 'balance_due' => 0,     'status' => 'paid']);
        $this->invoice(['total' => 50000, 'balance_due' => 20000, 'status' => 'partial']);
        $this->invoice(['total' => 30000, 'balance_due' => 30000, 'status' => 'overdue']);

        $response = $this->getJson('/api/billing/invoices')->assertStatus(200);

        $this->assertEquals(130000, $response->json('summary.total'));
        $this->assertEquals(50000, $response->json('summary.balance_due'));
    }

    #[Test]
    public function el_resumen_respeta_el_filtro_de_estado(): void
    {
        Sanctum::actingAs($this->staff);

        $this->invoice(['total' => 10000, 'balance_due' => 0,     'status' => 'paid']);
        $this->invoice(['total' => 90000, 'balance_due' => 90000, 'status' => 'overdue']);

        $response = $this->getJson('/api/billing/invoices?status=overdue')->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals(90000, $response->json('summary.total'));
        $this->assertEquals(1, $response->json('summary.count'));
    }

    #[Test]
    public function el_resumen_respeta_el_filtro_de_periodo(): void
    {
        Sanctum::actingAs($this->staff);

        $this->invoice(['total' => 10000, 'period_start' => '2026-07-01']);
        $this->invoice(['total' => 999000, 'period_start' => '2026-06-01']);

        $response = $this->getJson('/api/billing/invoices?period=2026-07')->assertStatus(200);

        $this->assertEquals(10000, $response->json('summary.total'));
        $this->assertEquals(1, $response->json('summary.count'));
    }

    #[Test]
    public function no_suma_facturas_de_otro_tenant(): void
    {
        Sanctum::actingAs($this->staff);

        $this->invoice(['total' => 10000]);

        $otroTenant = Tenant::factory()->create();
        $ajeno = User::factory()->create(['tenant_id' => $otroTenant->id]);
        Invoice::create([
            'customer_id'  => $ajeno->id,
            'tenant_id'    => $otroTenant->id,
            'number'       => 'INV-AJENA-0001',
            'issue_date'   => '2026-07-01',
            'due_date'     => '2026-07-10',
            'period_start' => '2026-07-01',
            'period_end'   => '2026-07-31',
            'total'        => 999000,
            'balance_due'  => 999000,
            'status'       => 'issued',
        ]);

        $response = $this->getJson('/api/billing/invoices')->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals(10000, $response->json('summary.total'));
    }

    #[Test]
    public function las_paginas_no_repiten_facturas_pese_a_compartir_fecha_de_emision(): void
    {
        Sanctum::actingAs($this->staff);

        // Toda la facturación mensual comparte `issue_date`: sin desempate por
        // id, las páginas podían repetir u omitir facturas.
        foreach (range(1, 25) as $i) {
            $this->invoice(['issue_date' => '2026-07-01']);
        }

        $page1 = collect($this->getJson('/api/billing/invoices')->json('data'))->pluck('id');
        $page2 = collect($this->getJson('/api/billing/invoices?page=2')->json('data'))->pluck('id');

        $this->assertCount(20, $page1);
        $this->assertCount(5, $page2);
        $this->assertEmpty($page1->intersect($page2), 'Las páginas no deben repetir facturas.');
    }
}
