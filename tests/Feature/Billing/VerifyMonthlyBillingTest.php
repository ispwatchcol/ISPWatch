<?php

namespace Tests\Feature\Billing;

use App\Models\Billing;
use App\Models\CustomerProfile;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserService;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the no-show detector — the blind spot the failover cannot see.
 *
 * The failover (billing:retry-failed) only retries per-customer creation
 * exceptions recorded in billing_action_logs. A router that was skipped
 * entirely, or a monthly job that never ran at all, leaves NO trace and
 * triggers NO retry. billing:verify-monthly reconstructs the same gating as
 * generation and alerts when "expected" invoices were never created.
 */
class VerifyMonthlyBillingTest extends TestCase
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

    private function makeBilling(int $createDay, string $mode = Billing::MODE_ANTICIPADO): Billing
    {
        return Billing::create([
            'create_invoice' => Carbon::create(2026, 1, $createDay)->toDateString(),
            'billing_mode'   => $mode,
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

    private function rowFor(array $rows, int $routerId): array
    {
        foreach ($rows as $r) {
            if ($r['router_id'] === $routerId) {
                return $r;
            }
        }
        $this->fail("No audit row for router {$routerId}");
    }

    // ────────────────────────────────────────────────────────────────

    #[Test]
    public function it_flags_a_no_show_when_a_due_router_generated_nothing(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $router = $this->makeRouter($tenant, $this->makeBilling(createDay: 15));
        $this->makeCustomer($tenant, $router, $plan);

        // Generation never ran → no invoices exist.
        $row = $this->rowFor($this->billing->auditMonthlyBilling(), $router->id);

        $this->assertSame('no_show', $row['status']);
        $this->assertTrue($row['due']);
        $this->assertSame(1, $row['expected']);
        $this->assertSame(0, $row['actual']);
    }

    #[Test]
    public function it_reports_ok_when_invoices_exist_for_the_due_router(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $router = $this->makeRouter($tenant, $this->makeBilling(createDay: 15));
        $this->makeCustomer($tenant, $router, $plan);

        $this->billing->generateMonthlyInvoices();

        $row = $this->rowFor($this->billing->auditMonthlyBilling(), $router->id);

        $this->assertSame('ok', $row['status']);
        $this->assertSame(1, $row['expected']);
        $this->assertSame(1, $row['actual']);
    }

    #[Test]
    public function it_does_not_flag_a_router_that_is_not_due_yet(): void
    {
        // Create day is the 20th; today is the 15th → not due, no alarm.
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $router = $this->makeRouter($tenant, $this->makeBilling(createDay: 20));
        $this->makeCustomer($tenant, $router, $plan);

        $row = $this->rowFor($this->billing->auditMonthlyBilling(), $router->id);

        $this->assertSame('pending', $row['status']);
        $this->assertFalse($row['due']);
        $this->assertSame(1, $row['expected']);
        $this->assertSame(0, $row['actual']);
    }

    #[Test]
    public function it_flags_partial_when_some_invoices_are_missing(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $router = $this->makeRouter($tenant, $this->makeBilling(createDay: 15));
        $this->makeCustomer($tenant, $router, $plan);
        $this->makeCustomer($tenant, $router, $plan);

        // Generate, then delete one invoice to simulate a partial run.
        $this->billing->generateMonthlyInvoices();
        \App\Models\Invoice::query()->latest('id')->first()->delete();

        $row = $this->rowFor($this->billing->auditMonthlyBilling(), $router->id);

        $this->assertSame('partial', $row['status']);
        $this->assertSame(2, $row['expected']);
        $this->assertSame(1, $row['actual']);
    }

    #[Test]
    public function courtesy_only_router_is_ok_with_zero_invoices(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $tenant   = Tenant::factory()->create();
        $courtesy = $this->makePlan($tenant, 0, courtesy: true);
        $router   = $this->makeRouter($tenant, $this->makeBilling(createDay: 15));
        $this->makeCustomer($tenant, $router, $courtesy, serviceStatus: UserService::STATUS_GRATIS);

        $row = $this->rowFor($this->billing->auditMonthlyBilling(), $router->id);

        // Nothing was expected, so zero invoices is correct — not a no-show.
        $this->assertSame('ok', $row['status']);
        $this->assertSame(0, $row['expected']);
        $this->assertSame(0, $row['actual']);
    }

    #[Test]
    public function the_command_exits_nonzero_on_a_no_show(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));
        Mail::fake();

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $router = $this->makeRouter($tenant, $this->makeBilling(createDay: 15));
        $this->makeCustomer($tenant, $router, $plan);

        $this->artisan('billing:verify-monthly', ['--no-mail' => true])
            ->assertExitCode(1);
    }

    #[Test]
    public function the_command_exits_zero_when_everything_billed(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0));

        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $router = $this->makeRouter($tenant, $this->makeBilling(createDay: 15));
        $this->makeCustomer($tenant, $router, $plan);

        $this->billing->generateMonthlyInvoices();

        $this->artisan('billing:verify-monthly', ['--no-mail' => true])
            ->assertExitCode(0);
    }
}
