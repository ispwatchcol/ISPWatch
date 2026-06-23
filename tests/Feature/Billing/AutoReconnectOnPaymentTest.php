<?php

namespace Tests\Feature\Billing;

use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Router;
use App\Models\SuspensionActionLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingService;
use App\Services\RouterProvisioningService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The mirror of the automatic cut: when a payment clears a customer's overdue
 * balance, lift the billing-driven block automatically. Customers cut manually
 * (e.g. abuse) must NOT be auto-reconnected.
 */
class AutoReconnectOnPaymentTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function tearDown(): void
    {
        Mockery::close();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function mockProvisioning(): \Mockery\MockInterface
    {
        $mock = Mockery::mock(RouterProvisioningService::class);
        $this->app->instance(RouterProvisioningService::class, $mock);
        return $mock;
    }

    private function makeCutCustomer(Tenant $tenant, Router $router, string $reason, int $overdueQty, float $each = 25000): User
    {
        $this->seq++;
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        CustomerProfile::create([
            'user_id'   => $user->id,
            'name'      => "Cliente{$this->seq}",
            'last_name' => "Apellido{$this->seq}",
            'router_id' => $router->id,
            'ip_user'   => '10.0.0.' . $this->seq,
            'status'    => false, // currently cut
        ]);

        for ($i = 0; $i < $overdueQty; $i++) {
            Invoice::create([
                'tenant_id'    => $tenant->id,
                'customer_id'  => $user->id,
                'number'       => uniqid('INV-'),
                'issue_date'   => now()->subDays(30),
                'due_date'     => now()->subDays(10),
                'period_start' => now()->subMonth()->startOfMonth(),
                'period_end'   => now()->subMonth()->endOfMonth(),
                'subtotal'     => $each,
                'total'        => $each,
                'balance_due'  => $each,
                'status'       => 'overdue',
            ]);
        }

        // Last suspension log = a successful SUSPEND with the given reason.
        SuspensionActionLog::create([
            'router_id'   => $router->id,
            'customer_id' => $user->id,
            'ip'          => '10.0.0.' . $this->seq,
            'action'      => SuspensionActionLog::ACTION_SUSPEND,
            'reason'      => $reason,
            'status'      => SuspensionActionLog::STATUS_SUCCESS,
            'attempts'    => 1,
        ]);

        return $user;
    }

    private function router(Tenant $tenant): Router
    {
        return Router::create([
            'name'      => 'Router ' . uniqid(),
            'tenant_id' => $tenant->id,
            'status'    => 'active',
        ]);
    }

    // ────────────────────────────────────────────────────────────────

    #[Test]
    public function paying_off_an_auto_cut_customer_reconnects_them(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant);
        $user   = $this->makeCutCustomer($tenant, $router, SuspensionActionLog::REASON_AUTO_CUT, overdueQty: 1, each: 25000);

        $mock = $this->mockProvisioning();
        $mock->shouldReceive('unsuspendCustomer')
            ->once()
            ->with($user->id, $router->id, Mockery::on(fn ($ctx) => ($ctx['reason'] ?? null) === SuspensionActionLog::REASON_AUTO_RECONNECT))
            ->andReturn(true);

        app(BillingService::class)->registerPayment([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $user->id,
            'amount'       => 25000,
            'payment_date' => now()->toDateString(),
            'method'       => 'cash',
        ]);

        $this->assertTrue((bool) CustomerProfile::where('user_id', $user->id)->first()->status);
    }

    #[Test]
    public function a_partial_payment_that_leaves_overdue_does_not_reconnect(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant);
        // Two overdue invoices; pay only one off.
        $user   = $this->makeCutCustomer($tenant, $router, SuspensionActionLog::REASON_AUTO_CUT, overdueQty: 2, each: 25000);

        $mock = $this->mockProvisioning();
        $mock->shouldNotReceive('unsuspendCustomer');

        app(BillingService::class)->registerPayment([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $user->id,
            'amount'       => 25000, // clears only 1 of 2
            'payment_date' => now()->toDateString(),
            'method'       => 'cash',
        ]);

        $this->assertFalse((bool) CustomerProfile::where('user_id', $user->id)->first()->status);
    }

    #[Test]
    public function a_manually_suspended_customer_is_reconnected_after_paying(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant);
        // Per operator policy, paying off the balance reconnects ANY current
        // cut — including manual suspensions, not only billing-driven ones.
        $user   = $this->makeCutCustomer($tenant, $router, SuspensionActionLog::REASON_MANUAL, overdueQty: 1, each: 25000);

        $mock = $this->mockProvisioning();
        $mock->shouldReceive('unsuspendCustomer')
            ->once()
            ->with($user->id, $router->id, Mockery::on(fn ($ctx) => ($ctx['reason'] ?? null) === SuspensionActionLog::REASON_AUTO_RECONNECT))
            ->andReturn(true);

        app(BillingService::class)->registerPayment([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $user->id,
            'amount'       => 25000,
            'payment_date' => now()->toDateString(),
            'method'       => 'cash',
        ]);

        $this->assertTrue((bool) CustomerProfile::where('user_id', $user->id)->first()->status);
    }
}
