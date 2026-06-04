<?php

namespace Tests\Feature\Billing;

use App\Models\Billing;
use App\Models\CutType;
use App\Models\CustomerProfile;
use App\Models\Router;
use App\Models\SuspensionActionLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OverdueSuspensionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReconcileSuspensionsTest extends TestCase
{
    use RefreshDatabase;

    // ────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────

    protected function makeRouter(Tenant $tenant): Router
    {
        $billing = Billing::create([
            'cut_day' => now()->format('Y-m-d'),
            'cut_time' => '00:00:00',
            'overdue_invoices' => 1,
            'status' => 'pending',
        ]);

        $cutType = CutType::firstOrCreate(['name' => 'Corte Automático']);

        return Router::create([
            'name' => 'Router ' . uniqid(),
            'tenant_id' => $tenant->id,
            'cut_type_id' => $cutType->id,
            'billing_router_id' => $billing->id,
            'status' => 'active',
        ]);
    }

    /** A customer suspended in the DB (status=false) with a router + IP. */
    protected function makeSuspendedCustomer(Tenant $tenant, Router $router): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        CustomerProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'name' => 'Test',
                'last_name' => 'Suspendido',
                'router_id' => $router->id,
                'ip_user' => '10.0.0.' . random_int(2, 250),
                'status' => false,
                'service_status' => 'suspendido',
            ]
        );

        return $user;
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
    public function reblocks_db_suspended_customer_without_confirmed_cut(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->makeRouter($tenant);
        $customer = $this->makeSuspendedCustomer($tenant, $router);

        $mock = $this->mockProvisioning();
        $mock->shouldReceive('suspendCustomer')
            ->once()
            ->with($customer->id, $router->id, Mockery::on(fn($ctx) => ($ctx['reason'] ?? null) === SuspensionActionLog::REASON_RECONCILE))
            ->andReturn(true);

        $stats = app(OverdueSuspensionService::class)->reconcileSuspensions();

        $this->assertEquals(1, $stats['scanned']);
        $this->assertEquals(1, $stats['reblocked_ok']);
        $this->assertEquals(0, $stats['already_confirmed']);
    }

    #[Test]
    public function skips_customer_with_confirmed_suspend_log(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->makeRouter($tenant);
        $customer = $this->makeSuspendedCustomer($tenant, $router);

        SuspensionActionLog::create([
            'router_id'   => $router->id,
            'customer_id' => $customer->id,
            'ip'          => '10.0.0.5',
            'action'      => SuspensionActionLog::ACTION_SUSPEND,
            'status'      => SuspensionActionLog::STATUS_SUCCESS,
            'attempts'    => 1,
        ]);

        $mock = $this->mockProvisioning();
        $mock->shouldNotReceive('suspendCustomer');

        $stats = app(OverdueSuspensionService::class)->reconcileSuspensions();

        $this->assertEquals(1, $stats['scanned']);
        $this->assertEquals(1, $stats['already_confirmed']);
        $this->assertEquals(0, $stats['reblocked_ok']);
    }

    #[Test]
    public function reblocks_when_last_action_was_unsuspend(): void
    {
        // A later UNSUSPEND/success means the RB is NOT blocking → must re-cut.
        $tenant = Tenant::factory()->create();
        $router = $this->makeRouter($tenant);
        $customer = $this->makeSuspendedCustomer($tenant, $router);

        SuspensionActionLog::create([
            'router_id' => $router->id, 'customer_id' => $customer->id, 'ip' => '10.0.0.5',
            'action' => SuspensionActionLog::ACTION_SUSPEND, 'status' => SuspensionActionLog::STATUS_SUCCESS, 'attempts' => 1,
        ]);
        SuspensionActionLog::create([
            'router_id' => $router->id, 'customer_id' => $customer->id, 'ip' => '10.0.0.5',
            'action' => SuspensionActionLog::ACTION_UNSUSPEND, 'status' => SuspensionActionLog::STATUS_SUCCESS, 'attempts' => 1,
        ]);

        $mock = $this->mockProvisioning();
        $mock->shouldReceive('suspendCustomer')->once()->andReturn(true);

        $stats = app(OverdueSuspensionService::class)->reconcileSuspensions();

        $this->assertEquals(1, $stats['reblocked_ok']);
    }

    #[Test]
    public function honors_backoff_for_recent_failed_attempt(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->makeRouter($tenant);
        $customer = $this->makeSuspendedCustomer($tenant, $router);

        SuspensionActionLog::create([
            'router_id' => $router->id, 'customer_id' => $customer->id, 'ip' => '10.0.0.5',
            'action' => SuspensionActionLog::ACTION_SUSPEND, 'status' => SuspensionActionLog::STATUS_FAILED,
            'attempts' => 1, 'next_retry_at' => now()->addHour(),
        ]);

        $mock = $this->mockProvisioning();
        $mock->shouldNotReceive('suspendCustomer');

        $stats = app(OverdueSuspensionService::class)->reconcileSuspensions();

        $this->assertEquals(1, $stats['skipped_backoff']);
        $this->assertEquals(0, $stats['reblocked_ok']);
    }

    #[Test]
    public function stops_retrying_an_exhausted_cut_and_leaves_it_for_manual(): void
    {
        // A SUSPEND that already failed MAX_ATTEMPTS times must NOT be retried
        // automatically — it stays for manual handling (MassActions needs_manual).
        $tenant = Tenant::factory()->create();
        $router = $this->makeRouter($tenant);
        $customer = $this->makeSuspendedCustomer($tenant, $router);

        SuspensionActionLog::create([
            'router_id' => $router->id, 'customer_id' => $customer->id, 'ip' => '10.0.0.5',
            'action' => SuspensionActionLog::ACTION_SUSPEND, 'status' => SuspensionActionLog::STATUS_FAILED,
            'attempts' => SuspensionActionLog::MAX_ATTEMPTS, 'next_retry_at' => null,
        ]);

        $mock = $this->mockProvisioning();
        $mock->shouldNotReceive('suspendCustomer');

        $stats = app(OverdueSuspensionService::class)->reconcileSuspensions();

        $this->assertEquals(1, $stats['skipped_exhausted']);
        $this->assertEquals(0, $stats['reblocked_ok']);
    }

    #[Test]
    public function force_retries_even_an_exhausted_cut(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->makeRouter($tenant);
        $customer = $this->makeSuspendedCustomer($tenant, $router);

        SuspensionActionLog::create([
            'router_id' => $router->id, 'customer_id' => $customer->id, 'ip' => '10.0.0.5',
            'action' => SuspensionActionLog::ACTION_SUSPEND, 'status' => SuspensionActionLog::STATUS_FAILED,
            'attempts' => SuspensionActionLog::MAX_ATTEMPTS, 'next_retry_at' => null,
        ]);

        $mock = $this->mockProvisioning();
        $mock->shouldReceive('suspendCustomer')->once()->andReturn(true);

        $stats = app(OverdueSuspensionService::class)->reconcileSuspensions(null, false, true);

        $this->assertEquals(0, $stats['skipped_exhausted']);
        $this->assertEquals(1, $stats['reblocked_ok']);
    }

    #[Test]
    public function dry_run_never_touches_the_router(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->makeRouter($tenant);
        $this->makeSuspendedCustomer($tenant, $router);

        $mock = $this->mockProvisioning();
        $mock->shouldNotReceive('suspendCustomer');

        $stats = app(OverdueSuspensionService::class)->reconcileSuspensions(null, true, false);

        $this->assertEquals(1, $stats['scanned']);
        $this->assertEquals(1, $stats['would_reblock']);
        $this->assertEquals(0, $stats['reblocked_ok']);
    }

    #[Test]
    public function ignores_active_customers(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->makeRouter($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        CustomerProfile::updateOrCreate(
            ['user_id' => $user->id],
            ['name' => 'Activo', 'last_name' => 'Cliente', 'router_id' => $router->id, 'ip_user' => '10.0.0.9', 'status' => true]
        );

        $mock = $this->mockProvisioning();
        $mock->shouldNotReceive('suspendCustomer');

        $stats = app(OverdueSuspensionService::class)->reconcileSuspensions();

        $this->assertEquals(0, $stats['scanned']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
