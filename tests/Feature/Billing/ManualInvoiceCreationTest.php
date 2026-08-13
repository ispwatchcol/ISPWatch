<?php

namespace Tests\Feature\Billing;

use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Alta manual de factura: nace con su línea de detalle y consume el saldo a
 * favor del cliente, igual que la generación automática.
 *
 * Faltaban las dos cosas. La consecuencia real: al borrar una factura ya pagada
 * y crear otra en su lugar, el dinero volvía como saldo a favor y la factura
 * nueva nacía debiendo el total — el cliente aparecía debiendo lo que ya había
 * pagado. Y el PDF salía con la tabla de ítems vacía.
 */
class ManualInvoiceCreationTest extends TestCase
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

        Sanctum::actingAs($this->staff);
    }

    private function customer(float $creditBalance = 0): User
    {
        $customer = User::factory()->create(['tenant_id' => $this->tenant->id]);

        CustomerProfile::create([
            'user_id'        => $customer->id,
            'name'           => 'Juan',
            'last_name'      => 'Pérez',
            'status'         => true,
            'credit_balance' => $creditBalance,
        ]);

        return $customer;
    }

    private function payload(User $customer, array $overrides = []): array
    {
        return array_merge([
            'customer_id'  => $customer->id,
            'tenant_id'    => $this->tenant->id,
            'invoice_type' => 'monthly',
            'issue_date'   => '2026-08-01',
            'due_date'     => '2026-08-10',
            'period_start' => '2026-08-01',
            'period_end'   => '2026-08-31',
            'total'        => 68000,
        ], $overrides);
    }

    #[Test]
    public function la_factura_manual_nace_con_su_linea_de_detalle(): void
    {
        $customer = $this->customer();

        $response = $this->postJson('/api/billing/invoices', $this->payload($customer));

        $response->assertStatus(201);

        $invoice = Invoice::find($response->json('id'));
        $items   = $invoice->items;

        $this->assertCount(1, $items, 'La factura manual debe traer su ítem: sin él el PDF sale sin detalle.');
        $this->assertEquals(68000, (float) $items->first()->amount);
        $this->assertEquals('plan', $items->first()->type);
    }

    #[Test]
    public function respeta_el_concepto_que_escribe_el_operador(): void
    {
        $customer = $this->customer();

        $response = $this->postJson('/api/billing/invoices', $this->payload($customer, [
            'description' => 'Mensualidad agosto con descuento pactado',
        ]));

        $this->assertEquals(
            'Mensualidad agosto con descuento pactado',
            Invoice::find($response->json('id'))->items->first()->description
        );
    }

    #[Test]
    public function sin_concepto_lo_deriva_del_tipo_de_factura(): void
    {
        $customer = $this->customer();

        InvoiceType::create([
            'tenant_id' => $this->tenant->id,
            'slug'      => 'monthly',
            'name'      => 'Plan Mensual',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/billing/invoices', $this->payload($customer));

        $this->assertStringContainsString(
            'Plan Mensual',
            Invoice::find($response->json('id'))->items->first()->description
        );
    }

    #[Test]
    public function el_saldo_a_favor_del_cliente_se_aplica_a_la_factura_nueva(): void
    {
        // Es el caso que motivó el cambio: al cliente le devolvieron 52.500 como
        // saldo a favor al borrarle una factura pagada.
        $customer = $this->customer(52500);

        $response = $this->postJson('/api/billing/invoices', $this->payload($customer));

        $response->assertStatus(201);

        $invoice = Invoice::find($response->json('id'));

        $this->assertEquals(68000, (float) $invoice->total, 'El total no cambia: el saldo paga, no descuenta.');
        $this->assertEquals(15500, (float) $invoice->balance_due, 'Debe quedar debiendo sólo la diferencia.');
        $this->assertEquals('partial', $invoice->status);

        $this->assertEquals(
            0,
            (float) CustomerProfile::where('user_id', $customer->id)->value('credit_balance'),
            'El saldo a favor se consumió entero.'
        );
    }

    #[Test]
    public function un_saldo_mayor_que_la_factura_la_deja_pagada_y_conserva_el_resto(): void
    {
        $customer = $this->customer(100000);

        $response = $this->postJson('/api/billing/invoices', $this->payload($customer));

        $invoice = Invoice::find($response->json('id'));

        $this->assertEquals(0, (float) $invoice->balance_due);
        $this->assertEquals('paid', $invoice->status);
        $this->assertEquals(
            32000,
            (float) CustomerProfile::where('user_id', $customer->id)->value('credit_balance')
        );
    }

    #[Test]
    public function una_factura_en_cero_no_crea_item_ni_toca_el_saldo(): void
    {
        $customer = $this->customer(50000);

        $response = $this->postJson('/api/billing/invoices', $this->payload($customer, ['total' => 0]));

        $invoice = Invoice::find($response->json('id'));

        $this->assertCount(0, $invoice->items, 'Un ítem de $0 sólo ensucia el PDF.');
        $this->assertEquals(
            50000,
            (float) CustomerProfile::where('user_id', $customer->id)->value('credit_balance'),
            'Sin nada que cobrar, el saldo del cliente no se toca.'
        );
    }
}
