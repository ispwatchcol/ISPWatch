<?php

namespace Tests\Feature\Billing;

use App\Constants\Permissions;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Router;
use App\Models\SuspensionActionLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingService;
use App\Services\RouterProvisioningService;
use App\Support\ReconnectionOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pago confirmado ≠ reconexión confirmada.
 *
 * El pago es una operación financiera y se aplica siempre; la reconexión
 * depende de un equipo que puede no existir, no estar asignado, no tener
 * credenciales o no responder. Antes esos cuatro casos salían por el mismo
 * sitio que el éxito —con `router_ok` en su valor por defecto `true`— y el
 * cajero leía "cliente reactivado" sobre un servicio que nadie había tocado.
 *
 * Aquí se fija que cada condición tenga su motivo propio, que ninguna de ellas
 * cante éxito, y que el pago entre igual en todas.
 */
class PaymentReconnectionWarningTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // `CheckPermission` deja pasar SIEMPRE a `role_id == 1`. Con
        // `RefreshDatabase` el primer rol creado se lleva ese id, así que se
        // quema uno: sin esto el caso negativo de permisos sería un falso
        // positivo (pasaría por el bypass, no por el permiso).
        Role::create([
            'name'        => 'Superadmin global',
            'code'        => 'superadmin',
            'permissions' => ['*'],
            'tenant_id'   => null,
        ]);
    }

    protected function tearDown(): void
    {
        // `Mockery::close()` LANZA cuando una expectativa no se cumplió. Si se
        // le deja cortar el tearDown, `RefreshDatabase` no alcanza a revertir
        // su transacción y el test SIGUIENTE muere con "there is already an
        // active transaction" — un fallo real queda enterrado bajo una cascada
        // de errores que no dicen nada.
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    // ── Andamiaje ────────────────────────────────────────────────────

    private function router(Tenant $tenant, array $overrides = []): Router
    {
        return Router::create(array_merge([
            'name'        => 'Router ' . uniqid(),
            'tenant_id'   => $tenant->id,
            'status'      => 'active',
            'ip'          => '10.10.0.1',
            'user_rb'     => 'admin',
            'password_rb' => 'secreto-del-router',
        ], $overrides));
    }

    /** Cliente cortado por mora, con una factura vencida por pagar. */
    private function cutCustomer(Tenant $tenant, array $profileOverrides = []): User
    {
        $this->seq++;
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        CustomerProfile::create(array_merge([
            'user_id'        => $user->id,
            'tenant_id'      => $tenant->id,
            'name'           => "Cliente{$this->seq}",
            'last_name'      => "Apellido{$this->seq}",
            'ip_user'        => '10.0.0.' . $this->seq,
            'status'         => false,
            'service_status' => 'suspendido',
        ], $profileOverrides));

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
            'balance_due'  => 25000,
            'status'       => 'overdue',
        ]);

        return $user;
    }

    private function pay(Tenant $tenant, User $user, float $amount = 25000): \App\Models\Payment
    {
        return app(BillingService::class)->registerPayment([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $user->id,
            'amount'       => $amount,
            'payment_date' => now()->toDateString(),
            'method'       => 'cash',
        ]);
    }

    private function mockProvisioning(): \Mockery\MockInterface
    {
        $mock = Mockery::mock(RouterProvisioningService::class);
        $this->app->instance(RouterProvisioningService::class, $mock);
        return $mock;
    }

    /** El pago entró y la factura quedó saldada: se verifica en TODOS los casos. */
    private function assertPaymentApplied(User $user): void
    {
        $invoice = Invoice::where('customer_id', $user->id)->first();
        $this->assertSame(0.0, (float) $invoice->balance_due, 'La factura debió quedar sin saldo.');
        $this->assertSame('paid', $invoice->status, 'La factura debió quedar pagada.');
    }

    /** @param string|array<int,string> $permissions */
    private function userWithPermission(Tenant $tenant, string|array $permissions): User
    {
        $role = Role::create([
            'name'        => 'Rol ' . uniqid(),
            'tenant_id'   => $tenant->id,
            'permissions' => (array) $permissions,
        ]);

        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id'   => $role->id,
        ]);
    }

    /**
     * El cajero de mostrador: cobra (registrar un pago pasa por `view_billing`,
     * que es como está enrutado hoy el módulo) pero NO opera RouterBoards.
     */
    private function cashier(Tenant $tenant): User
    {
        return $this->userWithPermission($tenant, [
            Permissions::VIEW_BILLING,
            Permissions::REGISTER_PAYMENTS,
        ]);
    }

    // ── 1. Camino feliz ──────────────────────────────────────────────

    #[Test]
    public function payment_with_a_working_router_applies_and_reactivates(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant);
        $user   = $this->cutCustomer($tenant, ['router_id' => $router->id]);

        $this->mockProvisioning()
            ->shouldReceive('unsuspendCustomer')
            ->once()
            ->andReturn(true);

        $payment = $this->pay($tenant, $user);

        $this->assertPaymentApplied($user);
        $this->assertSame(ReconnectionOutcome::REACTIVADO_AUTOMATICAMENTE, $payment->reactivation['outcome']);
        $this->assertTrue($payment->reactivation['router_ok']);
        $this->assertFalse($payment->reactivation['pending']);
        $this->assertTrue((bool) CustomerProfile::where('user_id', $user->id)->first()->status);
    }

    // ── 2. Sin router configurado ────────────────────────────────────

    /**
     * La ficha apunta a un equipo que NO existe como router configurado de esta
     * sede. Se construye con un router de otro tenant —la deriva típica de una
     * importación o de un traslado de cliente entre sedes— porque borrar el
     * router no sirve para reproducirlo: la FK de customer_profile.router_id es
     * `onDelete('set null')` y ese camino desemboca en "no asignado", que es
     * otro caso distinto (y está cubierto aparte).
     *
     * Doble valor: fija también que NUNCA se opera un equipo de otra sede.
     */
    #[Test]
    public function payment_with_an_unconfigured_router_warns_and_does_not_claim_reconnection(): void
    {
        $tenant = Tenant::factory()->create();
        $otraSede = Tenant::factory()->create();
        $router = $this->router($otraSede);
        $user   = $this->cutCustomer($tenant, ['router_id' => $router->id]);

        $this->mockProvisioning()->shouldNotReceive('unsuspendCustomer');

        $payment = $this->pay($tenant, $user);

        $this->assertPaymentApplied($user);
        $this->assertSame(
            ReconnectionOutcome::PENDIENTE_SIN_ROUTER_CONFIGURADO,
            $payment->reactivation['outcome']
        );
        $this->assertFalse($payment->reactivation['router_ok'], 'No se puede afirmar que el equipo reconectó.');
        $this->assertTrue($payment->reactivation['pending']);
        $this->assertNotSame('', $payment->reactivation['action'], 'La alerta debe decir qué hacer.');
    }

    // ── 3. Router no asignado al cliente ─────────────────────────────

    #[Test]
    public function payment_with_no_router_assigned_warns_instead_of_reporting_success(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->cutCustomer($tenant, ['router_id' => null]);

        $this->mockProvisioning()->shouldNotReceive('unsuspendCustomer');

        $payment = $this->pay($tenant, $user);

        $this->assertPaymentApplied($user);
        $this->assertSame(
            ReconnectionOutcome::PENDIENTE_ROUTER_NO_ASIGNADO,
            $payment->reactivation['outcome']
        );
        $this->assertFalse($payment->reactivation['router_ok']);
        $this->assertTrue($payment->reactivation['pending']);

        // Este es EL caso de regresión: antes salía router_ok=true y la pantalla
        // pintaba el aviso verde de "cliente reactivado".
        $this->assertStringContainsString('no tiene un router asignado', $payment->reactivation['message']);
    }

    // ── 4. Configuración incompleta ──────────────────────────────────

    #[Test]
    public function payment_with_missing_router_credentials_warns(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant, ['user_rb' => null, 'password_rb' => null]);
        $user   = $this->cutCustomer($tenant, ['router_id' => $router->id]);

        $this->mockProvisioning()->shouldNotReceive('unsuspendCustomer');

        $payment = $this->pay($tenant, $user);

        $this->assertPaymentApplied($user);
        $this->assertSame(
            ReconnectionOutcome::PENDIENTE_CONFIGURACION_INCOMPLETA,
            $payment->reactivation['outcome']
        );
        $this->assertTrue($payment->reactivation['pending']);
    }

    #[Test]
    public function payment_for_a_customer_without_ip_warns_as_incomplete_configuration(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant);
        $user   = $this->cutCustomer($tenant, ['router_id' => $router->id, 'ip_user' => null]);

        $this->mockProvisioning()->shouldNotReceive('unsuspendCustomer');

        $payment = $this->pay($tenant, $user);

        $this->assertPaymentApplied($user);
        $this->assertSame(
            ReconnectionOutcome::PENDIENTE_CONFIGURACION_INCOMPLETA,
            $payment->reactivation['outcome']
        );
    }

    // ── 5. Router no disponible / error de comunicación ──────────────

    #[Test]
    public function payment_with_an_inactive_router_warns_as_unavailable(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant, ['status' => 'inactive']);
        $user   = $this->cutCustomer($tenant, ['router_id' => $router->id]);

        $this->mockProvisioning()->shouldNotReceive('unsuspendCustomer');

        $payment = $this->pay($tenant, $user);

        $this->assertPaymentApplied($user);
        $this->assertSame(
            ReconnectionOutcome::PENDIENTE_ROUTER_NO_DISPONIBLE,
            $payment->reactivation['outcome']
        );
    }

    #[Test]
    public function payment_with_a_router_in_general_failure_warns_as_unavailable(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant, ['falla_general' => true]);
        $user   = $this->cutCustomer($tenant, ['router_id' => $router->id]);

        $this->mockProvisioning()->shouldNotReceive('unsuspendCustomer');

        $payment = $this->pay($tenant, $user);

        $this->assertPaymentApplied($user);
        $this->assertSame(
            ReconnectionOutcome::PENDIENTE_ROUTER_NO_DISPONIBLE,
            $payment->reactivation['outcome']
        );
    }

    #[Test]
    public function payment_with_a_mikrotik_error_warns_and_keeps_the_payment(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant);
        $user   = $this->cutCustomer($tenant, ['router_id' => $router->id]);

        $this->mockProvisioning()
            ->shouldReceive('unsuspendCustomer')
            ->once()
            ->andReturn(false);

        $payment = $this->pay($tenant, $user);

        $this->assertPaymentApplied($user);
        $this->assertSame(
            ReconnectionOutcome::PENDIENTE_ERROR_MIKROTIK,
            $payment->reactivation['outcome']
        );
        $this->assertFalse($payment->reactivation['router_ok']);
        $this->assertTrue($payment->reactivation['pending']);
    }

    // ── 6. Cliente que NO estaba suspendido ──────────────────────────

    #[Test]
    public function paying_a_customer_that_is_not_suspended_neither_reconnects_nor_warns(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant);
        $this->seq++;
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        CustomerProfile::create([
            'user_id'        => $user->id,
            'tenant_id'      => $tenant->id,
            'name'           => 'Al',
            'last_name'      => 'Día',
            'router_id'      => $router->id,
            'ip_user'        => '10.0.9.1',
            'status'         => true,
            'service_status' => 'activo',
        ]);

        Invoice::create([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $user->id,
            'number'       => uniqid('INV-'),
            'issue_date'   => now()->subDays(5),
            'due_date'     => now()->addDays(5),
            'period_start' => now()->startOfMonth(),
            'period_end'   => now()->endOfMonth(),
            'subtotal'     => 25000,
            'total'        => 25000,
            'balance_due'  => 25000,
            'status'       => 'issued',
        ]);

        $this->mockProvisioning()->shouldNotReceive('unsuspendCustomer');

        $payment = $this->pay($tenant, $user);

        $this->assertFalse($payment->reactivation['was_suspended']);
        $this->assertFalse($payment->reactivation['pending'], 'No debe alertar de algo que no pasó.');
        $this->assertSame(ReconnectionOutcome::NO_APLICA, $payment->reactivation['outcome']);
        $this->assertSame('', $payment->reactivation['message']);
    }

    // ── 7. Idempotencia ──────────────────────────────────────────────

    #[Test]
    public function a_second_payment_after_a_successful_reconnection_is_idempotent(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant);
        $user   = $this->cutCustomer($tenant, ['router_id' => $router->id]);

        // Primer pago: reconecta de verdad (una sola llamada al equipo).
        $this->mockProvisioning()->shouldReceive('unsuspendCustomer')->once()->andReturn(true);
        $this->pay($tenant, $user);

        // El equipo ya quedó levantado; ese es el estado que deja el intento.
        SuspensionActionLog::create([
            'router_id'   => $router->id,
            'customer_id' => $user->id,
            'ip'          => '10.0.0.1',
            'action'      => SuspensionActionLog::ACTION_UNSUSPEND,
            'reason'      => SuspensionActionLog::REASON_AUTO_RECONNECT,
            'outcome'     => ReconnectionOutcome::REACTIVADO_AUTOMATICAMENTE,
            'status'      => SuspensionActionLog::STATUS_SUCCESS,
            'attempts'    => 1,
        ]);

        // Segundo pago sobre el mismo cliente: NO debe volver a tocar el router.
        $this->mockProvisioning()->shouldNotReceive('unsuspendCustomer');
        $second = $this->pay($tenant, $user, 10000);

        $this->assertSame(ReconnectionOutcome::YA_REACTIVADO, $second->reactivation['outcome']);
        $this->assertFalse($second->reactivation['pending']);
    }

    // ── 8. Reintento autorizado ──────────────────────────────────────

    #[Test]
    public function an_authorized_user_can_retry_a_pending_reconnection(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant);
        $user   = $this->cutCustomer($tenant, ['router_id' => $router->id]);

        $this->mockProvisioning()->shouldReceive('unsuspendCustomer')->once()->andReturn(false);
        $payment = $this->pay($tenant, $user);
        $this->assertTrue($payment->reactivation['pending']);

        // Ahora el equipo responde: el reintento tiene que cerrar el caso.
        $this->mockProvisioning()->shouldReceive('unsuspendCustomer')->once()->andReturn(true);

        Sanctum::actingAs($this->userWithPermission($tenant, Permissions::EXECUTE_MASS_ACTIONS));

        $this->postJson("/api/billing/customers/{$user->id}/retry-reconnection")
            ->assertOk()
            ->assertJsonPath('outcome', ReconnectionOutcome::REACTIVADO_AUTOMATICAMENTE)
            ->assertJsonPath('reconnected', true)
            ->assertJsonPath('pending', false);

        // Y la alerta persistente desaparece de la ficha.
        $this->assertNull(app(BillingService::class)->pendingReconnectionFor($user->id));
    }

    /**
     * La alerta tiene que APAGARSE cuando el problema se resuelve. Se fija
     * aparte porque ya se escapó una vez: el reintento marcaba la fila como
     * `success` pero le dejaba el motivo pendiente del intento anterior, y el
     * aviso rojo seguía en la ficha de un cliente ya reconectado.
     */
    #[Test]
    public function a_successful_retry_clears_the_pending_outcome_on_the_log(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant);
        $user   = $this->cutCustomer($tenant, ['router_id' => $router->id]);

        $this->mockProvisioning()->shouldReceive('unsuspendCustomer')->once()->andReturn(false);
        $this->pay($tenant, $user);

        $fallido = SuspensionActionLog::pendingReconnectionFor($user->id);
        $this->assertNotNull($fallido);
        $this->assertSame(ReconnectionOutcome::PENDIENTE_ERROR_MIKROTIK, $fallido->outcome);

        $this->mockProvisioning()->shouldReceive('unsuspendCustomer')->once()->andReturn(true);
        Sanctum::actingAs($this->userWithPermission($tenant, Permissions::EXECUTE_MASS_ACTIONS));
        $this->postJson("/api/billing/customers/{$user->id}/retry-reconnection")->assertOk();

        $this->assertSame(
            ReconnectionOutcome::REACTIVADO_AUTOMATICAMENTE,
            SuspensionActionLog::where('customer_id', $user->id)
                ->where('action', SuspensionActionLog::ACTION_UNSUSPEND)
                ->latest('id')->first()->outcome
        );
        $this->assertNull(SuspensionActionLog::pendingReconnectionFor($user->id));
    }

    /**
     * Pagar dos veces con el mismo problema no debe apilar filas: la bitácora
     * tiene que dejar ver UN caso abierto, no un caso por recibo.
     */
    #[Test]
    public function repeated_payments_with_the_same_problem_do_not_pile_up_log_rows(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->cutCustomer($tenant, ['router_id' => null]);

        $this->mockProvisioning()->shouldNotReceive('unsuspendCustomer');

        $this->pay($tenant, $user, 25000);
        $this->pay($tenant, $user, 5000);

        $this->assertSame(
            1,
            SuspensionActionLog::where('customer_id', $user->id)
                ->where('action', SuspensionActionLog::ACTION_UNSUSPEND)
                ->count()
        );
    }

    // ── 9. Sin permiso ───────────────────────────────────────────────

    #[Test]
    public function a_user_without_permission_cannot_retry_a_reconnection(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant);
        $user   = $this->cutCustomer($tenant, ['router_id' => $router->id]);

        // `register_payments` autoriza a cobrar, no a operar el RouterBoard.
        Sanctum::actingAs($this->cashier($tenant));

        $this->postJson("/api/billing/customers/{$user->id}/retry-reconnection")
            ->assertForbidden();
    }

    #[Test]
    public function the_payment_response_only_offers_retry_to_authorized_roles(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant);
        $user   = $this->cutCustomer($tenant, ['router_id' => $router->id]);

        $this->mockProvisioning()->shouldReceive('unsuspendCustomer')->andReturn(false);

        // Cajero: ve la alerta, no el botón.
        Sanctum::actingAs($this->cashier($tenant));
        $this->postJson('/api/billing/payments', [
            'tenant_id'    => $tenant->id,
            'customer_id'  => $user->id,
            'amount'       => 25000,
            'payment_date' => now()->toDateString(),
            'method'       => 'cash',
        ])
            ->assertCreated()
            ->assertJsonPath('reactivation.pending', true)
            ->assertJsonPath('reactivation.can_retry', false);
    }

    // ── 10. Aislamiento por tenant ───────────────────────────────────

    #[Test]
    public function a_user_cannot_retry_a_reconnection_for_another_tenant_customer(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $routerB = $this->router($tenantB);
        $ajeno   = $this->cutCustomer($tenantB, ['router_id' => $routerB->id]);

        $this->mockProvisioning()->shouldNotReceive('unsuspendCustomer');

        // Operador de la sede A, con el permiso correcto, contra un cliente de B.
        Sanctum::actingAs($this->userWithPermission($tenantA, Permissions::EXECUTE_MASS_ACTIONS));

        $this->postJson("/api/billing/customers/{$ajeno->id}/retry-reconnection")
            ->assertNotFound();
    }

    // ── Auditoría ────────────────────────────────────────────────────

    /**
     * Pago y desenlace en la MISMA entrada: la pregunta que hay que poder
     * responder meses después es «este cliente pagó el día tal, ¿se le
     * restableció el servicio, y si no, por qué», no las dos por separado.
     */
    #[Test]
    public function the_payment_and_its_reconnection_outcome_are_audited_together(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->cutCustomer($tenant, ['router_id' => null]);

        $this->mockProvisioning()->shouldNotReceive('unsuspendCustomer');
        $this->pay($tenant, $user);

        $entrada = \App\Models\AuditLog::withoutGlobalScope('tenant')
            ->where('action', 'payment.reconnection')
            ->latest('id')
            ->first();

        $this->assertNotNull($entrada, 'El par pago ⇄ reconexión debe quedar auditado.');
        $this->assertSame($tenant->id, $entrada->tenant_id);
        $this->assertSame(ReconnectionOutcome::PENDIENTE_ROUTER_NO_ASIGNADO, $entrada->new_values['outcome']);
        $this->assertFalse($entrada->new_values['router_ok']);
        $this->assertTrue($entrada->new_values['db_reactivated']);
        $this->assertSame($user->id, $entrada->new_values['customer_id']);
    }

    /** Un cliente que no estaba cortado no ensucia la bitácora con ruido. */
    #[Test]
    public function a_payment_without_any_cut_writes_no_reconnection_audit_entry(): void
    {
        $tenant = Tenant::factory()->create();
        $this->seq++;
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        CustomerProfile::create([
            'user_id'        => $user->id,
            'tenant_id'      => $tenant->id,
            'name'           => 'Al',
            'last_name'      => 'Día',
            'status'         => true,
            'service_status' => 'activo',
        ]);

        $this->pay($tenant, $user, 10000);

        $this->assertSame(
            0,
            \App\Models\AuditLog::withoutGlobalScope('tenant')
                ->where('action', 'payment.reconnection')
                ->count()
        );
    }

    // ── 11. Sin secretos en la respuesta ─────────────────────────────

    #[Test]
    public function the_warning_never_leaks_router_secrets_or_raw_errors(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant, [
            'ip'          => '192.168.88.77',
            'user_rb'     => 'admin-secreto',
            'password_rb' => 'clave-super-secreta',
        ]);
        $user = $this->cutCustomer($tenant, ['router_id' => $router->id]);

        $this->mockProvisioning()->shouldReceive('unsuspendCustomer')->andReturn(false);

        Sanctum::actingAs($this->cashier($tenant));

        $response = $this->postJson('/api/billing/payments', [
            'tenant_id'    => $tenant->id,
            'customer_id'  => $user->id,
            'amount'       => 25000,
            'payment_date' => now()->toDateString(),
            'method'       => 'cash',
        ])->assertCreated();

        $reactivation = json_encode($response->json('reactivation'), JSON_UNESCAPED_UNICODE);

        foreach (['clave-super-secreta', 'admin-secreto', '192.168.88.77', 'password_rb', 'user_rb'] as $secreto) {
            $this->assertStringNotContainsString(
                $secreto,
                $reactivation,
                "El aviso de reconexión no puede exponer «{$secreto}»."
            );
        }
    }

    // ── Alerta persistente en la ficha ───────────────────────────────

    #[Test]
    public function the_pending_warning_survives_on_the_customer_record(): void
    {
        $tenant = Tenant::factory()->create();
        $user   = $this->cutCustomer($tenant, ['router_id' => null]);

        $this->mockProvisioning()->shouldNotReceive('unsuspendCustomer');
        $this->pay($tenant, $user);

        // Otra petición, más tarde: el problema sigue ahí y la ficha lo dice.
        $pendiente = app(BillingService::class)->pendingReconnectionFor($user->id);

        $this->assertNotNull($pendiente, 'La alerta debe seguir visible mientras el problema siga pendiente.');
        $this->assertSame(ReconnectionOutcome::PENDIENTE_ROUTER_NO_ASIGNADO, $pendiente['outcome']);
        $this->assertTrue($pendiente['pending']);

        // Y también viaja en el estado que consume la pantalla de cobro.
        $status = app(BillingService::class)->suspensionStatusFor($user->id);
        $this->assertSame(ReconnectionOutcome::PENDIENTE_ROUTER_NO_ASIGNADO, $status['reconnection']['outcome']);
    }

    #[Test]
    public function a_customer_without_problems_shows_no_pending_warning(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant);
        $user   = $this->cutCustomer($tenant, ['router_id' => $router->id]);

        $this->mockProvisioning()->shouldReceive('unsuspendCustomer')->once()->andReturn(true);
        $this->pay($tenant, $user);

        $this->assertNull(app(BillingService::class)->pendingReconnectionFor($user->id));
    }
}
