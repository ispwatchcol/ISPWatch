<?php

namespace Tests\Feature\Billing;

use App\Models\Billing;
use App\Models\BillingActionLog;
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
 * "Primera factura": qué se le cobra a un cliente cuyo servicio arranca DESPUÉS
 * de que el mes ya se facturó (instalación a mitad de mes).
 *
 * Caso real que originó esto: un prospecto convertido a cliente el día 27 con
 * instalación el 25 recibió, en la corrida horaria siguiente, una factura del
 * MES COMPLETO (plan de 52.500) que el operador tuvo que editar a mano a 10.000
 * con la nota "PRORRATEO INSTALACION".
 *
 * Reglas cubiertas aquí:
 *   - por defecto (none) no se factura nada: su primer cobro es el próximo ciclo
 *   - prorated cobra sólo los días restantes del mes
 *   - full mantiene el mes completo
 *   - el modo del cliente manda sobre la política del router
 *   - el cliente antiguo NO se ve afectado (sigue recibiendo su mes completo)
 *   - una factura borrada por el administrador jamás se regenera
 */
class FirstInvoiceProrationTest extends TestCase
{
    use RefreshDatabase;

    protected BillingService $billing;

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

    // ── Helpers ─────────────────────────────────────────────────────────

    private function makeRouter(Tenant $tenant, int $createDay, string $policy = Billing::FIRST_INVOICE_NONE): Router
    {
        $this->seq++;

        $config = Billing::create([
            'create_invoice'       => Carbon::create(2026, 1, $createDay)->toDateString(),
            'billing_mode'         => Billing::MODE_ANTICIPADO,
            'first_invoice_policy' => $policy,
            'status'               => 'pending',
        ]);

        return Router::create([
            'name'              => "Router {$this->seq}",
            'tenant_id'         => $tenant->id,
            'billing_router_id' => $config->id,
            'status'            => 'active',
        ]);
    }

    /** Cliente cuyo servicio arrancó en $serviceStart. */
    private function makeCustomer(
        Tenant $tenant,
        Router $router,
        Plan $plan,
        Carbon $serviceStart,
        ?string $firstInvoiceMode = null,
    ): User {
        $this->seq++;

        $user = User::factory()->create([
            'tenant_id'  => $tenant->id,
            'created_at' => $serviceStart,
        ]);

        CustomerProfile::create([
            'user_id'            => $user->id,
            'name'               => "Cliente{$this->seq}",
            'last_name'          => "Apellido{$this->seq}",
            'router_id'          => $router->id,
            'status'             => true,
            'installation_date'  => $serviceStart->toDateString(),
            'first_invoice_mode' => $firstInvoiceMode,
        ]);

        UserService::create([
            'user_id'         => $user->id,
            'service_plan_id' => $plan->id,
            'status'          => UserService::STATUS_ACTIVE,
            'start_date'      => $serviceStart,
        ]);

        return $user;
    }

    private function makePlan(Tenant $tenant, float $cost = 52500): Plan
    {
        return Plan::factory()->create([
            'tenant_id'    => $tenant->id,
            'cost_product' => $cost,
            'is_courtesy'  => false,
        ]);
    }

    // ── Por defecto: no se factura a mitad de mes ───────────────────────

    #[Test]
    public function a_customer_installed_after_the_create_day_is_not_invoiced_by_default(): void
    {
        // Junio: se facturó el día 1; el cliente entra el 25 y hoy es 29.
        Carbon::setTestNow(Carbon::create(2026, 6, 29, 21, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $router = $this->makeRouter($tenant, createDay: 1);
        $user   = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2026, 6, 25));

        $count = $this->billing->generateMonthlyInvoices();

        $this->assertSame(0, $count, 'Un alta a mitad de mes no debe generar factura por sí sola');
        $this->assertSame(0, Invoice::where('customer_id', $user->id)->count());
    }

    #[Test]
    public function the_same_customer_is_invoiced_in_full_on_the_next_cycle(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);

        Carbon::setTestNow(Carbon::create(2026, 6, 29, 21, 0, 0));
        $router = $this->makeRouter($tenant, createDay: 1);
        $user   = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2026, 6, 25));

        $this->assertSame(0, $this->billing->generateMonthlyInvoices());

        // Julio: ya es un cliente del mes anterior → mes completo.
        Carbon::setTestNow(Carbon::create(2026, 7, 1, 9, 0, 0));

        $this->assertSame(1, $this->billing->generateMonthlyInvoices());

        $invoice = Invoice::where('customer_id', $user->id)->firstOrFail();
        $this->assertEquals(52500, (float) $invoice->total);
        $this->assertSame('2026-07-01', Carbon::parse($invoice->period_start)->toDateString());
    }

    // ── Prorrateo ───────────────────────────────────────────────────────

    #[Test]
    public function the_router_policy_can_charge_only_the_remaining_days(): void
    {
        // Junio tiene 30 días; instalado el 20 ⇒ 10 días (30 − 20).
        Carbon::setTestNow(Carbon::create(2026, 6, 28, 10, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant, 60000);
        $router = $this->makeRouter($tenant, createDay: 1, policy: Billing::FIRST_INVOICE_PRORATED);
        $user   = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2026, 6, 20));

        $this->assertSame(1, $this->billing->generateMonthlyInvoices());

        $invoice = Invoice::where('customer_id', $user->id)->firstOrFail();

        // 60000 * 10/30 = 20000
        $this->assertEquals(20000, (float) $invoice->total);
        $this->assertEquals(20000, (float) $invoice->balance_due);
        $this->assertSame('2026-06-20', Carbon::parse($invoice->period_start)->toDateString());
        $this->assertSame('2026-06-30', Carbon::parse($invoice->period_end)->toDateString());
        $this->assertStringContainsString('10 de 30 días', $invoice->items()->first()->description);
    }

    #[Test]
    public function the_customer_mode_overrides_the_router_policy(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 28, 10, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant, 60000);
        // El router dice "no cobrar", pero este cliente sí paga proporcional.
        $router = $this->makeRouter($tenant, createDay: 1, policy: Billing::FIRST_INVOICE_NONE);
        $user   = $this->makeCustomer(
            $tenant, $router, $plan, Carbon::create(2026, 6, 20), Billing::FIRST_INVOICE_PRORATED
        );

        $this->assertSame(1, $this->billing->generateMonthlyInvoices());
        $this->assertEquals(20000, (float) Invoice::where('customer_id', $user->id)->first()->total);
    }

    #[Test]
    public function a_customer_marked_none_is_skipped_even_when_the_router_prorates(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 28, 10, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant, 60000);
        $router = $this->makeRouter($tenant, createDay: 1, policy: Billing::FIRST_INVOICE_PRORATED);
        $user   = $this->makeCustomer(
            $tenant, $router, $plan, Carbon::create(2026, 6, 20), Billing::FIRST_INVOICE_NONE
        );

        $this->assertSame(0, $this->billing->generateMonthlyInvoices());
        $this->assertSame(0, Invoice::where('customer_id', $user->id)->count());
    }

    #[Test]
    public function the_full_mode_keeps_charging_the_whole_month(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 28, 10, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant, 60000);
        $router = $this->makeRouter($tenant, createDay: 1, policy: Billing::FIRST_INVOICE_FULL);
        $user   = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2026, 6, 20));

        $this->assertSame(1, $this->billing->generateMonthlyInvoices());

        $invoice = Invoice::where('customer_id', $user->id)->firstOrFail();
        $this->assertEquals(60000, (float) $invoice->total);
        $this->assertSame('2026-06-01', Carbon::parse($invoice->period_start)->toDateString());
    }

    #[Test]
    public function prorating_the_last_day_of_the_month_charges_nothing(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 30, 23, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant, 60000);
        $router = $this->makeRouter($tenant, createDay: 1, policy: Billing::FIRST_INVOICE_PRORATED);
        $user   = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2026, 6, 30));

        $this->assertSame(0, $this->billing->generateMonthlyInvoices());
        $this->assertSame(0, Invoice::where('customer_id', $user->id)->count());
    }

    #[Test]
    public function an_established_customer_still_gets_the_full_month(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 29, 21, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant, 60000);
        $router = $this->makeRouter($tenant, createDay: 1);
        $user   = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2025, 11, 3));

        $this->assertSame(1, $this->billing->generateMonthlyInvoices());

        $invoice = Invoice::where('customer_id', $user->id)->firstOrFail();
        $this->assertEquals(60000, (float) $invoice->total);
        $this->assertSame('2026-06-01', Carbon::parse($invoice->period_start)->toDateString());
    }

    #[Test]
    public function a_prorated_invoice_is_not_duplicated_on_the_next_hourly_run(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 28, 10, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant, 60000);
        $router = $this->makeRouter($tenant, createDay: 1, policy: Billing::FIRST_INVOICE_PRORATED);
        $user   = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2026, 6, 20));

        $this->assertSame(1, $this->billing->generateMonthlyInvoices());

        Carbon::setTestNow(Carbon::create(2026, 6, 28, 11, 0, 0));

        $this->assertSame(0, $this->billing->generateMonthlyInvoices());
        $this->assertSame(1, Invoice::where('customer_id', $user->id)->count());
    }

    // ── El audit no debe gritar por facturas que no deben existir ───────

    #[Test]
    public function the_no_show_audit_ignores_customers_the_policy_leaves_out(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 29, 21, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $router = $this->makeRouter($tenant, createDay: 1);
        $this->makeCustomer($tenant, $router, $plan, Carbon::create(2026, 6, 25));

        $row = collect($this->billing->auditMonthlyBilling())->firstWhere('router_id', $router->id);

        $this->assertSame(0, $row['expected']);
        $this->assertSame('ok', $row['status']);
    }

    // ── Factura borrada por el administrador ────────────────────────────

    #[Test]
    public function a_deleted_monthly_invoice_is_never_regenerated(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 9, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant, 60000);
        $router = $this->makeRouter($tenant, createDay: 1);
        $user   = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2025, 11, 3));

        $this->assertSame(1, $this->billing->generateMonthlyInvoices());

        $invoice = Invoice::where('customer_id', $user->id)->firstOrFail();
        $this->billing->deleteInvoice($invoice);

        // Varias corridas horarias más tarde sigue sin existir.
        Carbon::setTestNow(Carbon::create(2026, 6, 2, 9, 0, 0));
        $this->assertSame(0, $this->billing->generateMonthlyInvoices());

        Carbon::setTestNow(Carbon::create(2026, 6, 20, 9, 0, 0));
        $this->assertSame(0, $this->billing->generateMonthlyInvoices());

        $this->assertSame(0, Invoice::where('customer_id', $user->id)->count());

        // La lápida queda registrada. Se consulta con whereDate porque el
        // formato en que se guarda la fecha depende del driver (PostgreSQL
        // normaliza a date, SQLite conserva "Y-m-d H:i:s").
        $this->assertTrue(
            BillingActionLog::where('tenant_id', $tenant->id)
                ->where('customer_id', $user->id)
                ->whereDate('period_start', '2026-06-01')
                ->where('status', BillingActionLog::STATUS_SUPPRESSED)
                ->exists(),
            'Debe quedar constancia de que el administrador borró la factura'
        );
    }

    #[Test]
    public function deleting_one_month_does_not_block_the_following_month(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 9, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant, 60000);
        $router = $this->makeRouter($tenant, createDay: 1);
        $user   = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2025, 11, 3));

        $this->billing->generateMonthlyInvoices();
        $this->billing->deleteInvoice(Invoice::where('customer_id', $user->id)->firstOrFail());

        Carbon::setTestNow(Carbon::create(2026, 7, 1, 9, 0, 0));

        $this->assertSame(1, $this->billing->generateMonthlyInvoices());

        $invoice = Invoice::where('customer_id', $user->id)->firstOrFail();
        $this->assertSame('2026-07-01', Carbon::parse($invoice->period_start)->toDateString());
    }

    #[Test]
    public function the_audit_does_not_flag_a_period_whose_invoice_was_deleted(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 1, 9, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant, 60000);
        $router = $this->makeRouter($tenant, createDay: 1);
        $user   = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2025, 11, 3));

        $this->billing->generateMonthlyInvoices();
        $this->billing->deleteInvoice(Invoice::where('customer_id', $user->id)->firstOrFail());

        Carbon::setTestNow(Carbon::create(2026, 6, 5, 9, 0, 0));

        $row = collect($this->billing->auditMonthlyBilling())->firstWhere('router_id', $router->id);

        $this->assertSame(0, $row['expected']);
        $this->assertSame('ok', $row['status'], 'Borrar a propósito no es un no-show');
    }
}
