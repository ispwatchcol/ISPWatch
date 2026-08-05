<?php

namespace Tests\Feature\Billing;

use App\Models\Billing;
use App\Models\CustomerProfile;
use App\Models\Invoice;
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
 * Tope de facturación por router (billing.stop_invoicing_extra).
 *
 * Regla del operador: "si suspendo tras 2 facturas vencidas, quiero que se le
 * sigan generando 2 más y ahí pare". Es decir, al llegar a
 * (overdue_invoices + stop_invoicing_extra) facturas PENDIENTES el cliente deja
 * de recibir mensualidades y su deuda queda congelada.
 */
class InvoiceStopThresholdTest extends TestCase
{
    use RefreshDatabase;

    protected BillingService $billing;

    /** Monotonic counter so customer_profile (name,last_name) stays unique. */
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->billing = app(BillingService::class);
        // Día 15: el gate de creación ya pasó en todos los tests.
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────

    /**
     * @param int|null $stopExtra Margen del tope; null = sin tope.
     */
    private function makeConfig(int $cutThreshold = 2, ?int $stopExtra = 2): Billing
    {
        return Billing::create([
            'create_invoice'       => Carbon::create(2026, 1, 15)->toDateString(),
            'payment_day'          => Carbon::create(2026, 1, 20)->toDateString(),
            'overdue_invoices'     => $cutThreshold,
            'stop_invoicing_extra' => $stopExtra,
            'billing_mode'         => Billing::MODE_ANTICIPADO,
            'status'               => 'pending',
        ]);
    }

    private function makeRouter(Tenant $tenant, Billing $config): Router
    {
        $this->seq++;

        return Router::create([
            'name'              => "Router {$this->seq}",
            'tenant_id'         => $tenant->id,
            'billing_router_id' => $config->id,
            'status'            => 'active',
        ]);
    }

    /** Cliente antiguo (servicio iniciado hace 6 meses) y facturable. */
    private function makeCustomer(Tenant $tenant, Router $router, Plan $plan, bool $active = true): User
    {
        $this->seq++;
        $start = Carbon::now()->subMonths(6)->startOfDay();

        $user = User::factory()->create([
            'tenant_id'  => $tenant->id,
            'created_at' => $start,
        ]);

        CustomerProfile::create([
            'user_id'        => $user->id,
            'name'           => "Cliente{$this->seq}",
            'last_name'      => "Apellido{$this->seq}",
            'router_id'      => $router->id,
            'status'         => $active,
            'service_status' => $active ? 'activo' : 'suspendido',
            'installation_date' => $start->toDateString(),
        ]);

        UserService::create([
            'user_id'         => $user->id,
            'service_plan_id' => $plan->id,
            'status'          => UserService::STATUS_ACTIVE,
            'start_date'      => $start,
        ]);

        return $user;
    }

    /**
     * $qty facturas mensuales impagas de meses ANTERIORES al que se factura
     * (para no chocar con la idempotencia del periodo en curso).
     */
    private function makeUnpaidInvoices(User $user, int $qty, string $status = 'overdue'): void
    {
        for ($i = 1; $i <= $qty; $i++) {
            $month = Carbon::create(2026, 6, 1)->subMonths($i);

            Invoice::create([
                'tenant_id'    => $user->tenant_id,
                'customer_id'  => $user->id,
                'invoice_type' => Invoice::TYPE_MONTHLY,
                'number'       => uniqid('INV-'),
                'issue_date'   => $month->copy()->startOfMonth(),
                'due_date'     => $month->copy()->setDay(20),
                'period_start' => $month->copy()->startOfMonth(),
                'period_end'   => $month->copy()->endOfMonth()->startOfDay(),
                'subtotal'     => 50000,
                'total'        => 50000,
                'balance_due'  => $status === 'paid' ? 0 : 50000,
                'status'       => $status,
            ]);
        }
    }

    private function makePlan(Tenant $tenant): Plan
    {
        return Plan::factory()->create([
            'tenant_id'    => $tenant->id,
            'cost_product' => 50000,
            'is_courtesy'  => false,
        ]);
    }

    // ────────────────────────────────────────────────────────────────
    // Tests
    // ────────────────────────────────────────────────────────────────

    #[Test]
    public function it_stops_invoicing_when_the_customer_reaches_the_cap(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $config = $this->makeConfig(cutThreshold: 2, stopExtra: 2); // tope = 4
        $router = $this->makeRouter($tenant, $config);

        // Cortado y debiendo exactamente el tope.
        $user = $this->makeCustomer($tenant, $router, $plan, active: false);
        $this->makeUnpaidInvoices($user, 4);

        $created = $this->billing->generateMonthlyInvoices();

        $this->assertSame(0, $created);
        $this->assertSame(4, Invoice::where('customer_id', $user->id)->count());
    }

    #[Test]
    public function it_still_invoices_one_below_the_cap(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $config = $this->makeConfig(cutThreshold: 2, stopExtra: 2); // tope = 4
        $router = $this->makeRouter($tenant, $config);

        $user = $this->makeCustomer($tenant, $router, $plan, active: false);
        $this->makeUnpaidInvoices($user, 3);

        $created = $this->billing->generateMonthlyInvoices();

        // La cuarta sí se emite; a partir de la quinta ya no.
        $this->assertSame(1, $created);
        $this->assertSame(4, Invoice::where('customer_id', $user->id)->count());
    }

    #[Test]
    public function the_cap_is_the_cut_threshold_plus_the_configured_margin(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        // Corta con 1 vencida y sin margen → deja de facturar con 1 pendiente.
        $config = $this->makeConfig(cutThreshold: 1, stopExtra: 0);
        $router = $this->makeRouter($tenant, $config);

        $user = $this->makeCustomer($tenant, $router, $plan);
        $this->makeUnpaidInvoices($user, 1);

        $this->assertSame(1, $config->invoiceStopThreshold());
        $this->assertSame(0, $this->billing->generateMonthlyInvoices());
    }

    #[Test]
    public function a_null_margin_disables_the_cap(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $config = $this->makeConfig(cutThreshold: 2, stopExtra: null);
        $router = $this->makeRouter($tenant, $config);

        $user = $this->makeCustomer($tenant, $router, $plan, active: false);
        $this->makeUnpaidInvoices($user, 6);

        $this->assertNull($config->invoiceStopThreshold());
        $this->assertSame(1, $this->billing->generateMonthlyInvoices());
        $this->assertSame(7, Invoice::where('customer_id', $user->id)->count());
    }

    #[Test]
    public function paid_and_void_invoices_do_not_count_towards_the_cap(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $config = $this->makeConfig(cutThreshold: 2, stopExtra: 2); // tope = 4
        $router = $this->makeRouter($tenant, $config);

        $user = $this->makeCustomer($tenant, $router, $plan);
        $this->makeUnpaidInvoices($user, 4, status: 'paid');   // saldadas
        $this->makeUnpaidInvoices($user, 1);                   // única con saldo

        $created = $this->billing->generateMonthlyInvoices();

        $this->assertSame(1, $created);
    }

    #[Test]
    public function a_customer_who_pays_starts_getting_invoices_again(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $config = $this->makeConfig(cutThreshold: 2, stopExtra: 2); // tope = 4
        $router = $this->makeRouter($tenant, $config);

        $user = $this->makeCustomer($tenant, $router, $plan, active: false);
        $this->makeUnpaidInvoices($user, 4);

        $this->assertSame(0, $this->billing->generateMonthlyInvoices());

        // Abona dos: baja del tope y el ciclo vuelve a emitirle.
        Invoice::where('customer_id', $user->id)
            ->limit(2)
            ->get()
            ->each(fn (Invoice $inv) => $inv->update(['balance_due' => 0, 'status' => 'paid']));

        $this->assertSame(1, $this->billing->generateMonthlyInvoices());
    }

    #[Test]
    public function the_audit_does_not_flag_capped_customers_as_missing(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $config = $this->makeConfig(cutThreshold: 2, stopExtra: 2); // tope = 4
        $router = $this->makeRouter($tenant, $config);

        $user = $this->makeCustomer($tenant, $router, $plan, active: false);
        $this->makeUnpaidInvoices($user, 4);

        $this->billing->generateMonthlyInvoices();

        // 12:00 > hora de creación + 1h de gracia → el audit ya evalúa.
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 12, 0, 0));
        $rows = $this->billing->auditMonthlyBilling();

        $this->assertCount(1, $rows);
        $this->assertSame(0, $rows[0]['expected']);
        $this->assertSame(1, $rows[0]['capped']);
        $this->assertSame('ok', $rows[0]['status']);
    }

    #[Test]
    public function the_audit_counts_the_customer_that_reached_the_cap_with_this_very_invoice(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $config = $this->makeConfig(cutThreshold: 2, stopExtra: 2); // tope = 4
        $router = $this->makeRouter($tenant, $config);

        // Con 3 pendientes se le emite la cuarta: el audit debe verla como
        // esperada+generada (no como un moroso "en tope" que faltó facturar).
        $user = $this->makeCustomer($tenant, $router, $plan, active: false);
        $this->makeUnpaidInvoices($user, 3);

        $this->assertSame(1, $this->billing->generateMonthlyInvoices());

        Carbon::setTestNow(Carbon::create(2026, 6, 15, 12, 0, 0));
        $rows = $this->billing->auditMonthlyBilling();

        $this->assertSame(1, $rows[0]['expected']);
        $this->assertSame(1, $rows[0]['actual']);
        $this->assertSame(0, $rows[0]['capped']);
        $this->assertSame('ok', $rows[0]['status']);
    }
}
