<?php

namespace Tests\Feature\Billing;

use App\Billing\FirstInvoicePolicy;
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
 * Meses de cortesía posteriores a la instalación ("el próximo mes es gratis").
 *
 * Caso que lo origina: un plan cuya instalación incluye el mes siguiente. El
 * cliente se instala el 16 de julio, paga el prorrateo del 16 al 31 de julio
 * como cualquier otro, y AGOSTO le sale en cero porque ya lo cubrió lo que
 * pagó de instalación. Septiembre vuelve a la tarifa plena.
 *
 * La promoción se configura en el PLAN (es una característica del producto),
 * pero los dos ejes —qué se cobra el mes de instalación y cuántos meses
 * siguientes van gratis— se resuelven por separado en cascada cliente → plan
 * → router, así que también sirve para promociones puntuales por cliente o
 * por router.
 */
class FirstInvoiceFreeMonthsTest extends TestCase
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

    private function makeRouter(
        Tenant $tenant,
        int $createDay = 1,
        string $policy = Billing::FIRST_INVOICE_NONE,
        int $freeMonths = 0,
    ): Router {
        $this->seq++;

        $config = Billing::create([
            'create_invoice'            => Carbon::create(2026, 1, $createDay)->toDateString(),
            'billing_mode'              => Billing::MODE_ANTICIPADO,
            'first_invoice_policy'      => $policy,
            'first_invoice_free_months' => $freeMonths,
            'status'                    => 'pending',
        ]);

        return Router::create([
            'name'              => "Router {$this->seq}",
            'tenant_id'         => $tenant->id,
            'billing_router_id' => $config->id,
            'status'            => 'active',
        ]);
    }

    private function makePlan(
        Tenant $tenant,
        float $cost = 60000,
        ?string $mode = null,
        ?int $freeMonths = null,
    ): Plan {
        return Plan::factory()->create([
            'tenant_id'                 => $tenant->id,
            'cost_product'              => $cost,
            'is_courtesy'               => false,
            'first_invoice_mode'        => $mode,
            'first_invoice_free_months' => $freeMonths,
        ]);
    }

    private function makeCustomer(
        Tenant $tenant,
        Router $router,
        Plan $plan,
        Carbon $serviceStart,
        ?string $firstInvoiceMode = null,
        ?int $freeMonths = null,
    ): User {
        $this->seq++;

        $user = User::factory()->create([
            'tenant_id'  => $tenant->id,
            'created_at' => $serviceStart,
        ]);

        CustomerProfile::create([
            'user_id'                   => $user->id,
            'name'                      => "Cliente{$this->seq}",
            'last_name'                 => "Apellido{$this->seq}",
            'router_id'                 => $router->id,
            'status'                    => true,
            'installation_date'         => $serviceStart->toDateString(),
            'first_invoice_mode'        => $firstInvoiceMode,
            'first_invoice_free_months' => $freeMonths,
        ]);

        UserService::create([
            'user_id'         => $user->id,
            'service_plan_id' => $plan->id,
            'status'          => UserService::STATUS_ACTIVE,
            'start_date'      => $serviceStart,
        ]);

        return $user;
    }

    /** Corre la generación mensual como si fuera el día 1 de ese mes. */
    private function runFor(int $year, int $month): int
    {
        Carbon::setTestNow(Carbon::create($year, $month, 1, 9, 0, 0));

        return $this->billing->generateMonthlyInvoices();
    }

    private function invoiceOf(User $user, string $period): ?Invoice
    {
        return Invoice::where('customer_id', $user->id)
            ->whereDate('period_start', '>=', $period . '-01')
            ->whereDate('period_start', '<=', Carbon::parse($period . '-01')->endOfMonth()->toDateString())
            ->first();
    }

    // ── El caso real: prorrateo + mes siguiente gratis ───────────────────

    #[Test]
    public function the_month_after_a_prorated_installation_is_free_and_then_billing_is_normal(): void
    {
        $tenant = Tenant::factory()->create();
        // La promoción vive en el plan: "instalación con el mes siguiente de regalo".
        $plan   = $this->makePlan($tenant, 60000, Billing::FIRST_INVOICE_PRORATED, freeMonths: 1);
        $router = $this->makeRouter($tenant, createDay: 1);

        Carbon::setTestNow(Carbon::create(2026, 7, 20, 9, 0, 0));
        $user = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2026, 7, 16));

        // Julio: se cobran los días restantes. 60000 * (31 − 16) / 31 = 29.032
        $this->assertSame(1, $this->billing->generateMonthlyInvoices());

        $julio = $this->invoiceOf($user, '2026-07');
        $this->assertNotNull($julio);
        $this->assertEquals(29032, (float) $julio->total);
        $this->assertSame('2026-07-16', Carbon::parse($julio->period_start)->toDateString());

        // Agosto: cortesía. La factura SÍ se emite (queda constancia) pero en cero.
        $this->assertSame(1, $this->runFor(2026, 8));

        $agosto = $this->invoiceOf($user, '2026-08');
        $this->assertNotNull($agosto, 'El mes de cortesía debe dejar factura, aunque sea de $0');
        $this->assertEquals(0, (float) $agosto->total);
        $this->assertEquals(0, (float) $agosto->balance_due);
        $this->assertSame('paid', $agosto->status);
        $this->assertStringContainsString('cortesía', $agosto->items()->first()->description);

        // Septiembre: se acabó la promoción, tarifa plena.
        $this->assertSame(1, $this->runFor(2026, 9));

        $septiembre = $this->invoiceOf($user, '2026-09');
        $this->assertNotNull($septiembre);
        $this->assertEquals(60000, (float) $septiembre->total);
        $this->assertSame('2026-09-01', Carbon::parse($septiembre->period_start)->toDateString());
    }

    #[Test]
    public function a_courtesy_month_never_becomes_overdue_or_triggers_a_cut(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant, 60000, Billing::FIRST_INVOICE_PRORATED, freeMonths: 1);
        $router = $this->makeRouter($tenant, createDay: 1);

        Carbon::setTestNow(Carbon::create(2026, 7, 20, 9, 0, 0));
        $user = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2026, 7, 16));

        $this->billing->generateMonthlyInvoices();
        $this->runFor(2026, 8);

        // Mucho después de cualquier fecha de vencimiento razonable.
        Carbon::setTestNow(Carbon::create(2026, 10, 15, 9, 0, 0));

        $overdue = $this->billing->getOverdueInvoices();

        $this->assertFalse(
            $overdue->contains(fn ($i) => (int) $i->customer_id === $user->id
                && Carbon::parse($i->period_start)->format('Y-m') === '2026-08'),
            'Una factura de cortesía en cero no puede entrar en mora'
        );
    }

    // ── Generalización: varios meses, y cada nivel de la cascada ─────────

    #[Test]
    public function the_promotion_can_span_several_months(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant, 50000, Billing::FIRST_INVOICE_PRORATED, freeMonths: 3);
        $router = $this->makeRouter($tenant, createDay: 1);

        Carbon::setTestNow(Carbon::create(2026, 7, 20, 9, 0, 0));
        $user = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2026, 7, 16));

        $this->billing->generateMonthlyInvoices();

        foreach ([8, 9, 10] as $month) {
            $this->runFor(2026, $month);
            $invoice = $this->invoiceOf($user, sprintf('2026-%02d', $month));
            $this->assertNotNull($invoice, "Debe existir la factura de cortesía del mes {$month}");
            $this->assertEquals(0, (float) $invoice->total, "El mes {$month} debía ser de cortesía");
        }

        $this->runFor(2026, 11);
        $this->assertEquals(50000, (float) $this->invoiceOf($user, '2026-11')->total);
    }

    #[Test]
    public function the_customer_overrides_the_plan_promotion(): void
    {
        $tenant = Tenant::factory()->create();
        // El plan regala un mes, pero a este cliente no se le concede.
        $plan   = $this->makePlan($tenant, 60000, Billing::FIRST_INVOICE_PRORATED, freeMonths: 1);
        $router = $this->makeRouter($tenant, createDay: 1);

        Carbon::setTestNow(Carbon::create(2026, 7, 20, 9, 0, 0));
        $user = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2026, 7, 16), freeMonths: 0);

        $this->billing->generateMonthlyInvoices();
        $this->runFor(2026, 8);

        $this->assertEquals(60000, (float) $this->invoiceOf($user, '2026-08')->total);
    }

    #[Test]
    public function the_router_supplies_the_promotion_when_the_plan_says_nothing(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant, 60000); // sin política propia
        $router = $this->makeRouter($tenant, createDay: 1, policy: Billing::FIRST_INVOICE_PRORATED, freeMonths: 1);

        Carbon::setTestNow(Carbon::create(2026, 7, 20, 9, 0, 0));
        $user = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2026, 7, 16));

        $this->billing->generateMonthlyInvoices();
        $this->assertEquals(29032, (float) $this->invoiceOf($user, '2026-07')->total);

        $this->runFor(2026, 8);
        $this->assertEquals(0, (float) $this->invoiceOf($user, '2026-08')->total);
    }

    #[Test]
    public function a_plan_without_a_promotion_keeps_billing_every_month(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant, 60000, Billing::FIRST_INVOICE_PRORATED);
        $router = $this->makeRouter($tenant, createDay: 1);

        Carbon::setTestNow(Carbon::create(2026, 7, 20, 9, 0, 0));
        $user = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2026, 7, 16));

        $this->billing->generateMonthlyInvoices();
        $this->runFor(2026, 8);

        $this->assertEquals(60000, (float) $this->invoiceOf($user, '2026-08')->total);
    }

    #[Test]
    public function an_established_customer_is_never_given_a_free_month(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant, 60000, Billing::FIRST_INVOICE_PRORATED, freeMonths: 1);
        $router = $this->makeRouter($tenant, createDay: 1);

        Carbon::setTestNow(Carbon::create(2026, 7, 1, 9, 0, 0));
        $user = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2025, 3, 10));

        $this->billing->generateMonthlyInvoices();
        $this->assertEquals(60000, (float) $this->invoiceOf($user, '2026-07')->total);

        $this->runFor(2026, 8);
        $this->assertEquals(60000, (float) $this->invoiceOf($user, '2026-08')->total);
    }

    // ── Idempotencia y auditoría ────────────────────────────────────────

    #[Test]
    public function the_courtesy_invoice_is_not_duplicated_by_the_hourly_run(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant, 60000, Billing::FIRST_INVOICE_PRORATED, freeMonths: 1);
        $router = $this->makeRouter($tenant, createDay: 1);

        Carbon::setTestNow(Carbon::create(2026, 7, 20, 9, 0, 0));
        $user = $this->makeCustomer($tenant, $router, $plan, Carbon::create(2026, 7, 16));

        $this->billing->generateMonthlyInvoices();
        $this->assertSame(1, $this->runFor(2026, 8));

        Carbon::setTestNow(Carbon::create(2026, 8, 1, 10, 0, 0));
        $this->assertSame(0, $this->billing->generateMonthlyInvoices());

        $this->assertSame(1, Invoice::where('customer_id', $user->id)
            ->whereDate('period_start', '>=', '2026-08-01')
            ->whereDate('period_start', '<=', '2026-08-31')
            ->count());
    }

    #[Test]
    public function the_audit_treats_a_courtesy_month_as_correctly_billed(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant, 60000, Billing::FIRST_INVOICE_PRORATED, freeMonths: 1);
        $router = $this->makeRouter($tenant, createDay: 1);

        Carbon::setTestNow(Carbon::create(2026, 7, 20, 9, 0, 0));
        $this->makeCustomer($tenant, $router, $plan, Carbon::create(2026, 7, 16));

        $this->billing->generateMonthlyInvoices();
        $this->runFor(2026, 8);

        Carbon::setTestNow(Carbon::create(2026, 8, 2, 9, 0, 0));
        $row = collect($this->billing->auditMonthlyBilling())->firstWhere('router_id', $router->id);

        $this->assertSame(1, $row['expected']);
        $this->assertSame(1, $row['actual']);
        $this->assertSame('ok', $row['status'], 'Un mes de cortesía no es una factura que falte');
    }

    // ── Resolución de la política (sin tocar la base) ────────────────────

    #[Test]
    public function the_cascade_resolves_each_axis_independently(): void
    {
        $profile = new CustomerProfile(['first_invoice_mode' => Billing::FIRST_INVOICE_FULL]);
        $plan    = new Plan(['first_invoice_mode' => Billing::FIRST_INVOICE_PRORATED, 'first_invoice_free_months' => 2]);
        $config  = new Billing(['first_invoice_policy' => Billing::FIRST_INVOICE_NONE, 'first_invoice_free_months' => 5]);

        $policy = FirstInvoicePolicy::resolve($profile, $plan, $config);

        // El modo lo pone el cliente; los meses gratis, el plan (el cliente no opina).
        $this->assertSame(Billing::FIRST_INVOICE_FULL, $policy->mode);
        $this->assertSame(FirstInvoicePolicy::SOURCE_CUSTOMER, $policy->modeSource);
        $this->assertSame(2, $policy->freeMonths);
        $this->assertSame(FirstInvoicePolicy::SOURCE_PLAN, $policy->freeMonthsSource);
    }

    #[Test]
    public function nothing_configured_anywhere_means_do_not_charge_and_no_free_months(): void
    {
        $policy = FirstInvoicePolicy::resolve(null, null, null);

        $this->assertSame(Billing::FIRST_INVOICE_NONE, $policy->mode);
        $this->assertSame(0, $policy->freeMonths);
    }

    // ── Vista previa del formulario ─────────────────────────────────────

    #[Test]
    public function the_form_preview_projects_the_same_numbers_that_will_be_billed(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant, 60000, Billing::FIRST_INVOICE_PRORATED, freeMonths: 1);
        $router = $this->makeRouter($tenant, createDay: 1);
        $staff  = User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($staff)->postJson('/api/customers/first-invoice-preview', [
            'installation_date' => '2026-07-16',
            'plan_id'           => $plan->id,
            'router_id'         => $router->id,
        ]);

        $response->assertOk();

        $months = $response->json('months');

        // Los mismos importes que produce la generación mensual: julio
        // prorrateado, agosto en cortesía y septiembre a tarifa plena.
        $this->assertSame('2026-07', $months[0]['period']);
        $this->assertEqualsWithDelta(29032, $months[0]['amount'], 0.01);
        $this->assertFalse($months[0]['free']);

        $this->assertSame('2026-08', $months[1]['period']);
        $this->assertTrue($months[1]['free']);
        $this->assertEqualsWithDelta(0, $months[1]['amount'], 0.01);

        $this->assertSame('2026-09', $months[2]['period']);
        $this->assertEqualsWithDelta(60000, $months[2]['amount'], 0.01);

        $this->assertSame(Billing::FIRST_INVOICE_PRORATED, $response->json('policy.mode'));
        $this->assertSame(1, $response->json('policy.free_months'));
        $this->assertSame(FirstInvoicePolicy::SOURCE_PLAN, $response->json('policy.free_months_source'));
    }

    #[Test]
    public function the_preview_reflects_the_override_the_operator_is_typing(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant, 60000, Billing::FIRST_INVOICE_PRORATED, freeMonths: 1);
        $router = $this->makeRouter($tenant, createDay: 1);
        $staff  = User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($staff)->postJson('/api/customers/first-invoice-preview', [
            'installation_date' => '2026-07-16',
            'plan_id'           => $plan->id,
            'router_id'         => $router->id,
            // El operador decide que a ESTE cliente no se le regala el mes.
            'first_invoice_free_months' => 0,
        ]);

        $response->assertOk();
        $this->assertSame(0, $response->json('policy.free_months'));
        $this->assertFalse($response->json('months.1.free'));
        $this->assertEqualsWithDelta(60000, $response->json('months.1.amount'), 0.01);
    }

    #[Test]
    public function a_zero_on_the_customer_still_beats_the_plan_promotion(): void
    {
        // 0 es una decisión ("a este cliente no se le regala nada"), no un
        // "sin configurar": tiene que ganarle al plan.
        $policy = FirstInvoicePolicy::resolve(
            new CustomerProfile(['first_invoice_free_months' => 0]),
            new Plan(['first_invoice_free_months' => 3]),
            null,
        );

        $this->assertSame(0, $policy->freeMonths);
    }
}
