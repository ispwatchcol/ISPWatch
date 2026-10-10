<?php

namespace Tests\Feature\Billing;

use App\Models\CustomerProfile;
use App\Models\Router;
use App\Models\SuspensionActionLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OverdueSuspensionService;
use App\Support\ReconnectionOutcome;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P-29 / KAN-53: billing:reconcile-reconnections, el espejo de
 * billing:reconcile-suspensions. Reabre en la RB a quien la BD da por activo y
 * cuya última reconexión no confirmó el equipo.
 */
class ReconcileReconnectionsTest extends TestCase
{
    use RefreshDatabase;

    // ────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────

    protected function makeRouter(Tenant $tenant, array $overrides = []): Router
    {
        return Router::create(array_merge([
            'name'        => 'Router ' . uniqid(),
            'tenant_id'   => $tenant->id,
            'status'      => 'active',
            'ip'          => '172.16.16.' . random_int(2, 250),
            'user_rb'     => 'ispwatch',
            'password_rb' => 'secreto',
        ], $overrides));
    }

    /** Cliente que ya pagó: activo en la BD (status=true) con router + IP. */
    protected function makeActiveCustomer(Tenant $tenant, Router $router, array $overrides = []): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        CustomerProfile::updateOrCreate(
            ['user_id' => $user->id],
            array_merge([
                'tenant_id'      => $tenant->id,
                'name'           => 'Test',
                'last_name'      => 'Pagado',
                'router_id'      => $router->id,
                'ip_user'        => '10.0.0.' . random_int(2, 250),
                'status'         => true,
                'service_status' => 'activo',
            ], $overrides)
        );

        return $user;
    }

    protected function failedUnsuspend(User $customer, Router $router, array $overrides = []): SuspensionActionLog
    {
        return SuspensionActionLog::create(array_merge([
            'router_id'     => $router->id,
            'customer_id'   => $customer->id,
            'ip'            => '10.0.0.5',
            'action'        => SuspensionActionLog::ACTION_UNSUSPEND,
            'reason'        => SuspensionActionLog::REASON_AUTO_RECONNECT,
            'outcome'       => ReconnectionOutcome::PENDIENTE_ERROR_MIKROTIK,
            'status'        => SuspensionActionLog::STATUS_FAILED,
            'attempts'      => 1,
            'next_retry_at' => now()->subMinute(),
        ], $overrides));
    }

    protected function mockProvisioning(): \Mockery\MockInterface
    {
        $mock = Mockery::mock(\App\Services\RouterProvisioningService::class);
        $this->app->instance(\App\Services\RouterProvisioningService::class, $mock);
        return $mock;
    }

    protected function reconcile(...$args): array
    {
        return app(OverdueSuspensionService::class)->reconcileReconnections(...$args);
    }

    // ────────────────────────────────────────────────────────────
    // El caso que motivó la tarjeta
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function reintenta_la_reconexion_fallida_de_un_cliente_que_ya_pago(): void
    {
        $tenant   = Tenant::factory()->create();
        $router   = $this->makeRouter($tenant);
        $customer = $this->makeActiveCustomer($tenant, $router);
        $log      = $this->failedUnsuspend($customer, $router);

        $this->mockProvisioning()->shouldReceive('unsuspendCustomer')
            ->once()
            ->with($customer->id, $router->id, Mockery::type('array'))
            ->andReturn(true);

        $stats = $this->reconcile();

        $this->assertSame(1, $stats['scanned']);
        $this->assertSame(1, $stats['reconnected_ok']);
        $this->assertSame(0, $stats['reconnect_failed']);

        // El desenlace se estampa en la misma fila: la alerta de la ficha se
        // apaga sola cuando el equipo por fin confirma.
        $this->assertSame(ReconnectionOutcome::REACTIVADO_AUTOMATICAMENTE, $log->fresh()->outcome);
    }

    #[Test]
    public function si_el_equipo_sigue_sin_responder_queda_pendiente(): void
    {
        $tenant   = Tenant::factory()->create();
        $router   = $this->makeRouter($tenant);
        $customer = $this->makeActiveCustomer($tenant, $router);
        $log      = $this->failedUnsuspend($customer, $router);

        $this->mockProvisioning()->shouldReceive('unsuspendCustomer')->once()->andReturn(false);

        $stats = $this->reconcile();

        $this->assertSame(0, $stats['reconnected_ok']);
        $this->assertSame(1, $stats['reconnect_failed']);
        $this->assertSame(ReconnectionOutcome::PENDIENTE_ERROR_MIKROTIK, $log->fresh()->outcome);
        $this->assertNotNull(SuspensionActionLog::pendingReconnectionFor($customer->id));
    }

    #[Test]
    public function reintenta_una_fila_pending_abandonada(): void
    {
        $tenant   = Tenant::factory()->create();
        $router   = $this->makeRouter($tenant);
        $customer = $this->makeActiveCustomer($tenant, $router);
        $log      = $this->failedUnsuspend($customer, $router, [
            'status'        => SuspensionActionLog::STATUS_PENDING,
            'next_retry_at' => null,
        ]);
        DB::table('suspension_action_logs')->where('id', $log->id)->update([
            'updated_at' => now()->subMinutes(OverdueSuspensionService::RECONNECT_PENDING_STALE_MINUTES + 5),
        ]);

        $this->mockProvisioning()->shouldReceive('unsuspendCustomer')->once()->andReturn(true);

        $this->assertSame(1, $this->reconcile()['reconnected_ok']);
    }

    // ────────────────────────────────────────────────────────────
    // Lo que NO debe reconectar
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function no_reconecta_si_despues_hubo_un_nuevo_corte(): void
    {
        // UNSUSPEND fallido y DESPUÉS un SUSPEND: el cliente volvió a quedar
        // cortado. Reabrirlo sería deshacer ese corte.
        $tenant   = Tenant::factory()->create();
        $router   = $this->makeRouter($tenant);
        $customer = $this->makeActiveCustomer($tenant, $router);
        $this->failedUnsuspend($customer, $router);
        SuspensionActionLog::create([
            'router_id' => $router->id, 'customer_id' => $customer->id, 'ip' => '10.0.0.5',
            'action' => SuspensionActionLog::ACTION_SUSPEND, 'status' => SuspensionActionLog::STATUS_SUCCESS, 'attempts' => 1,
        ]);

        $this->mockProvisioning()->shouldNotReceive('unsuspendCustomer');

        $stats = $this->reconcile();

        $this->assertSame(1, $stats['skipped_superseded']);
        $this->assertSame(0, $stats['reconnected_ok']);
    }

    #[Test]
    public function no_reconecta_si_una_reconexion_posterior_ya_salio_bien(): void
    {
        $tenant   = Tenant::factory()->create();
        $router   = $this->makeRouter($tenant);
        $customer = $this->makeActiveCustomer($tenant, $router);
        $this->failedUnsuspend($customer, $router);
        $this->failedUnsuspend($customer, $router, [
            'status' => SuspensionActionLog::STATUS_SUCCESS,
            'outcome' => ReconnectionOutcome::REACTIVADO_AUTOMATICAMENTE,
        ]);

        $this->mockProvisioning()->shouldNotReceive('unsuspendCustomer');

        $this->assertSame(1, $this->reconcile()['skipped_superseded']);
    }

    #[Test]
    public function no_toca_clientes_suspendidos_en_la_bd(): void
    {
        // status=false es terreno de reconcile-suspensions, no de éste.
        $tenant   = Tenant::factory()->create();
        $router   = $this->makeRouter($tenant);
        $customer = $this->makeActiveCustomer($tenant, $router, ['status' => false, 'service_status' => 'suspendido']);
        $this->failedUnsuspend($customer, $router);

        $this->mockProvisioning()->shouldNotReceive('unsuspendCustomer');

        $this->assertSame(0, $this->reconcile()['scanned']);
    }

    #[Test]
    public function no_reabre_una_ficha_que_se_contradice(): void
    {
        $tenant   = Tenant::factory()->create();
        $router   = $this->makeRouter($tenant);
        $retirado = $this->makeActiveCustomer($tenant, $router, ['service_status' => 'retirado']);
        $suspendido = $this->makeActiveCustomer($tenant, $router, ['service_status' => 'suspendido']);
        $this->failedUnsuspend($retirado, $router);
        $this->failedUnsuspend($suspendido, $router);

        $this->mockProvisioning()->shouldNotReceive('unsuspendCustomer');

        $this->assertSame(2, $this->reconcile()['skipped_not_active']);
    }

    #[Test]
    public function respeta_el_backoff_y_los_agotados_salvo_con_force(): void
    {
        $tenant  = Tenant::factory()->create();
        $router  = $this->makeRouter($tenant);
        $enEspera = $this->makeActiveCustomer($tenant, $router);
        $agotado  = $this->makeActiveCustomer($tenant, $router);
        $this->failedUnsuspend($enEspera, $router, ['next_retry_at' => now()->addHour()]);
        $this->failedUnsuspend($agotado, $router, [
            'attempts'      => SuspensionActionLog::MAX_ATTEMPTS,
            'next_retry_at' => null,
        ]);

        $mock = $this->mockProvisioning();
        $mock->shouldNotReceive('unsuspendCustomer');

        $stats = $this->reconcile();
        $this->assertSame(1, $stats['skipped_backoff']);
        $this->assertSame(1, $stats['skipped_exhausted']);

        $this->mockProvisioning()->shouldReceive('unsuspendCustomer')->twice()->andReturn(true);

        $forced = $this->reconcile(force: true);
        $this->assertSame(2, $forced['reconnected_ok']);
    }

    #[Test]
    public function nunca_pisa_un_intento_en_curso_ni_con_force(): void
    {
        $tenant   = Tenant::factory()->create();
        $router   = $this->makeRouter($tenant);
        $customer = $this->makeActiveCustomer($tenant, $router);
        $this->failedUnsuspend($customer, $router, [
            'status'        => SuspensionActionLog::STATUS_PENDING,
            'next_retry_at' => null,
        ]);

        $this->mockProvisioning()->shouldNotReceive('unsuspendCustomer');

        $this->assertSame(1, $this->reconcile(force: true)['skipped_in_flight']);
    }

    #[Test]
    public function omite_routers_de_aaa_externo(): void
    {
        $tenant   = Tenant::factory()->create();
        $router   = $this->makeRouter($tenant, ['radius' => true]);
        $customer = $this->makeActiveCustomer($tenant, $router);
        $this->failedUnsuspend($customer, $router);

        $this->mockProvisioning()->shouldNotReceive('unsuspendCustomer');

        $stats = $this->reconcile();
        $this->assertSame(1, $stats['skipped_external']);
        $this->assertSame(0, $stats['reconnected_ok']);
    }

    #[Test]
    public function dry_run_no_toca_el_equipo(): void
    {
        $tenant   = Tenant::factory()->create();
        $router   = $this->makeRouter($tenant);
        $customer = $this->makeActiveCustomer($tenant, $router);
        $this->failedUnsuspend($customer, $router);

        $this->mockProvisioning()->shouldNotReceive('unsuspendCustomer');

        $this->assertSame(1, $this->reconcile(dryRun: true)['would_reconnect']);
    }

    #[Test]
    public function el_filtro_por_tenant_no_alcanza_clientes_de_otro_tenant(): void
    {
        $propio = Tenant::factory()->create();
        $ajeno  = Tenant::factory()->create();
        $routerPropio = $this->makeRouter($propio);
        $routerAjeno  = $this->makeRouter($ajeno);
        $cliente      = $this->makeActiveCustomer($propio, $routerPropio);
        $clienteAjeno = $this->makeActiveCustomer($ajeno, $routerAjeno);
        $this->failedUnsuspend($cliente, $routerPropio);
        $this->failedUnsuspend($clienteAjeno, $routerAjeno);

        $this->mockProvisioning()->shouldReceive('unsuspendCustomer')
            ->once()
            ->with($cliente->id, $routerPropio->id, Mockery::type('array'))
            ->andReturn(true);

        $stats = $this->reconcile(tenantId: $propio->id);

        $this->assertSame(1, $stats['scanned']);
        $this->assertSame(1, $stats['reconnected_ok']);
    }

    // ────────────────────────────────────────────────────────────
    // Comando y planificador
    // ────────────────────────────────────────────────────────────

    #[Test]
    public function el_comando_corre_y_esta_agendado_cada_hora(): void
    {
        $this->mockProvisioning()->shouldNotReceive('unsuspendCustomer');

        $this->artisan('billing:reconcile-reconnections', ['--dry-run' => true])->assertExitCode(0);

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'billing:reconcile-reconnections'));

        $this->assertNotNull($event, 'billing:reconcile-reconnections no está en el planificador');
        $this->assertSame('0 * * * *', $event->expression);
    }
}
