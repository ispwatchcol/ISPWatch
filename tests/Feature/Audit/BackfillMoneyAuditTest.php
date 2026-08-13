<?php

namespace Tests\Feature\Audit;

use App\Models\CustomerCredit;
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
 * Reconstrucción del libro de saldo desde los datos que ya existen.
 *
 * Los saldos vivos hoy nacieron antes de que existiera el libro, así que sin
 * backfill el extracto arrancaría vacío justo para los clientes que tienen
 * dinero a favor — que son precisamente los que hay que poder explicar en el
 * mostrador.
 *
 * La regla que se verifica aquí: el backfill reconstruye, pero NUNCA cambia el
 * saldo de nadie. Si no logra explicar el saldo real, lo deja intacto y escribe
 * el descuadre.
 */
class BackfillMoneyAuditTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /** Cliente con saldo ya existente, como los que hay hoy en producción. */
    private function legacyCustomer(Tenant $tenant, float $creditBalance): User
    {
        $this->seq++;

        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        CustomerProfile::create([
            'user_id'        => $user->id,
            'name'           => "Legado{$this->seq}",
            'last_name'      => "Apellido{$this->seq}",
            'status'         => true,
            'credit_balance' => $creditBalance,
        ]);

        return $user;
    }

    private function invoice(Tenant $tenant, User $customer, float $total, float $balanceDue): Invoice
    {
        return Invoice::create([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $customer->id,
            'number'       => uniqid('INV-'),
            'invoice_type' => Invoice::TYPE_MONTHLY,
            'issue_date'   => now()->subDays(20),
            'due_date'     => now()->subDays(10),
            'period_start' => now()->startOfMonth(),
            'period_end'   => now()->endOfMonth(),
            'subtotal'     => $total,
            'total'        => $total,
            'balance_due'  => $balanceDue,
            'status'       => $balanceDue <= 0 ? 'paid' : 'issued',
        ]);
    }

    private function rawPayment(Tenant $tenant, User $customer, float $amount): Payment
    {
        return Payment::create([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $customer->id,
            'amount'       => $amount,
            'payment_date' => now()->subDays(15),
            'method'       => 'cash',
            'status'       => 'completed',
        ]);
    }

    #[Test]
    public function reconstruye_el_excedente_de_un_pago_historico(): void
    {
        $tenant   = Tenant::factory()->create();
        // Paga 70.000 contra una factura de 60.000: quedan 10.000 de saldo.
        $customer = $this->legacyCustomer($tenant, 10000);

        $invoice = $this->invoice($tenant, $customer, 60000, 0);
        $payment = $this->rawPayment($tenant, $customer, 70000);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount'     => 60000,
        ]);

        $this->artisan('audit:backfill-money')->assertSuccessful();

        $movements = CustomerCredit::where('customer_id', $customer->id)->get();

        $this->assertCount(1, $movements);
        $this->assertSame(CustomerCredit::TYPE_EARNED, $movements->first()->type);
        $this->assertEquals(10000, (float) $movements->first()->amount);

        // El saldo real no se tocó y el libro cuadra con él.
        $this->assertEquals(10000, (float) CustomerProfile::where('user_id', $customer->id)->value('credit_balance'));
        $this->assertEquals(10000, CustomerCredit::ledgerBalanceFor($customer->id));
    }

    #[Test]
    public function reconstruye_el_credito_que_ya_habia_pagado_una_factura(): void
    {
        $tenant = Tenant::factory()->create();
        // Excedente de 14.000, del que 4.000 ya se consumieron: quedan 10.000.
        $customer = $this->legacyCustomer($tenant, 10000);

        $primera = $this->invoice($tenant, $customer, 56000, 0);
        $payment = $this->rawPayment($tenant, $customer, 70000);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'invoice_id' => $primera->id,
            'amount'     => 56000,
        ]);

        // Factura posterior saldada en parte por el saldo: total 60.000,
        // 56.000 pagados en efectivo y 4.000 que salieron del saldo a favor.
        $segunda = $this->invoice($tenant, $customer, 60000, 0);
        $segundo = $this->rawPayment($tenant, $customer, 56000);
        PaymentAllocation::create([
            'payment_id' => $segundo->id,
            'invoice_id' => $segunda->id,
            'amount'     => 56000,
        ]);

        $this->artisan('audit:backfill-money')->assertSuccessful();

        $applied = CustomerCredit::where('customer_id', $customer->id)
            ->where('type', CustomerCredit::TYPE_APPLIED)
            ->first();

        $this->assertNotNull($applied, 'No se reconstruyó el crédito que pagó la segunda factura.');
        $this->assertEquals(-4000, (float) $applied->amount);
        $this->assertEquals($segunda->id, $applied->to_invoice_id);
        $this->assertEquals(10000, CustomerCredit::ledgerBalanceFor($customer->id));
    }

    #[Test]
    public function un_saldo_que_no_se_explica_queda_como_descuadre_sin_tocar_la_plata(): void
    {
        $tenant = Tenant::factory()->create();
        // Saldo sin ningún pago que lo respalde: viene de un ajuste manual
        // antiguo que solo quedó en un Log::info de archivo. La factura está
        // pendiente, así que no aporta ningún movimiento a la reconstrucción.
        $customer = $this->legacyCustomer($tenant, 25000);
        $this->invoice($tenant, $customer, 60000, 60000);

        $this->artisan('audit:backfill-money')->assertSuccessful();

        $descuadre = CustomerCredit::where('customer_id', $customer->id)
            ->where('type', CustomerCredit::TYPE_ADJUSTED)
            ->first();

        $this->assertNotNull($descuadre, 'El saldo inexplicable debía quedar registrado como descuadre.');
        $this->assertEquals(25000, (float) $descuadre->amount);
        $this->assertStringContainsString('Descuadre', $descuadre->reason);

        // Lo importante: el saldo del cliente sigue siendo el suyo.
        $this->assertEquals(
            25000,
            (float) CustomerProfile::where('user_id', $customer->id)->value('credit_balance'),
            'El backfill no puede cambiarle el saldo a un cliente.'
        );
    }

    #[Test]
    public function correrlo_dos_veces_no_duplica_movimientos(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->legacyCustomer($tenant, 10000);

        $invoice = $this->invoice($tenant, $customer, 60000, 0);
        $payment = $this->rawPayment($tenant, $customer, 70000);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount'     => 60000,
        ]);

        $this->artisan('audit:backfill-money')->assertSuccessful();
        $primera = CustomerCredit::where('customer_id', $customer->id)->count();

        $this->artisan('audit:backfill-money')->assertSuccessful();
        $segunda = CustomerCredit::where('customer_id', $customer->id)->count();

        $this->assertSame($primera, $segunda, 'El backfill no es idempotente.');
        $this->assertEquals(10000, (float) CustomerProfile::where('user_id', $customer->id)->value('credit_balance'));
    }

    #[Test]
    public function dry_run_no_escribe_nada(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->legacyCustomer($tenant, 10000);

        $invoice = $this->invoice($tenant, $customer, 60000, 0);
        $payment = $this->rawPayment($tenant, $customer, 70000);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount'     => 60000,
        ]);

        $this->artisan('audit:backfill-money --dry-run')->assertSuccessful();

        $this->assertSame(0, CustomerCredit::where('customer_id', $customer->id)->count(),
            'El dry-run escribió en la base.');
        $this->assertEquals(10000, (float) CustomerProfile::where('user_id', $customer->id)->value('credit_balance'),
            'El dry-run dejó el saldo alterado.');
    }
}
