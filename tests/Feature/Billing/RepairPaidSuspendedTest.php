<?php

namespace Tests\Feature\Billing;

use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Router;
use App\Models\SuspensionActionLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RouterProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `billing:repair-paid-suspended` limpia el pasivo del bug del § 43: clientes que
 * saldaron su cuenta y se quedaron marcados como suspendidos porque su corte
 * original nunca se confirmó en el router.
 */
class RepairPaidSuspendedTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function router(Tenant $tenant): Router
    {
        return Router::create([
            'name'      => 'Router ' . uniqid(),
            'tenant_id' => $tenant->id,
            'status'    => 'active',
        ]);
    }

    /**
     * Cliente cortado en la BD, con su corte en `failed` (el caso real de
     * producción) y las facturas que se indiquen.
     */
    private function suspended(
        Tenant $tenant,
        Router $router,
        int $overdueQty = 0,
        int $paidQty = 1,
        bool $excludeFromBilling = false,
    ): User {
        $this->seq++;
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        CustomerProfile::create([
            'user_id'              => $user->id,
            'tenant_id'            => $tenant->id,
            'name'                 => "Cliente{$this->seq}",
            'last_name'            => "Apellido{$this->seq}",
            'router_id'            => $router->id,
            'ip_user'              => '10.0.0.' . $this->seq,
            'status'               => false,
            'service_status'       => 'suspendido',
            'exclude_from_billing' => $excludeFromBilling,
        ]);

        $make = function (float $balance, string $status) use ($tenant, $user) {
            Invoice::create([
                'tenant_id'    => $tenant->id,
                'customer_id'  => $user->id,
                'number'       => uniqid('INV-'),
                'issue_date'   => now()->subDays(30),
                'due_date'     => now()->subDays(10),
                'period_start' => now()->subMonth()->startOfMonth(),
                'period_end'   => now()->subMonth()->endOfMonth(),
                'subtotal'     => 25000,
                'total'        => 25000,
                'balance_due'  => $balance,
                'status'       => $status,
            ]);
        };

        for ($i = 0; $i < $paidQty; $i++) {
            $make(0, 'paid');
        }
        for ($i = 0; $i < $overdueQty; $i++) {
            $make(25000, 'overdue');
        }

        SuspensionActionLog::create([
            'router_id'   => $router->id,
            'customer_id' => $user->id,
            'ip'          => '10.0.0.' . $this->seq,
            'action'      => SuspensionActionLog::ACTION_SUSPEND,
            'reason'      => SuspensionActionLog::REASON_RECONCILE,
            'status'      => SuspensionActionLog::STATUS_FAILED,
            'attempts'    => 4,
        ]);

        return $user;
    }

    // ────────────────────────────────────────────────────────────────

    #[Test]
    public function the_dry_run_reports_without_touching_anything(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->suspended($tenant, $this->router($tenant));

        $mock = Mockery::mock(RouterProvisioningService::class);
        $this->app->instance(RouterProvisioningService::class, $mock);
        $mock->shouldNotReceive('unsuspendCustomer');

        $this->artisan('billing:repair-paid-suspended')->assertSuccessful();

        $this->assertFalse((bool) CustomerProfile::where('user_id', $user->id)->first()->status);
    }

    #[Test]
    public function apply_reconnects_the_customer_who_already_paid(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->suspended($tenant, $this->router($tenant));

        $mock = Mockery::mock(RouterProvisioningService::class);
        $this->app->instance(RouterProvisioningService::class, $mock);
        $mock->shouldReceive('unsuspendCustomer')->once()->andReturn(true);

        $this->artisan('billing:repair-paid-suspended --apply')->assertSuccessful();

        $profile = CustomerProfile::where('user_id', $user->id)->first();
        $this->assertTrue((bool) $profile->status);
        $this->assertSame('activo', $profile->service_status);
    }

    #[Test]
    public function a_customer_who_still_owes_is_left_alone(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->suspended($tenant, $this->router($tenant), overdueQty: 2);

        $mock = Mockery::mock(RouterProvisioningService::class);
        $this->app->instance(RouterProvisioningService::class, $mock);
        $mock->shouldNotReceive('unsuspendCustomer');

        $this->artisan('billing:repair-paid-suspended --apply')->assertSuccessful();

        $this->assertFalse((bool) CustomerProfile::where('user_id', $user->id)->first()->status);
    }

    /**
     * El caso de MIRIAN (#985) en producción: sin ninguna factura, "quedó al día"
     * es vacío — nunca estuvo en mora, así que no hay nada que reparar.
     */
    #[Test]
    public function a_customer_without_any_invoice_is_not_a_candidate(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->suspended($tenant, $this->router($tenant), paidQty: 0, excludeFromBilling: true);

        $mock = Mockery::mock(RouterProvisioningService::class);
        $this->app->instance(RouterProvisioningService::class, $mock);
        $mock->shouldNotReceive('unsuspendCustomer');

        $this->artisan('billing:repair-paid-suspended --apply')->assertSuccessful();

        $this->assertFalse((bool) CustomerProfile::where('user_id', $user->id)->first()->status);
    }

    /**
     * El caso de GABRIEL (#755): marcado "no facturar", pero con una factura
     * vieja pagada en su totalidad. Ése sí saldó su cuenta y sí entra.
     */
    #[Test]
    public function an_excluded_customer_with_a_paid_invoice_is_still_repaired(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->suspended($tenant, $this->router($tenant), paidQty: 1, excludeFromBilling: true);

        $mock = Mockery::mock(RouterProvisioningService::class);
        $this->app->instance(RouterProvisioningService::class, $mock);
        $mock->shouldReceive('unsuspendCustomer')->once()->andReturn(true);

        $this->artisan('billing:repair-paid-suspended --apply')->assertSuccessful();

        $this->assertTrue((bool) CustomerProfile::where('user_id', $user->id)->first()->status);
    }

    #[Test]
    public function a_retired_customer_is_never_a_candidate(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->suspended($tenant, $this->router($tenant));
        CustomerProfile::where('user_id', $user->id)->update(['service_status' => 'retirado']);

        $mock = Mockery::mock(RouterProvisioningService::class);
        $this->app->instance(RouterProvisioningService::class, $mock);
        $mock->shouldNotReceive('unsuspendCustomer');

        $this->artisan('billing:repair-paid-suspended --apply')->assertSuccessful();

        $this->assertFalse((bool) CustomerProfile::where('user_id', $user->id)->first()->status);
    }

    #[Test]
    public function the_tenant_option_limits_the_sweep(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA   = $this->suspended($tenantA, $this->router($tenantA));
        $userB   = $this->suspended($tenantB, $this->router($tenantB));

        $mock = Mockery::mock(RouterProvisioningService::class);
        $this->app->instance(RouterProvisioningService::class, $mock);
        $mock->shouldReceive('unsuspendCustomer')->once()->with($userA->id, Mockery::any(), Mockery::any())->andReturn(true);

        $this->artisan("billing:repair-paid-suspended --apply --tenant={$tenantA->id}")->assertSuccessful();

        $this->assertTrue((bool) CustomerProfile::where('user_id', $userA->id)->first()->status);
        $this->assertFalse((bool) CustomerProfile::where('user_id', $userB->id)->first()->status);
    }
}
