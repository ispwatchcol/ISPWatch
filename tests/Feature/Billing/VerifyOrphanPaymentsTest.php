<?php

namespace Tests\Feature\Billing;

use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * billing:verify-orphan-payments — comprueba la invariante de caja:
 *
 *     todo peso que entró está aplicado a una factura, o está en el saldo a favor
 *
 * Nace de un caso real: se borró una mensualidad YA PAGADA para reemplazarla por
 * otra con otro precio; el borrado devolvió el dinero como saldo a favor y unos
 * ajustes manuales posteriores lo dejaron en cero. El pago quedó sin respaldar
 * nada y ninguna auditoría lo veía.
 */
class VerifyOrphanPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
    }

    private function customer(string $name, float $creditBalance = 0): User
    {
        $customer = User::factory()->create(['tenant_id' => $this->tenant->id]);

        CustomerProfile::create([
            'user_id'        => $customer->id,
            'name'           => $name,
            'last_name'      => 'Prueba',
            'status'         => true,
            'credit_balance' => $creditBalance,
        ]);

        return $customer;
    }

    private function payment(User $customer, float $amount): Payment
    {
        return Payment::create([
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $customer->id,
            'amount'       => $amount,
            'payment_date' => '2026-07-10',
            'method'       => 'Efectivo',
            'status'       => 'completed',
        ]);
    }

    private function invoice(User $customer, float $total, float $balance): Invoice
    {
        return Invoice::create([
            'tenant_id'    => $this->tenant->id,
            'customer_id'  => $customer->id,
            'number'       => 'FAC-' . fake()->unique()->numberBetween(1000, 9999),
            'issue_date'   => '2026-07-01',
            'due_date'     => '2026-07-10',
            'period_start' => '2026-07-01',
            'period_end'   => '2026-07-31',
            'total'        => $total,
            'balance_due'  => $balance,
            'status'       => $balance > 0 ? 'issued' : 'paid',
        ]);
    }

    #[Test]
    public function no_alerta_cuando_el_pago_esta_aplicado_a_una_factura(): void
    {
        $customer = $this->customer('Aplicado');
        $invoice  = $this->invoice($customer, 52500, 0);
        $payment  = $this->payment($customer, 52500);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount'     => 52500,
        ]);

        $this->artisan('billing:verify-orphan-payments --no-mail')
            ->expectsOutputToContain('Todo el dinero recibido está aplicado')
            ->assertExitCode(0);
    }

    #[Test]
    public function no_alerta_cuando_el_pago_quedo_como_saldo_a_favor(): void
    {
        // Un anticipo sin factura pendiente es legítimo: el dinero está, y está
        // localizable en el saldo del cliente.
        $customer = $this->customer('Anticipo', 60000);
        $this->payment($customer, 60000);

        $this->artisan('billing:verify-orphan-payments --no-mail')
            ->assertExitCode(0);
    }

    #[Test]
    public function alerta_cuando_el_pago_no_respalda_factura_ni_saldo(): void
    {
        // Exactamente el caso de la factura pagada que se borró y cuyo saldo
        // alguien ajustó a cero después.
        $customer = $this->customer('Descuadrado', 0);
        $this->payment($customer, 52500);

        $this->artisan('billing:verify-orphan-payments --no-mail')
            ->expectsOutputToContain('Descuadrado')
            ->assertExitCode(1);
    }

    #[Test]
    public function el_saldo_parcial_solo_descuadra_por_la_diferencia(): void
    {
        $customer = $this->customer('Parcial', 20000);
        $this->payment($customer, 52500);

        $filas = app(\App\Services\BillingService::class)->auditOrphanPayments();

        $this->assertCount(1, $filas);
        $this->assertEquals(32500, $filas[0]['suelto']);
        $this->assertEquals(52500, $filas[0]['recibido']);
        $this->assertEquals(20000, $filas[0]['en_saldo']);
    }

    #[Test]
    public function el_filtro_min_deja_pasar_los_descuadres_pequenos(): void
    {
        $customer = $this->customer('Centavos', 52400);
        $this->payment($customer, 52500);   // descuadre de 100

        $this->artisan('billing:verify-orphan-payments --no-mail --min=1000')
            ->assertExitCode(0);

        $this->artisan('billing:verify-orphan-payments --no-mail --min=50')
            ->assertExitCode(1);
    }

    #[Test]
    public function ordena_los_peores_primero(): void
    {
        $this->payment($this->customer('Poco'), 10000);
        $this->payment($this->customer('Mucho'), 90000);
        $this->payment($this->customer('Medio'), 50000);

        $filas = app(\App\Services\BillingService::class)->auditOrphanPayments();

        $this->assertEquals(['Mucho Prueba', 'Medio Prueba', 'Poco Prueba'], array_column($filas, 'cliente'));
    }

    #[Test]
    public function puede_limitarse_a_un_tenant(): void
    {
        $mio = $this->customer('Mio');
        $this->payment($mio, 52500);

        $otroTenant = Tenant::factory()->create();
        $ajeno = User::factory()->create(['tenant_id' => $otroTenant->id]);
        CustomerProfile::create([
            'user_id' => $ajeno->id, 'name' => 'Ajeno', 'last_name' => 'Prueba',
            'status' => true, 'credit_balance' => 0,
        ]);
        Payment::create([
            'tenant_id'    => $otroTenant->id,
            'customer_id'  => $ajeno->id,
            'amount'       => 99000,
            'payment_date' => '2026-07-10',
            'method'       => 'Efectivo',
            'status'       => 'completed',
        ]);

        $filas = app(\App\Services\BillingService::class)->auditOrphanPayments($this->tenant->id);

        $this->assertCount(1, $filas);
        $this->assertEquals('Mio Prueba', $filas[0]['cliente']);
    }

    #[Test]
    public function no_modifica_nada(): void
    {
        $customer = $this->customer('Intacto', 0);
        $payment  = $this->payment($customer, 52500);

        $this->artisan('billing:verify-orphan-payments --no-mail')->assertExitCode(1);

        // Es una auditoría: mira y avisa. Si además "arreglara", nadie podría
        // revisar el criterio antes de que el dinero se mueva.
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'amount' => 52500]);
        $this->assertEquals(
            0,
            (float) CustomerProfile::where('user_id', $customer->id)->value('credit_balance')
        );
        $this->assertDatabaseCount('payment_allocations', 0);
    }
}
