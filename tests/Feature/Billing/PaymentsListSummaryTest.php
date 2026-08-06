<?php

namespace Tests\Feature\Billing;

use App\Models\CustomerProfile;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Listado de recaudos: agregados del filtro completo.
 *
 * La vista decía "de X recaudos" pero no cuánto sumaban: para saber cuánto se
 * recaudó en un rango había que sumar a mano. Misma convención que Gastos y
 * Facturación: clave `summary` en la MISMA respuesta que la página.
 *
 * A diferencia de facturas y gastos, aquí no se excluye ningún estado: un
 * recaudo no se anula, se elimina (y al eliminarlo se revierten sus
 * asignaciones), así que lo que está en la tabla es dinero recibido.
 */
class PaymentsListSummaryTest extends TestCase
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

    private function payment(array $attributes = []): Payment
    {
        return Payment::create(array_merge([
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $this->customer->id,
            'amount'       => 1000,
            'payment_date' => '2026-07-15',
            'method'       => 'cash',
            'status'       => 'completed',
        ], $attributes));
    }

    #[Test]
    public function el_total_cubre_todo_el_filtro_no_solo_la_pagina(): void
    {
        Sanctum::actingAs($this->staff);

        foreach (range(1, 25) as $i) {
            $this->payment(['reference' => "REF-$i"]);
        }

        $response = $this->getJson('/api/billing/payments?per_page=10')->assertStatus(200);

        $this->assertCount(10, $response->json('data'), 'La página trae 10 recaudos.');

        // Si el total se calculara sobre `data`, aquí saldría 10.000.
        $this->assertEquals(25000, $response->json('summary.total'));
        $this->assertEquals(25, $response->json('summary.count'));
    }

    #[Test]
    public function el_resumen_respeta_el_rango_de_fechas(): void
    {
        Sanctum::actingAs($this->staff);

        $this->payment(['amount' => 1000, 'payment_date' => '2026-07-10']);
        $this->payment(['amount' => 2000, 'payment_date' => '2026-07-20']);
        $this->payment(['amount' => 999000, 'payment_date' => '2026-06-10']);

        $response = $this->getJson('/api/billing/payments?date_from=2026-07-01&date_to=2026-07-31')
            ->assertStatus(200);

        $this->assertEquals(3000, $response->json('summary.total'));
        $this->assertEquals(2, $response->json('summary.count'));
    }

    #[Test]
    public function el_resumen_respeta_el_filtro_de_metodo(): void
    {
        Sanctum::actingAs($this->staff);

        $this->payment(['amount' => 1000, 'method' => 'nequi']);
        $this->payment(['amount' => 500000, 'method' => 'cash']);

        $response = $this->getJson('/api/billing/payments?method=nequi')->assertStatus(200);

        $this->assertEquals(1000, $response->json('summary.total'));
        $this->assertEquals(1, $response->json('summary.count'));
    }

    #[Test]
    public function no_suma_recaudos_de_otro_tenant(): void
    {
        Sanctum::actingAs($this->staff);

        $this->payment(['amount' => 1000]);

        $otroTenant = Tenant::factory()->create();
        $ajeno = User::factory()->create(['tenant_id' => $otroTenant->id]);
        Payment::create([
            'tenant_id'    => $otroTenant->id,
            'customer_id'  => $ajeno->id,
            'amount'       => 999000,
            'payment_date' => '2026-07-15',
            'method'       => 'cash',
            'status'       => 'completed',
        ]);

        $response = $this->getJson('/api/billing/payments')->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals(1000, $response->json('summary.total'));
    }
}
