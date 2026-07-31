<?php

namespace Tests\Feature\Billing;

use App\Models\Billing;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\InvoiceCarryover;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserService;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Arrastre de saldo por pago parcial (decisión de operación):
 *
 *   "El cliente abona menos del total → la factura queda PAGADA y el faltante
 *    se suma a la próxima factura."
 *
 * Consecuencia buscada: quien abona sale de mora y no se le corta hasta que
 * venza la factura nueva, que ya trae la deuda vieja encima.
 */
class PartialPaymentCarryoverTest extends TestCase
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

    private function customer(Tenant $tenant, ?Router $router = null, ?Plan $plan = null, ?Carbon $serviceStart = null): User
    {
        $this->seq++;
        $serviceStart ??= Carbon::now()->subMonths(6)->startOfDay();

        $user = User::factory()->create([
            'tenant_id'  => $tenant->id,
            'created_at' => $serviceStart,
        ]);

        CustomerProfile::create([
            'user_id'           => $user->id,
            'name'              => "Cliente{$this->seq}",
            'last_name'         => "Apellido{$this->seq}",
            'router_id'         => $router?->id,
            'status'            => true,
            'installation_date' => $serviceStart->toDateString(),
        ]);

        if ($plan) {
            UserService::create([
                'user_id'         => $user->id,
                'service_plan_id' => $plan->id,
                'status'          => UserService::STATUS_ACTIVE,
                'start_date'      => $serviceStart,
            ]);
        }

        return $user;
    }

    private function invoice(Tenant $tenant, User $customer, float $total, array $attributes = []): Invoice
    {
        return Invoice::create(array_merge([
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
        ], $attributes));
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

    private function routerWithBilling(Tenant $tenant, int $createDay = 15): Router
    {
        $config = Billing::create([
            'create_invoice' => Carbon::create(2026, 1, $createDay)->toDateString(),
            'billing_mode'   => Billing::MODE_ANTICIPADO,
            'status'         => 'pending',
        ]);

        $this->seq++;

        return Router::create([
            'name'              => "Router {$this->seq}",
            'tenant_id'         => $tenant->id,
            'billing_router_id' => $config->id,
            'status'            => 'active',
        ]);
    }

    // ── El arrastre ──────────────────────────────────────────────────────

    #[Test]
    public function un_abono_parcial_cierra_la_factura_y_deja_el_faltante_pendiente(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);
        $invoice  = $this->invoice($tenant, $customer, 50000);

        $this->pay($tenant, $customer, 30000);

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertEquals(0, (float) $invoice->balance_due);
        $this->assertEquals(20000, (float) $invoice->carried_out);

        $row = InvoiceCarryover::where('from_invoice_id', $invoice->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(InvoiceCarryover::STATUS_PENDING, $row->status);
        $this->assertEquals(20000, (float) $row->amount);
        $this->assertEquals(20000, InvoiceCarryover::pendingTotalFor($customer->id));
    }

    #[Test]
    public function un_pago_exacto_no_genera_arrastre(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);
        $invoice  = $this->invoice($tenant, $customer, 50000);

        $this->pay($tenant, $customer, 50000);

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertEquals(0, (float) $invoice->carried_out);
        $this->assertSame(0, InvoiceCarryover::count());
    }

    #[Test]
    public function una_factura_vencida_pagada_a_medias_deja_de_estar_en_mora(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);
        $this->invoice($tenant, $customer, 50000, [
            'due_date' => now()->subDays(10),
            'status'   => 'overdue',
        ]);

        $this->pay($tenant, $customer, 10000);

        // Es el filtro exacto que usan el corte automático y la reconexión.
        $overdue = Invoice::where('customer_id', $customer->id)
            ->where('due_date', '<', now())
            ->where('balance_due', '>', 0)
            ->whereNotIn('status', ['void', 'cancelled', 'paid'])
            ->count();

        $this->assertSame(0, $overdue);
    }

    #[Test]
    public function la_siguiente_factura_mensual_cobra_el_saldo_arrastrado(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $tenant   = Tenant::factory()->create();
        $plan     = Plan::factory()->create(['tenant_id' => $tenant->id, 'cost_product' => 50000]);
        $router   = $this->routerWithBilling($tenant, createDay: 15);
        $customer = $this->customer($tenant, $router, $plan);

        // Junio: se factura y el cliente abona sólo una parte.
        $this->billing->generateMonthlyInvoices();
        $june = Invoice::where('customer_id', $customer->id)->firstOrFail();
        $this->pay($tenant, $customer, 30000);

        $this->assertEquals(20000, InvoiceCarryover::pendingTotalFor($customer->id));

        // Julio: la factura del mes trae el plan + lo que quedó debiendo.
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 9, 0, 0));
        $this->billing->generateMonthlyInvoices();

        $july = Invoice::where('customer_id', $customer->id)->where('id', '!=', $june->id)->firstOrFail();

        $this->assertEquals(70000, (float) $july->total);
        $this->assertEquals(70000, (float) $july->balance_due);
        $this->assertEquals(20000, (float) $july->carried_in);

        $item = $july->items()->where('type', 'carryover')->first();
        $this->assertNotNull($item);
        $this->assertEquals(20000, (float) $item->amount);
        $this->assertStringContainsString($june->number, $item->description);

        // El movimiento queda saldado y ya no cuenta como pendiente.
        $this->assertEquals(0, InvoiceCarryover::pendingTotalFor($customer->id));
        $this->assertSame(
            InvoiceCarryover::STATUS_APPLIED,
            InvoiceCarryover::where('from_invoice_id', $june->id)->first()->status
        );
    }

    #[Test]
    public function el_arrastre_no_se_cobra_dos_veces_en_meses_siguientes(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $tenant   = Tenant::factory()->create();
        $plan     = Plan::factory()->create(['tenant_id' => $tenant->id, 'cost_product' => 50000]);
        $router   = $this->routerWithBilling($tenant, createDay: 15);
        $customer = $this->customer($tenant, $router, $plan);

        $this->billing->generateMonthlyInvoices();
        $this->pay($tenant, $customer, 30000);

        Carbon::setTestNow(Carbon::create(2026, 7, 15, 9, 0, 0));
        $this->billing->generateMonthlyInvoices();

        // Agosto: nadie pagó julio, pero el arrastre ya se cobró allá.
        Carbon::setTestNow(Carbon::create(2026, 8, 15, 9, 0, 0));
        $this->billing->generateMonthlyInvoices();

        $august = Invoice::where('customer_id', $customer->id)->latest('id')->firstOrFail();

        $this->assertEquals(50000, (float) $august->total);
        $this->assertEquals(0, (float) $august->carried_in);
    }

    // ── Reversos ─────────────────────────────────────────────────────────

    #[Test]
    public function borrar_el_pago_devuelve_el_arrastre_a_la_factura_original(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);
        $invoice  = $this->invoice($tenant, $customer, 50000);

        $payment = $this->pay($tenant, $customer, 30000);
        $this->billing->deletePayment($payment);

        $invoice->refresh();
        $this->assertEquals(50000, (float) $invoice->balance_due);
        $this->assertEquals(0, (float) $invoice->carried_out);
        $this->assertSame(0, InvoiceCarryover::count());
    }

    #[Test]
    public function marcar_como_no_pagada_devuelve_el_arrastre_pendiente(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);
        $invoice  = $this->invoice($tenant, $customer, 50000);

        $this->pay($tenant, $customer, 30000);
        $this->billing->markInvoiceUnpaid($invoice);

        $invoice->refresh();
        $this->assertEquals(50000, (float) $invoice->balance_due);
        $this->assertEquals(0, (float) $invoice->carried_out);
        $this->assertSame(0, InvoiceCarryover::count());
    }

    #[Test]
    public function un_arrastre_ya_cobrado_en_otra_factura_no_vuelve_a_la_original(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $tenant   = Tenant::factory()->create();
        $plan     = Plan::factory()->create(['tenant_id' => $tenant->id, 'cost_product' => 50000]);
        $router   = $this->routerWithBilling($tenant, createDay: 15);
        $customer = $this->customer($tenant, $router, $plan);

        $this->billing->generateMonthlyInvoices();
        $june = Invoice::where('customer_id', $customer->id)->firstOrFail();
        $payment = $this->pay($tenant, $customer, 30000);

        Carbon::setTestNow(Carbon::create(2026, 7, 15, 9, 0, 0));
        $this->billing->generateMonthlyInvoices();

        // Se anula el abono de junio DESPUÉS de que julio ya cobró el arrastre.
        $this->billing->deletePayment($payment);

        $june->refresh();
        // Junio vuelve a deber sólo lo que el abono había cubierto: los 20.000
        // restantes los está cobrando julio y reclamarlos aquí sería duplicarlos.
        $this->assertEquals(30000, (float) $june->balance_due);
        $this->assertEquals(20000, (float) $june->carried_out);

        $july = Invoice::where('customer_id', $customer->id)->where('id', '!=', $june->id)->firstOrFail();
        $this->assertEquals(70000, (float) $july->total);
    }

    #[Test]
    public function borrar_la_factura_que_cobraba_el_arrastre_lo_deja_pendiente_otra_vez(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $tenant   = Tenant::factory()->create();
        $plan     = Plan::factory()->create(['tenant_id' => $tenant->id, 'cost_product' => 50000]);
        $router   = $this->routerWithBilling($tenant, createDay: 15);
        $customer = $this->customer($tenant, $router, $plan);

        $this->billing->generateMonthlyInvoices();
        $june = Invoice::where('customer_id', $customer->id)->firstOrFail();
        $this->pay($tenant, $customer, 30000);

        Carbon::setTestNow(Carbon::create(2026, 7, 15, 9, 0, 0));
        $this->billing->generateMonthlyInvoices();
        $july = Invoice::where('customer_id', $customer->id)->where('id', '!=', $june->id)->firstOrFail();

        $this->billing->deleteInvoice($july);

        // La deuda no se perdona al borrar la factura que la cobraba.
        $this->assertEquals(20000, InvoiceCarryover::pendingTotalFor($customer->id));
    }

    #[Test]
    public function borrar_la_factura_que_origino_el_arrastre_lo_elimina(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);
        $invoice  = $this->invoice($tenant, $customer, 50000);

        $this->pay($tenant, $customer, 30000);
        $this->billing->deleteInvoice($invoice);

        $this->assertEquals(0, InvoiceCarryover::pendingTotalFor($customer->id));
        // El abono no se pierde: vuelve como saldo a favor del cliente.
        $this->assertEquals(30000, (float) CustomerProfile::where('user_id', $customer->id)->first()->credit_balance);
    }

    #[Test]
    public function el_balance_del_cliente_reporta_el_saldo_arrastrado_aparte(): void
    {
        $tenant   = Tenant::factory()->create();
        $customer = $this->customer($tenant);
        $this->invoice($tenant, $customer, 50000);

        $this->pay($tenant, $customer, 30000);

        $staff = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id'   => \App\Models\Role::create(['name' => 'Admin', 'permissions' => ['*']])->id,
        ]);

        $response = $this->actingAs($staff)->getJson("/api/billing/customers/{$customer->id}/balance");

        $response->assertOk()
            ->assertJson([
                'balance'           => 0,     // ninguna factura abierta
                'carryover_balance' => 20000, // pero sí deuda arrastrada
            ]);
    }
}
