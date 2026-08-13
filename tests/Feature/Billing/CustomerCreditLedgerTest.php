<?php

namespace Tests\Feature\Billing;

use App\Models\CustomerCredit;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Libro de movimientos del saldo a favor.
 *
 * El caso real que originó esto: una clienta con plan de $60.000 que paga
 * $70.000 en efectivo todos los meses. El excedente se acumulaba como saldo, el
 * sistema se lo descontaba solo de la siguiente factura, y en el mostrador
 * aparecía "$36.000 a pagar" sin ninguna fila que explicara los $24.000 de
 * diferencia. La factura decía 60.000, la caja cobraba 36.000 y el libro no
 * cuadraba con ninguno de los dos.
 *
 * Invariante que se verifica en todos los casos:
 *     SUM(customer_credits.amount) == customer_profile.credit_balance
 */
class CustomerCreditLedgerTest extends TestCase
{
    use RefreshDatabase;

    private BillingService $billing;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->billing = app(BillingService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function customer(Tenant $tenant): User
    {
        $this->seq++;

        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        CustomerProfile::create([
            'user_id'        => $user->id,
            'name'           => "Cliente{$this->seq}",
            'last_name'      => "Apellido{$this->seq}",
            'status'         => true,
            'credit_balance' => 0,
        ]);

        return $user;
    }

    private function invoice(Tenant $tenant, User $customer, float $total): Invoice
    {
        return Invoice::create([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $customer->id,
            'number'       => uniqid('INV-'),
            'invoice_type' => Invoice::TYPE_MONTHLY,
            'issue_date'   => now()->subDays(10),
            'due_date'     => now()->addDays(5),
            'period_start' => now()->startOfMonth(),
            'period_end'   => now()->endOfMonth(),
            'subtotal'     => $total,
            'total'        => $total,
            'balance_due'  => $total,
            'status'       => 'issued',
        ]);
    }

    private function pay(Tenant $tenant, User $customer, float $amount): Payment
    {
        return $this->billing->registerPayment([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $customer->id,
            'amount'       => $amount,
            'payment_date' => now()->toDateString(),
            'method'       => 'cash',
        ]);
    }

    private function balance(User $customer): float
    {
        return (float) CustomerProfile::where('user_id', $customer->id)->value('credit_balance');
    }

    private function assertLedgerMatchesCache(User $customer): void
    {
        $this->assertEqualsWithDelta(
            $this->balance($customer),
            CustomerCredit::ledgerBalanceFor($customer->id),
            0.01,
            'El libro de movimientos y el credit_balance cacheado divergieron.'
        );
    }

    // ── El excedente entra al libro ──────────────────────────────────────

    #[Test]
    public function un_pago_en_exceso_deja_asiento_de_saldo_ganado(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);
        $this->invoice($tenant, $customer, 60000);

        $payment = $this->pay($tenant, $customer, 70000);

        $movement = CustomerCredit::where('customer_id', $customer->id)->first();

        $this->assertNotNull($movement, 'El excedente no dejó movimiento en el libro.');
        $this->assertSame(CustomerCredit::TYPE_EARNED, $movement->type);
        $this->assertEquals(10000, (float) $movement->amount);
        $this->assertEquals($payment->id, $movement->from_payment_id);
        $this->assertEquals(10000, (float) $movement->balance_after);
        $this->assertLedgerMatchesCache($customer);
    }

    #[Test]
    public function aplicar_saldo_a_una_factura_deja_asiento_con_la_factura(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);

        // Mes 1: paga de más y queda saldo.
        $this->invoice($tenant, $customer, 60000);
        $this->pay($tenant, $customer, 70000);
        $this->assertEquals(10000, $this->balance($customer));

        // Mes 2: la factura nueva consume el saldo.
        $segunda = $this->invoice($tenant, $customer, 60000);
        $profile = CustomerProfile::where('user_id', $customer->id)->first();
        $this->invokeApplyCredit($segunda, $profile);

        $segunda->refresh();
        $this->assertEquals(50000, (float) $segunda->balance_due, 'La factura debió bajar por el saldo aplicado.');

        $applied = CustomerCredit::where('customer_id', $customer->id)
            ->where('type', CustomerCredit::TYPE_APPLIED)
            ->first();

        $this->assertNotNull($applied, 'Aplicar el saldo no dejó asiento: es el agujero que originó el problema.');
        $this->assertEquals(-10000, (float) $applied->amount);
        $this->assertEquals($segunda->id, $applied->to_invoice_id);
        $this->assertEquals(0, $this->balance($customer));
        $this->assertLedgerMatchesCache($customer);
    }

    // ── El bug: revertir un pago no puede borrar saldo ajeno ─────────────

    #[Test]
    public function anular_un_pago_cuyo_excedente_ya_se_consumio_no_destruye_saldo_de_otros_pagos(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);

        // Pago 1: deja 10.000 de saldo.
        $this->invoice($tenant, $customer, 60000);
        $primerPago = $this->pay($tenant, $customer, 70000);

        // Ese saldo se consume en la factura del mes siguiente.
        $segunda = $this->invoice($tenant, $customer, 60000);
        $profile = CustomerProfile::where('user_id', $customer->id)->first();
        $this->invokeApplyCredit($segunda, $profile);
        $this->assertEquals(0, $this->balance($customer));

        // Pago 2: paga la factura rebajada y deja 20.000 nuevos de saldo.
        $this->pay($tenant, $customer, 70000);
        $this->assertEquals(20000, $this->balance($customer));

        // Ahora se anula el PRIMER pago, cuyo excedente ya se había gastado.
        //
        // El código viejo hacía max(0, saldo - excedente) = 20.000 - 10.000 y
        // dejaba 10.000: se llevaba por delante saldo que venía del segundo
        // pago y que el cliente sí tenía. Ese dinero desaparecía sin traza.
        $this->billing->deletePayment($primerPago);

        $this->assertEquals(
            20000,
            $this->balance($customer),
            'Anular un pago cuyo excedente ya se consumió no puede tocar el saldo de otros pagos.'
        );
        $this->assertLedgerMatchesCache($customer);
    }

    #[Test]
    public function anular_un_pago_devuelve_el_excedente_que_seguia_vivo(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);

        $this->invoice($tenant, $customer, 60000);
        $pago = $this->pay($tenant, $customer, 70000);

        $this->assertEquals(10000, $this->balance($customer));

        // Nadie consumió el excedente: al anular el pago tiene que irse entero.
        $this->billing->deletePayment($pago);

        $this->assertEquals(0, $this->balance($customer));
        $this->assertLedgerMatchesCache($customer);
    }

    #[Test]
    public function el_ajuste_manual_queda_registrado_con_motivo(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);

        CustomerCredit::adjust($customer->id, 15000, 0, 'Corrección de caja del 11 de agosto');

        $movement = CustomerCredit::where('customer_id', $customer->id)->first();

        $this->assertSame(CustomerCredit::TYPE_ADJUSTED, $movement->type);
        $this->assertEquals(15000, (float) $movement->amount);
        $this->assertSame('Corrección de caja del 11 de agosto', $movement->reason);
        $this->assertEquals(15000, $this->balance($customer));
        $this->assertLedgerMatchesCache($customer);
    }

    #[Test]
    public function el_caso_de_alba_tres_meses_pagando_de_mas_cuadra_al_peso(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);
        $profile  = CustomerProfile::where('user_id', $customer->id)->first();

        // Junio: factura de 56.000 (el precio mal cargado), paga 70.000.
        $junio = $this->invoice($tenant, $customer, 56000);
        $this->pay($tenant, $customer, 70000);
        $this->assertEquals(14000, $this->balance($customer));

        // Julio: factura de 60.000 ya corregida; el saldo la rebaja a 46.000.
        $julio = $this->invoice($tenant, $customer, 60000);
        $this->invokeApplyCredit($julio, $profile->refresh());
        $julio->refresh();
        $this->assertEquals(46000, (float) $julio->balance_due);
        $this->pay($tenant, $customer, 70000);
        $this->assertEquals(24000, $this->balance($customer));

        // Agosto: el saldo la rebaja a 36.000 — el número que vio la cajera.
        $agosto = $this->invoice($tenant, $customer, 60000);
        $this->invokeApplyCredit($agosto, $profile->refresh());
        $agosto->refresh();
        $this->assertEquals(36000, (float) $agosto->balance_due);
        $this->pay($tenant, $customer, 70000);
        $this->assertEquals(34000, $this->balance($customer));

        // Y ahora sí queda escrito de dónde salió cada peso.
        $aplicados = CustomerCredit::where('customer_id', $customer->id)
            ->where('type', CustomerCredit::TYPE_APPLIED)
            ->orderBy('id')
            ->pluck('amount')
            ->map(fn ($a) => (float) $a)
            ->all();

        $this->assertEquals([-14000.0, -24000.0], $aplicados);
        $this->assertLedgerMatchesCache($customer);
    }

    #[Test]
    public function borrar_una_factura_pagada_con_saldo_devuelve_el_saldo(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);
        $profile  = CustomerProfile::where('user_id', $customer->id)->first();

        $this->invoice($tenant, $customer, 60000);
        $this->pay($tenant, $customer, 70000);

        $segunda = $this->invoice($tenant, $customer, 8000);
        $this->invokeApplyCredit($segunda, $profile->refresh());
        $segunda->refresh();

        $this->assertEquals(0, (float) $segunda->balance_due);
        $this->assertEquals(2000, $this->balance($customer));

        // Si la factura desaparece, los 8.000 que la pagaron tienen que volver
        // al cliente. Antes se perdían sin que nadie se enterara.
        $this->billing->deleteInvoice($segunda);

        $this->assertEquals(10000, $this->balance($customer));
        $this->assertLedgerMatchesCache($customer);
    }

    /**
     * applyCreditToInvoice es protegido porque en producción solo lo dispara la
     * generación de facturas. El test lo llama directo para poder montar los
     * escenarios sin arrastrar toda la configuración de routers y planes.
     */
    private function invokeApplyCredit(Invoice $invoice, CustomerProfile $profile): void
    {
        $method = new \ReflectionMethod($this->billing, 'applyCreditToInvoice');
        $method->setAccessible(true);
        $method->invoke($this->billing, $invoice, $profile);
    }
}
