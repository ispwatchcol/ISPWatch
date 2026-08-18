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

    private function makeCutCustomer(
        Tenant $tenant,
        Router $router,
        string $reason,
        int $overdueQty,
        float $each = 25000,
        string $logStatus = SuspensionActionLog::STATUS_SUCCESS,
        string $serviceStatus = 'suspendido',
    ): User {
        $this->seq++;
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        CustomerProfile::create([
            'user_id'        => $user->id,
            'name'           => "Cliente{$this->seq}",
            'last_name'      => "Apellido{$this->seq}",
            'router_id'      => $router->id,
            'ip_user'        => '10.0.0.' . $this->seq,
            'status'         => false, // currently cut
            'service_status' => $serviceStatus,
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

        // Last suspension log = a SUSPEND with the given reason and status.
        SuspensionActionLog::create([
            'router_id'   => $router->id,
            'customer_id' => $user->id,
            'ip'          => '10.0.0.' . $this->seq,
            'action'      => SuspensionActionLog::ACTION_SUSPEND,
            'reason'      => $reason,
            'status'      => $logStatus,
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

    /**
     * El caso que se estaba escapando en producción: el corte quedó registrado
     * como SUSPEND/failed (el router no respondió) pero la BD sí quedó en
     * suspendido. Antes esto no contaba como "cortado" y el cliente pagaba,
     * seguía marcado suspendido y el reconciliador lo volvía a cortar.
     */
    #[Test]
    public function a_customer_whose_cut_log_failed_is_still_reconnected_after_paying(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant);
        $user   = $this->makeCutCustomer(
            $tenant,
            $router,
            SuspensionActionLog::REASON_AUTO_CUT,
            overdueQty: 1,
            each: 25000,
            logStatus: SuspensionActionLog::STATUS_FAILED,
        );

        $mock = $this->mockProvisioning();
        $mock->shouldReceive('unsuspendCustomer')->once()->andReturn(true);

        app(BillingService::class)->registerPayment([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $user->id,
            'amount'       => 25000,
            'payment_date' => now()->toDateString(),
            'method'       => 'cash',
        ]);

        $profile = CustomerProfile::where('user_id', $user->id)->first();
        $this->assertTrue((bool) $profile->status);
        $this->assertSame('activo', $profile->service_status);
    }

    /**
     * Cliente suspendido en la BD sin ningún log de corte (cortes viejos,
     * importados o hechos a mano en el equipo). Pagar tiene que dejarlo activo.
     */
    #[Test]
    public function a_db_suspended_customer_without_any_log_is_reconnected_after_paying(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant);
        $user   = $this->makeCutCustomer($tenant, $router, SuspensionActionLog::REASON_AUTO_CUT, overdueQty: 1, each: 25000);
        SuspensionActionLog::where('customer_id', $user->id)->delete();

        $mock = $this->mockProvisioning();
        $mock->shouldReceive('unsuspendCustomer')->once()->andReturn(true);

        app(BillingService::class)->registerPayment([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $user->id,
            'amount'       => 25000,
            'payment_date' => now()->toDateString(),
            'method'       => 'cash',
        ]);

        $this->assertTrue((bool) CustomerProfile::where('user_id', $user->id)->first()->status);
    }

    /**
     * Si el router NO confirma la reconexión, la BD se corrige igual (el cliente
     * ya no debe, y dejarlo en status=false haría que el reconciliador lo
     * re-cortara), pero el resultado avisa que hay que revisar el equipo.
     */
    #[Test]
    public function a_failed_router_unsuspend_still_clears_the_db_cut_and_reports_it(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant);
        $user   = $this->makeCutCustomer($tenant, $router, SuspensionActionLog::REASON_AUTO_CUT, overdueQty: 1, each: 25000);

        $mock = $this->mockProvisioning();
        $mock->shouldReceive('unsuspendCustomer')->once()->andReturn(false);

        $payment = app(BillingService::class)->registerPayment([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $user->id,
            'amount'       => 25000,
            'payment_date' => now()->toDateString(),
            'method'       => 'cash',
        ]);

        $this->assertTrue((bool) CustomerProfile::where('user_id', $user->id)->first()->status);
        $this->assertTrue($payment->reactivation['was_suspended']);
        $this->assertTrue($payment->reactivation['reactivated']);
        $this->assertFalse($payment->reactivation['router_ok']);
        $this->assertStringContainsString('NO confirmó', $payment->reactivation['message']);
    }

    /**
     * Retirado / cancelado son bajas definitivas: pagar una deuda vieja no
     * revive el servicio.
     */
    #[Test]
    public function a_retired_customer_is_not_reactivated_by_a_payment(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant);
        $user   = $this->makeCutCustomer(
            $tenant,
            $router,
            SuspensionActionLog::REASON_MANUAL,
            overdueQty: 1,
            each: 25000,
            serviceStatus: 'retirado',
        );

        $mock = $this->mockProvisioning();
        $mock->shouldNotReceive('unsuspendCustomer');

        $payment = app(BillingService::class)->registerPayment([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $user->id,
            'amount'       => 25000,
            'payment_date' => now()->toDateString(),
            'method'       => 'cash',
        ]);

        $profile = CustomerProfile::where('user_id', $user->id)->first();
        $this->assertFalse((bool) $profile->status);
        $this->assertSame('retirado', $profile->service_status);
        $this->assertFalse($payment->reactivation['was_suspended']);
    }

    /**
     * El aviso previo: el cajero tiene que ver que el cliente está cortado
     * ANTES de registrar el pago.
     */
    #[Test]
    public function the_balance_endpoint_reports_the_suspension_so_the_cashier_is_warned(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant);
        $user   = $this->makeCutCustomer($tenant, $router, SuspensionActionLog::REASON_AUTO_CUT, overdueQty: 1, each: 25000);

        $status = app(BillingService::class)->suspensionStatusFor($user->id);

        $this->assertTrue($status['is_suspended']);
        $this->assertSame('suspendido', $status['service_status']);
    }

    #[Test]
    public function an_active_customer_reports_no_suspension(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);
        CustomerProfile::create([
            'user_id'        => $user->id,
            'name'           => 'Activo',
            'last_name'      => 'Corriente',
            'status'         => true,
            'service_status' => 'activo',
        ]);

        $this->assertFalse(app(BillingService::class)->suspensionStatusFor($user->id)['is_suspended']);
    }
}
