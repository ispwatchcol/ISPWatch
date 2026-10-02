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
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Un error con un cliente o con un router no detiene la corrida mensual.
 *
 * Origen: el 2026-10-01 los routers 57 y 58 del tenant 19 no recibieron
 * mensualidades —sin filas en billing_action_logs— mientras otro router del
 * mismo tenant sí facturaba. Sólo la creación de la factura estaba dentro del
 * try/catch; una excepción antes de ella (adicionales, primera factura, tope,
 * configuración del router) cortaba la corrida entera y el comando la imprimía
 * en una consola que el scheduler descarta.
 *
 * Los fallos se provocan con FailingBillingService, que lanza en los puntos que
 * antes quedaban FUERA del try.
 */
class MonthlyRunResilienceTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 10, 1, 9, 30, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────

    private function failing(): FailingBillingService
    {
        $service = new FailingBillingService(app(WhatsAppService::class));
        $this->app->instance(BillingService::class, $service);

        return $service;
    }

    private function makeRouter(Tenant $tenant): Router
    {
        $this->seq++;

        $config = Billing::create([
            'create_invoice'      => Carbon::create(2026, 1, 1)->toDateString(),
            'create_invoice_time' => '09:00:00',
            'payment_day'         => Carbon::create(2026, 1, 10)->toDateString(),
            'overdue_invoices'    => 2,
            'billing_mode'        => Billing::MODE_ANTICIPADO,
            'status'              => 'pending',
        ]);

        return Router::create([
            'name'              => "Router {$this->seq}",
            'tenant_id'         => $tenant->id,
            'billing_router_id' => $config->id,
            'status'            => 'active',
        ]);
    }

    /** Cliente antiguo y facturable. Sin $plan: sin servicio activo. */
    private function makeCustomer(Tenant $tenant, Router $router, ?Plan $plan): User
    {
        $this->seq++;
        $start = Carbon::create(2026, 3, 1);

        $user = User::factory()->create(['tenant_id' => $tenant->id, 'created_at' => $start]);

        CustomerProfile::create([
            'user_id'           => $user->id,
            'name'              => "Cliente{$this->seq}",
            'last_name'         => "Apellido{$this->seq}",
            'router_id'         => $router->id,
            'status'            => true,
            'service_status'    => 'activo',
            'installation_date' => $start->toDateString(),
        ]);

        if ($plan) {
            UserService::create([
                'user_id'         => $user->id,
                'service_plan_id' => $plan->id,
                'status'          => UserService::STATUS_ACTIVE,
                'start_date'      => $start,
            ]);
        }

        return $user;
    }

    private function makePlan(Tenant $tenant): Plan
    {
        return Plan::factory()->create([
            'tenant_id'    => $tenant->id,
            'cost_product' => 50000,
            'is_courtesy'  => false,
        ]);
    }

    private function octoberInvoices(User $user)
    {
        return Invoice::where('customer_id', $user->id)
            ->whereDate('period_start', '>=', '2026-10-01')
            ->whereDate('period_start', '<=', '2026-10-31');
    }

    // ────────────────────────────────────────────────────────────────
    // Tests
    // ────────────────────────────────────────────────────────────────

    #[Test]
    public function a_customer_that_fails_before_invoice_creation_does_not_stop_the_rest_of_its_router(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $router = $this->makeRouter($tenant);

        $antes   = $this->makeCustomer($tenant, $router, $plan);
        $roto    = $this->makeCustomer($tenant, $router, $plan);
        $despues = $this->makeCustomer($tenant, $router, $plan);

        $service = $this->failing();
        $service->failFirstInvoiceFor = [$roto->id];

        $created = $service->generateMonthlyInvoices();

        $this->assertSame(2, $created);
        $this->assertSame(1, $this->octoberInvoices($antes)->count());
        $this->assertSame(1, $this->octoberInvoices($despues)->count());
        $this->assertSame(0, $this->octoberInvoices($roto)->count());

        // Queda para reintento, con el motivo real.
        $log = BillingActionLog::where('customer_id', $roto->id)->sole();
        $this->assertSame(BillingActionLog::ACTION_GENERATE_MONTHLY, $log->action);
        $this->assertSame(BillingActionLog::STATUS_FAILED, $log->status);
        $this->assertSame('2026-10-01', Carbon::parse($log->period_start)->toDateString());
        $this->assertStringContainsString('primera factura rota', $log->last_error);
        $this->assertNotNull($log->next_retry_at);

        $this->assertSame([$roto->id], array_column($service->lastRunFailures(), 'customer_id'));
    }

    #[Test]
    public function a_router_that_fails_does_not_stop_the_routers_after_it(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);

        // Orden por id: el router roto va PRIMERO, como un router de otro
        // tenant que se procesara antes que el 57 y el 58.
        $roto  = $this->makeRouter($tenant);
        $sano  = $this->makeRouter($tenant);

        $clienteRoto = $this->makeCustomer($tenant, $roto, $plan);
        $clienteSano = $this->makeCustomer($tenant, $sano, $plan);

        $service = $this->failing();
        $service->failDueDateForConfig = [$roto->billing_router_id];

        $created = $service->generateMonthlyInvoices();

        $this->assertSame(1, $created);
        $this->assertSame(1, $this->octoberInvoices($clienteSano)->count());
        $this->assertSame(0, $this->octoberInvoices($clienteRoto)->count());

        $failures = $service->lastRunFailures();
        $this->assertCount(1, $failures);
        $this->assertSame($roto->id, $failures[0]['router_id']);
        $this->assertNull($failures[0]['customer_id']);
    }

    #[Test]
    public function the_next_run_recovers_the_customer_once_the_cause_is_gone_and_marks_the_log_successful(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $router = $this->makeRouter($tenant);
        $user   = $this->makeCustomer($tenant, $router, $plan);

        $service = $this->failing();
        $service->failFirstInvoiceFor = [$user->id];
        $service->generateMonthlyInvoices();

        $service->failFirstInvoiceFor = [];
        Carbon::setTestNow(Carbon::create(2026, 10, 1, 10, 0, 0));
        $this->assertSame(1, $service->generateMonthlyInvoices());
        $this->assertSame([], $service->lastRunFailures());

        $invoice = $this->octoberInvoices($user)->sole();
        $log     = BillingActionLog::where('customer_id', $user->id)->sole();
        $this->assertSame(BillingActionLog::STATUS_SUCCESS, $log->status);
        $this->assertSame($invoice->id, $log->invoice_id);
        $this->assertNull($log->last_error);
    }

    #[Test]
    public function an_additional_only_failure_is_recorded_under_its_own_action_and_retry_failed_leaves_it_alone(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $router = $this->makeRouter($tenant);

        $sinPlan = $this->makeCustomer($tenant, $router, null);
        $conPlan = $this->makeCustomer($tenant, $router, $plan);

        $service = $this->failing();
        $service->failAdditionalOnlyFor = [$sinPlan->id];

        $this->assertSame(1, $service->generateMonthlyInvoices());
        $this->assertSame(1, $this->octoberInvoices($conPlan)->count());

        $log = BillingActionLog::where('customer_id', $sinPlan->id)->sole();
        $this->assertSame(BillingActionLog::ACTION_GENERATE_ADDITIONAL_ONLY, $log->action);
        $this->assertSame(BillingActionLog::STATUS_FAILED, $log->status);

        // billing:retry-failed sólo reintenta mensualidades: sin este filtro la
        // agotaba con "No active billable service plan" y se perdía el motivo.
        $log->update(['next_retry_at' => null]);
        $this->artisan('billing:retry-failed')->assertExitCode(0);

        $log->refresh();
        $this->assertSame(BillingActionLog::STATUS_FAILED, $log->status);
        $this->assertSame(1, $log->attempts);
        $this->assertStringContainsString('adicionales rotos', $log->last_error);
    }

    #[Test]
    public function the_command_exits_with_failure_and_logs_when_the_run_had_errors(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $router = $this->makeRouter($tenant);
        $roto   = $this->makeCustomer($tenant, $router, $plan);
        $this->makeCustomer($tenant, $router, $plan);

        $service = $this->failing();
        $service->failFirstInvoiceFor = [$roto->id];

        Log::spy();

        $this->artisan('billing:generate-monthly')->assertExitCode(1);

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, "Failed to process customer {$roto->id}"))
            ->once();
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_starts_with($message, '[BILLING-RUN]'))
            ->once();
    }

    #[Test]
    public function the_command_logs_an_unexpected_abort_instead_of_only_printing_it(): void
    {
        $service = $this->failing();
        $service->abortRun = true;

        Log::spy();

        $this->artisan('billing:generate-monthly')->assertExitCode(1);

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'Failed to generate invoices: corrida abortada'))
            ->once();
    }

    #[Test]
    public function retry_failed_keeps_going_when_one_retry_throws_outside_its_own_try(): void
    {
        $tenant = Tenant::factory()->create();
        $plan   = $this->makePlan($tenant);
        $router = $this->makeRouter($tenant);
        $roto   = $this->makeCustomer($tenant, $router, $plan);
        $sano   = $this->makeCustomer($tenant, $router, $plan);

        foreach ([$roto, $sano] as $user) {
            BillingActionLog::create([
                'tenant_id'     => $tenant->id,
                'router_id'     => $router->id,
                'customer_id'   => $user->id,
                'action'        => BillingActionLog::ACTION_GENERATE_MONTHLY,
                'period_start'  => '2026-10-01',
                'period_end'    => '2026-10-31',
                'status'        => BillingActionLog::STATUS_FAILED,
                'attempts'      => 1,
                'last_error'    => 'fallo original',
                'next_retry_at' => null,
            ]);
        }

        $service = $this->failing();
        $service->failFirstInvoiceFor = [$roto->id];

        $this->artisan('billing:retry-failed')->assertExitCode(0);

        $this->assertSame(1, $this->octoberInvoices($sano)->count());
        $this->assertSame(
            BillingActionLog::STATUS_SUCCESS,
            BillingActionLog::where('customer_id', $sano->id)->value('status')
        );

        $logRoto = BillingActionLog::where('customer_id', $roto->id)->sole();
        $this->assertSame(BillingActionLog::STATUS_FAILED, $logRoto->status);
        $this->assertSame(2, $logRoto->attempts);
        $this->assertStringContainsString('primera factura rota', $logRoto->last_error);
    }
}

/**
 * BillingService que lanza a pedido en los puntos que la corrida mensual tenía
 * fuera de su try/catch.
 */
class FailingBillingService extends BillingService
{
    /** @var int[] */
    public array $failFirstInvoiceFor = [];

    /** @var int[] ids de `billing` */
    public array $failDueDateForConfig = [];

    /** @var int[] */
    public array $failAdditionalOnlyFor = [];

    public bool $abortRun = false;

    public function generateMonthlyInvoices(?string $period = null, ?int $routerId = null): int
    {
        if ($this->abortRun) {
            throw new \RuntimeException('corrida abortada');
        }

        return parent::generateMonthlyInvoices($period, $routerId);
    }

    protected function resolveFirstInvoiceCharge(
        CustomerProfile $profile,
        ?UserService $userService,
        Billing $billingConfig,
        Plan $servicePlan,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): ?array {
        if (in_array((int) $profile->user_id, $this->failFirstInvoiceFor, true)) {
            throw new \RuntimeException('primera factura rota');
        }

        return parent::resolveFirstInvoiceCharge($profile, $userService, $billingConfig, $servicePlan, $periodStart, $periodEnd);
    }

    protected function resolveDueDate(Billing $billingConfig, Carbon $issueDate): Carbon
    {
        if (in_array((int) $billingConfig->id, $this->failDueDateForConfig, true)) {
            throw new \RuntimeException('configuración de router rota');
        }

        return parent::resolveDueDate($billingConfig, $issueDate);
    }

    protected function issueAdditionalOnlyInvoice(
        Router $router,
        CustomerProfile $profile,
        Billing $billingConfig,
        Carbon $issueDate,
        Carbon $dueDate,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?int $stopAt,
    ): ?Invoice {
        if (in_array((int) $profile->user_id, $this->failAdditionalOnlyFor, true)) {
            throw new \RuntimeException('adicionales rotos');
        }

        return parent::issueAdditionalOnlyInvoice($router, $profile, $billingConfig, $issueDate, $dueDate, $periodStart, $periodEnd, $stopAt);
    }
}
