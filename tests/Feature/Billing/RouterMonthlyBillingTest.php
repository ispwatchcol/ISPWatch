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
 * Verifies the core business rule the operator cares about:
 *
 *   "Once a month, based on the billing config attached to a router
 *    (routers > add > facturación), the system must generate invoices
 *    ONLY for the customers assigned to that router — on the configured
 *    create_invoice day."
 *
 * Every test freezes the clock so the create_invoice-day gate is
 * deterministic.
 */
class RouterMonthlyBillingTest extends TestCase
{
    use RefreshDatabase;

    protected BillingService $billing;

    /** Monotonic counter so customer_profile (name,last_name) stays unique. */
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

    // ────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────

    /**
     * Build a billing config. $createDay / $paymentDay are days of month;
     * only the day component is read by BillingService.
     */
    private function makeBilling(int $createDay, ?int $paymentDay = null): Billing
    {
        return Billing::create([
            'create_invoice' => Carbon::create(2026, 1, $createDay)->toDateString(),
            'payment_day'    => $paymentDay ? Carbon::create(2026, 1, $paymentDay)->toDateString() : null,
            'status'         => 'pending',
        ]);
    }

    private function makeRouter(Tenant $tenant, ?Billing $billing): Router
    {
        $this->seq++;

        return Router::create([
            'name'              => "Router {$this->seq}",
            'tenant_id'         => $tenant->id,
            'billing_router_id' => $billing?->id,
            'status'            => 'active',
        ]);
    }

    /**
     * Create a customer + profile (optionally assigned to a router) and an
     * active user_service pointing at $plan.
     */
    private function makeCustomer(
        Tenant $tenant,
        ?Router $router,
        Plan $plan,
        bool $profileActive = true,
        string $serviceStatus = UserService::STATUS_ACTIVE,
    ): User {
        $this->seq++;
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        CustomerProfile::create([
            'user_id'   => $user->id,
            'name'      => "Cliente{$this->seq}",
            'last_name' => "Apellido{$this->seq}",
            'router_id' => $router?->id,
            // customer_profile.status is a BOOLEAN column (true = active),
            // matching production (PostgreSQL).
            'status'    => $profileActive,
        ]);

        UserService::create([
            'user_id'         => $user->id,
            'service_plan_id' => $plan->id,
            'status'          => $serviceStatus,
            'start_date'      => Carbon::now(),
        ]);

        return $user;
    }

    private function makePlan(Tenant $tenant, float $cost = 50000, bool $courtesy = false): Plan
    {
        return Plan::factory()->create([
            'tenant_id'    => $tenant->id,
            'cost_product' => $cost,
            'is_courtesy'  => $courtesy,
        ]);
    }

    // ────────────────────────────────────────────────────────────────
    // Core scenario the operator asked about
    // ────────────────────────────────────────────────────────────────

    #[Test]
    public function it_generates_invoices_for_customers_of_a_router_on_its_create_invoice_day(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant, 50000);
        $config = $this->makeBilling(createDay: 15, paymentDay: 20);
        $router = $this->makeRouter($tenant, $config);
        $user   = $this->makeCustomer($tenant, $router, $plan);

        $count = $this->billing->generateMonthlyInvoices();

        $this->assertSame(1, $count);

        $invoice = Invoice::where('customer_id', $user->id)->first();
        $this->assertNotNull($invoice);
        $this->assertEquals($tenant->id, $invoice->tenant_id);
        $this->assertEquals(50000, $invoice->total);
        $this->assertEquals(50000, $invoice->balance_due);
        $this->assertEquals('2026-06-01', Carbon::parse($invoice->period_start)->toDateString());
        $this->assertEquals('2026-06-30', Carbon::parse($invoice->period_end)->toDateString());
        // payment_day = 20 → due date is the 20th of the issue month
        $this->assertEquals('2026-06-20', Carbon::parse($invoice->due_date)->toDateString());
    }

    #[Test]
    public function it_does_not_generate_before_the_create_invoice_day(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 10, 9, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $config = $this->makeBilling(createDay: 15);
        $router = $this->makeRouter($tenant, $config);
        $this->makeCustomer($tenant, $router, $plan);

        $count = $this->billing->generateMonthlyInvoices();

        $this->assertSame(0, $count);
        $this->assertSame(0, Invoice::count());
    }

    #[Test]
    public function it_recovers_and_generates_when_run_after_the_create_invoice_day(): void
    {
        // System was down on the 15th; job runs on the 18th instead.
        Carbon::setTestNow(Carbon::create(2026, 6, 18, 9, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $config = $this->makeBilling(createDay: 15);
        $router = $this->makeRouter($tenant, $config);
        $user   = $this->makeCustomer($tenant, $router, $plan);

        $count = $this->billing->generateMonthlyInvoices();

        $this->assertSame(1, $count);
        $this->assertSame(1, Invoice::where('customer_id', $user->id)->count());
    }

    // ────────────────────────────────────────────────────────────────
    // Exclusions
    // ────────────────────────────────────────────────────────────────

    #[Test]
    public function it_skips_customers_without_an_assigned_router(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $config = $this->makeBilling(createDay: 15);
        // Router exists with billing config, but the customer is NOT on it.
        $this->makeRouter($tenant, $config);
        $this->makeCustomer($tenant, null, $plan); // router_id = null

        $count = $this->billing->generateMonthlyInvoices();

        $this->assertSame(0, $count);
        $this->assertSame(0, Invoice::count());
    }

    #[Test]
    public function it_skips_customers_on_a_router_without_billing_config(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $router = $this->makeRouter($tenant, null); // billing_router_id = null
        $this->makeCustomer($tenant, $router, $plan);

        $count = $this->billing->generateMonthlyInvoices();

        $this->assertSame(0, $count);
        $this->assertSame(0, Invoice::count());
    }

    #[Test]
    public function it_skips_courtesy_plan_customers(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $tenant  = Tenant::factory()->create();
        $courtesy = $this->makePlan($tenant, 0, courtesy: true);
        $config  = $this->makeBilling(createDay: 15);
        $router  = $this->makeRouter($tenant, $config);
        $this->makeCustomer($tenant, $router, $courtesy, serviceStatus: UserService::STATUS_GRATIS);

        $count = $this->billing->generateMonthlyInvoices();

        $this->assertSame(0, $count);
        $this->assertSame(0, Invoice::count());
    }

    #[Test]
    public function it_skips_inactive_customer_profiles(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $config = $this->makeBilling(createDay: 15);
        $router = $this->makeRouter($tenant, $config);

        $active    = $this->makeCustomer($tenant, $router, $plan, profileActive: true);
        $suspended = $this->makeCustomer($tenant, $router, $plan, profileActive: false);

        $count = $this->billing->generateMonthlyInvoices();

        $this->assertSame(1, $count);
        $this->assertSame(1, Invoice::where('customer_id', $active->id)->count());
        $this->assertSame(0, Invoice::where('customer_id', $suspended->id)->count());
    }

    #[Test]
    public function it_skips_customers_without_an_active_service(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $config = $this->makeBilling(createDay: 15);
        $router = $this->makeRouter($tenant, $config);
        // Service is suspended, not active → no billable plan.
        $this->makeCustomer($tenant, $router, $plan, serviceStatus: 'suspended');

        $count = $this->billing->generateMonthlyInvoices();

        $this->assertSame(0, $count);
        $this->assertSame(0, Invoice::count());
    }

    // ────────────────────────────────────────────────────────────────
    // Multi-router gating + idempotency
    // ────────────────────────────────────────────────────────────────

    #[Test]
    public function only_the_router_whose_create_day_matches_today_is_billed(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);

        // Router A bills on the 15th → should run today.
        $routerA = $this->makeRouter($tenant, $this->makeBilling(createDay: 15));
        $userA   = $this->makeCustomer($tenant, $routerA, $plan);

        // Router B bills on the 28th → must NOT run on the 15th.
        $routerB = $this->makeRouter($tenant, $this->makeBilling(createDay: 28));
        $userB   = $this->makeCustomer($tenant, $routerB, $plan);

        $count = $this->billing->generateMonthlyInvoices();

        $this->assertSame(1, $count);
        $this->assertSame(1, Invoice::where('customer_id', $userA->id)->count());
        $this->assertSame(0, Invoice::where('customer_id', $userB->id)->count());
    }

    #[Test]
    public function running_twice_in_the_same_period_does_not_duplicate_invoices(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $config = $this->makeBilling(createDay: 15);
        $router = $this->makeRouter($tenant, $config);
        $user   = $this->makeCustomer($tenant, $router, $plan);

        $first  = $this->billing->generateMonthlyInvoices();
        $second = $this->billing->generateMonthlyInvoices();

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame(1, Invoice::where('customer_id', $user->id)->count());
    }

    // ────────────────────────────────────────────────────────────────
    // Command wiring (no console.php closure shadowing the class command)
    // ────────────────────────────────────────────────────────────────

    #[Test]
    public function the_artisan_command_runs_the_router_based_generation(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $config = $this->makeBilling(createDay: 15);
        $router = $this->makeRouter($tenant, $config);
        $user   = $this->makeCustomer($tenant, $router, $plan);

        $this->artisan('billing:generate-monthly')
            ->assertExitCode(0);

        $this->assertSame(1, Invoice::where('customer_id', $user->id)->count());
    }

    #[Test]
    public function the_command_accepts_an_explicit_period_argument(): void
    {
        // "Now" is July, but we explicitly bill the June period.
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 9, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $config = $this->makeBilling(createDay: 15);
        $router = $this->makeRouter($tenant, $config);
        $user   = $this->makeCustomer($tenant, $router, $plan);

        $this->artisan('billing:generate-monthly', ['period' => '2026-06'])
            ->assertExitCode(0);

        $invoice = Invoice::where('customer_id', $user->id)->firstOrFail();
        $this->assertEquals('2026-06-01', Carbon::parse($invoice->period_start)->toDateString());
        $this->assertEquals('2026-06-30', Carbon::parse($invoice->period_end)->toDateString());
    }
}
