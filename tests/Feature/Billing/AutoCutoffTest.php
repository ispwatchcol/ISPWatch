<?php

namespace Tests\Feature\Billing;

use App\Models\Billing;
use App\Models\CutType;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OverdueSuspensionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AutoCutoffTest extends TestCase
{
    use RefreshDatabase;

    // ────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────

    protected function makeBilling(array $attrs = []): Billing
    {
        return Billing::create(array_merge([
            'cut_day' => now()->format('Y-m-d'),
            'cut_time' => '00:00:00',
            'overdue_invoices' => 1,
            'status' => 'pending',
        ], $attrs));
    }

    protected function makeRouter(string $cutTypeName, Billing $billing, Tenant $tenant): Router
    {
        $cutType = CutType::firstOrCreate(['name' => $cutTypeName]);

        return Router::create([
            'name' => 'Router ' . uniqid(),
            'tenant_id' => $tenant->id,
            'cut_type_id' => $cutType->id,
            'billing_router_id' => $billing->id,
            'status' => 'active',
        ]);
    }

    protected function makeCustomerWithOverdueInvoices(User $user, Router $router, int $qty): void
    {
        CustomerProfile::updateOrCreate(
            ['user_id' => $user->id],
            // status is a BOOLEAN column (true = active), matching production.
            ['name' => 'Test ' . uniqid(), 'last_name' => 'User', 'router_id' => $router->id, 'status' => true]
        );

        for ($i = 0; $i < $qty; $i++) {
            Invoice::create([
                'tenant_id' => $user->tenant_id,
                'customer_id' => $user->id,
                'number' => uniqid('INV-'),
                'issue_date' => now()->subDays(30),
                'due_date' => now()->subDays(10),
                'period_start' => now()->subMonth()->startOfMonth(),
                'period_end' => now()->subMonth()->endOfMonth(),
                'subtotal' => 25000,
                'total' => 25000,
                'balance_due' => 25000,
                'status' => 'overdue',
            ]);
        }
    }

    protected function mockProvisioning(): \Mockery\MockInterface
    {
        $mock = Mockery::mock(\App\Services\RouterProvisioningService::class);
        $this->app->instance(\App\Services\RouterProvisioningService::class, $mock);
        return $mock;
    }

    // ────────────────────────────────────────────────────────────
    // Tests
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function suspends_customer_with_enough_overdue_invoices(): void
    {
        $tenant = Tenant::factory()->create();
        $billing = $this->makeBilling(['overdue_invoices' => 1]);
        $router = $this->makeRouter('Corte Automático', $billing, $tenant);
        $customer = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->makeCustomerWithOverdueInvoices($customer, $router, 1);

        $mock = $this->mockProvisioning();
        $mock->shouldReceive('suspendCustomer')
            ->once()
            ->andReturn(true);

        $stats = app(OverdueSuspensionService::class)->processOverdueInvoices();

        $this->assertEquals(1, $stats['suspended']);
        $this->assertEquals(0, $stats['errors']);
    }

    #[Test]
    public function suspending_sets_the_profile_status_to_false(): void
    {
        $tenant = Tenant::factory()->create();
        $billing = $this->makeBilling(['overdue_invoices' => 1]);
        $router = $this->makeRouter('Corte Automático', $billing, $tenant);
        $customer = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->makeCustomerWithOverdueInvoices($customer, $router, 1);

        $mock = $this->mockProvisioning();
        $mock->shouldReceive('suspendCustomer')->once()->andReturn(true);

        app(OverdueSuspensionService::class)->processOverdueInvoices();

        $profile = CustomerProfile::where('user_id', $customer->id)->first();
        $this->assertFalse((bool) $profile->status);
        $this->assertSame('suspendido', $profile->service_status);
    }

    #[Test]
    public function does_not_suspend_if_overdue_count_below_threshold(): void
    {
        $tenant = Tenant::factory()->create();
        $billing = $this->makeBilling(['overdue_invoices' => 3]);
        $router = $this->makeRouter('Corte Automático', $billing, $tenant);
        $customer = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->makeCustomerWithOverdueInvoices($customer, $router, 2);

        $mock = $this->mockProvisioning();
        $mock->shouldNotReceive('suspendCustomer');

        $stats = app(OverdueSuspensionService::class)->processOverdueInvoices();

        $this->assertEquals(0, $stats['suspended']);
    }

    #[Test]
    public function does_not_suspend_if_cut_day_is_in_future(): void
    {
        $tenant = Tenant::factory()->create();
        $billing = $this->makeBilling([
            'cut_day' => now()->addDay()->format('Y-m-d'),
            'overdue_invoices' => 1,
        ]);
        $router = $this->makeRouter('Corte Automático', $billing, $tenant);
        $customer = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->makeCustomerWithOverdueInvoices($customer, $router, 2);

        $mock = $this->mockProvisioning();
        $mock->shouldNotReceive('suspendCustomer');

        $stats = app(OverdueSuspensionService::class)->processOverdueInvoices();

        $this->assertEquals(0, $stats['suspended']);
    }

    #[Test]
    public function does_not_suspend_if_cut_time_not_reached(): void
    {
        $tenant = Tenant::factory()->create();
        $billing = $this->makeBilling([
            'cut_day' => now()->format('Y-m-d'),
            'cut_time' => '23:59:00',
            'overdue_invoices' => 1,
        ]);
        $router = $this->makeRouter('Corte Automático', $billing, $tenant);
        $customer = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->makeCustomerWithOverdueInvoices($customer, $router, 1);

        $mock = $this->mockProvisioning();
        $mock->shouldNotReceive('suspendCustomer');

        $stats = app(OverdueSuspensionService::class)->processOverdueInvoices();

        $this->assertEquals(0, $stats['suspended']);
    }

    #[Test]
    public function marks_manual_pending_for_corte_manual_routers(): void
    {
        $tenant = Tenant::factory()->create();
        $billing = $this->makeBilling(['overdue_invoices' => 1]);
        $router = $this->makeRouter('Corte Manual', $billing, $tenant);
        $customer = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->makeCustomerWithOverdueInvoices($customer, $router, 1);

        $mock = $this->mockProvisioning();
        $mock->shouldNotReceive('suspendCustomer');

        $stats = app(OverdueSuspensionService::class)->processOverdueInvoices();

        $this->assertEquals(0, $stats['suspended']);
        $this->assertEquals(1, $stats['manual_pending']);
    }

    #[Test]
    public function filters_by_specific_router_id(): void
    {
        $tenant = Tenant::factory()->create();
        $billing1 = $this->makeBilling(['overdue_invoices' => 1]);
        $billing2 = $this->makeBilling(['overdue_invoices' => 1]);
        $router1 = $this->makeRouter('Corte Automático', $billing1, $tenant);
        $router2 = $this->makeRouter('Corte Automático', $billing2, $tenant);
        $customer1 = User::factory()->create(['tenant_id' => $tenant->id]);
        $customer2 = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->makeCustomerWithOverdueInvoices($customer1, $router1, 1);
        $this->makeCustomerWithOverdueInvoices($customer2, $router2, 1);

        $mock = $this->mockProvisioning();
        $mock->shouldReceive('suspendCustomer')
            ->once()
            ->with($customer1->id, $router1->id, Mockery::any())
            ->andReturn(true);

        $stats = app(OverdueSuspensionService::class)->processOverdueInvoices($router1->id);

        $this->assertEquals(1, $stats['suspended']);
        $this->assertEquals(1, $stats['routers_processed']);
    }

    #[Test]
    public function cut_day_31_fires_on_feb_28_in_a_non_leap_year(): void
    {
        // 2026 is not a leap year → February has 28 days; a configured
        // cut_day of 31 must clamp to 28 so the cut still happens.
        Carbon::setTestNow(Carbon::create(2026, 2, 28, 9, 0, 0));

        $tenant  = Tenant::factory()->create();
        $billing = $this->makeBilling([
            'cut_day'          => Carbon::create(2026, 1, 31)->toDateString(),
            'cut_time'         => '00:00:00',
            'overdue_invoices' => 1,
        ]);
        $router   = $this->makeRouter('Corte Automático', $billing, $tenant);
        $customer = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->makeCustomerWithOverdueInvoices($customer, $router, 1);

        $mock = $this->mockProvisioning();
        $mock->shouldReceive('suspendCustomer')->once()->andReturn(true);

        $stats = app(OverdueSuspensionService::class)->processOverdueInvoices();

        $this->assertEquals(1, $stats['suspended']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }
}
