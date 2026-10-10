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
use App\Support\ReconnectionOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Registrar un pago no puede quedarse esperando a un router que no existe.
 *
 * EL CASO REAL. Un ISP con los routers dados de alta a medias —nombre y poco
 * más, sin VPN, sin RADIUS, sin credenciales— recaudaba en el mostrador y la
 * pantalla respondía «Request failed with status code 504». El pago SÍ se
 * guardaba: la transacción confirma antes de que empiece la parte del equipo.
 * Lo que se comía el tiempo era la reconexión automática, dos sesiones SSH
 * encadenadas contra una dirección que no contesta, hasta que el gateway
 * cortaba la petición. El cajero veía un error por algo que había funcionado y
 * volvía a cobrar: cobro doble.
 *
 * LO QUE ESTA SUITE FIJA
 *
 *  1. A un router sin datos de acceso no se le intenta nada. Ni una sesión.
 *  2. El desenlace del EQUIPO se cuenta aparte del pago y nunca como éxito:
 *     queda `pendiente_configuracion_incompleta`, con su acción recomendada.
 *  3. El router gestionable (por VPN o por RADIUS) se comporta como siempre.
 *
 * Qué NO fija esta suite, y de quién es: el estado comercial del cliente tras
 * el pago lo decide `reactivateIfCleared()` —hoy lo deja activo en la BD para
 * que el reconciliador no lo vuelva a cortar por `status = false`— y eso vive
 * en PaymentReconnectionWarningTest, que es donde se diseñó.
 */
class PaymentWithUnconfiguredRouterTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** Router al que ISPWatch no tiene por dónde entrarle. */
    private function routerSinConfigurar(Tenant $tenant): Router
    {
        return Router::create([
            'name'      => 'Router a medias ' . uniqid(),
            'tenant_id' => $tenant->id,
            'status'    => 'active',
        ]);
    }

    private function routerConfigurado(Tenant $tenant): Router
    {
        return Router::create([
            'name'        => 'Router sano ' . uniqid(),
            'tenant_id'   => $tenant->id,
            'status'      => 'active',
            'ip'          => '172.16.16.9',
            'user_rb'     => 'ispwatch',
            'password_rb' => 'secreto',
        ]);
    }

    private function clienteCortado(Tenant $tenant, Router $router, float $deuda = 25000): User
    {
        $this->seq++;
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        CustomerProfile::create([
            'user_id'        => $user->id,
            'name'           => "Cliente{$this->seq}",
            'last_name'      => "Apellido{$this->seq}",
            'router_id'      => $router->id,
            'ip_user'        => '10.0.0.' . $this->seq,
            'status'         => false,
            'service_status' => 'suspendido',
        ]);

        Invoice::create([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $user->id,
            'number'       => uniqid('INV-'),
            'issue_date'   => now()->subDays(30),
            'due_date'     => now()->subDays(10),
            'period_start' => now()->subMonth()->startOfMonth(),
            'period_end'   => now()->subMonth()->endOfMonth(),
            'subtotal'     => $deuda,
            'total'        => $deuda,
            'balance_due'  => $deuda,
            'status'       => 'overdue',
        ]);

        SuspensionActionLog::create([
            'router_id'   => $router->id,
            'customer_id' => $user->id,
            'ip'          => '10.0.0.' . $this->seq,
            'action'      => SuspensionActionLog::ACTION_SUSPEND,
            'reason'      => SuspensionActionLog::REASON_AUTO_CUT,
            'status'      => SuspensionActionLog::STATUS_SUCCESS,
            'attempts'    => 1,
        ]);

        return $user;
    }

    /** @return array<string, mixed> */
    private function pagar(Tenant $tenant, User $user, float $monto = 25000): array
    {
        return app(BillingService::class)->registerPayment([
            'tenant_id'    => $tenant->id,
            'customer_id'  => $user->id,
            'amount'       => $monto,
            'payment_date' => now()->toDateString(),
            'method'       => 'cash',
        ])->reactivation;
    }

    // ── El caso que reportó el ISP ──────────────────────────────────────

    #[Test]
    public function un_router_sin_configurar_no_recibe_ni_un_intento(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->routerSinConfigurar($tenant);
        $user   = $this->clienteCortado($tenant, $router);

        // Si esto se llamara, en producción serían dos sesiones SSH esperando
        // el tiempo de espera completo dentro de la petición del pago.
        $mock = Mockery::mock(RouterProvisioningService::class);
        $this->app->instance(RouterProvisioningService::class, $mock);
        $mock->shouldNotReceive('unsuspendCustomer');

        $resultado = $this->pagar($tenant, $user);

        $this->assertTrue($resultado['was_suspended']);
        $this->assertSame(ReconnectionOutcome::PENDIENTE_CONFIGURACION_INCOMPLETA, $resultado['outcome']);
        $this->assertFalse($resultado['router_ok'], 'Nadie confirmó nada en el equipo.');
        $this->assertTrue($resultado['pending'], 'La reconexión queda pendiente, no resuelta.');
    }

    #[Test]
    public function el_pago_queda_guardado_aunque_el_router_no_se_pueda_tocar(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->routerSinConfigurar($tenant);
        $user   = $this->clienteCortado($tenant, $router);

        $this->pagar($tenant, $user);

        // Es la mitad que importa: el dinero entra igual. Que fallara el aviso
        // es lo que llevaba al cajero a cobrar dos veces.
        $this->assertDatabaseHas('payments', [
            'customer_id' => $user->id,
            'amount'      => 25000,
            'status'      => 'completed',
        ]);
        $this->assertSame(
            0.0,
            (float) Invoice::where('customer_id', $user->id)->first()->balance_due,
            'La factura tiene que quedar saldada.'
        );
    }

    #[Test]
    public function el_desenlace_queda_escrito_como_pendiente_y_no_como_exito(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->routerSinConfigurar($tenant);
        $user   = $this->clienteCortado($tenant, $router);

        $resultado = $this->pagar($tenant, $user);

        // El cliente queda activo en la BD a propósito —si no, el reconciliador
        // volvería a cortarlo por `status = false`— pero el desenlace del EQUIPO
        // se cuenta aparte y en ningún caso como reconectado. Pago confirmado no
        // es reconexión confirmada.
        $this->assertTrue((bool) CustomerProfile::where('user_id', $user->id)->first()->status);
        $this->assertTrue($resultado['pending']);
        $this->assertNotSame(ReconnectionOutcome::REACTIVADO_AUTOMATICAMENTE, $resultado['outcome']);
        $this->assertNotSame('', trim((string) $resultado['action']), 'El aviso tiene que decir qué hacer.');
    }

    // ── Lo que no debe cambiar ──────────────────────────────────────────

    #[Test]
    public function con_radius_el_cliente_si_queda_reactivado(): void
    {
        $tenant = Tenant::factory()->create();

        // RADIUS es gestionable por delegación: el estado del abonado no vive
        // en el equipo, así que no hay nada que escribirle y tampoco hay espera.
        $router = Router::create([
            'name'      => 'Router AAA ' . uniqid(),
            'tenant_id' => $tenant->id,
            'status'    => 'active',
            'radius'    => true,
        ]);

        $user = $this->clienteCortado($tenant, $router);

        $resultado = $this->pagar($tenant, $user);

        $this->assertTrue($resultado['reactivated']);
        $this->assertTrue((bool) CustomerProfile::where('user_id', $user->id)->first()->status);
    }

    #[Test]
    public function el_router_configurado_sigue_reconectando_como_siempre(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->routerConfigurado($tenant);
        $user   = $this->clienteCortado($tenant, $router);

        $mock = Mockery::mock(RouterProvisioningService::class);
        $this->app->instance(RouterProvisioningService::class, $mock);
        $mock->shouldReceive('unsuspendCustomer')
            ->once()
            ->with($user->id, $router->id, Mockery::on(
                fn ($ctx) => ($ctx['reason'] ?? null) === SuspensionActionLog::REASON_AUTO_RECONNECT
            ))
            ->andReturn(true);

        $resultado = $this->pagar($tenant, $user);

        $this->assertTrue($resultado['reactivated']);
        $this->assertTrue($resultado['router_ok']);
        $this->assertSame(ReconnectionOutcome::REACTIVADO_AUTOMATICAMENTE, $resultado['outcome']);
    }

    // ── La regla, en el modelo ──────────────────────────────────────────

    #[Test]
    public function el_router_explica_que_le_falta(): void
    {
        $tenant = Tenant::factory()->create();

        $sinNada = $this->routerSinConfigurar($tenant);
        $this->assertFalse($sinNada->isManageable());
        $this->assertStringContainsString('no tiene dirección IP ni usuario de VPN', $sinNada->manageabilityIssue());
        $this->assertStringContainsString('usuario y la contraseña', $sinNada->manageabilityIssue());
        $this->assertStringContainsString($sinNada->name, $sinNada->manageabilityIssue());

        $this->assertTrue($this->routerConfigurado($tenant)->isManageable());

        // Con identidad de túnel basta: la IP la reescribe el resolver leyendo
        // `/ppp active` del CORE, así que exigirla sería exigir un dato que el
        // sistema sabe averiguar solo.
        $soloVpn = Router::create([
            'name'         => 'Router overlay ' . uniqid(),
            'tenant_id'    => $tenant->id,
            'status'       => 'active',
            'vpn_username' => 'tenant19-rb1',
            'user_rb'      => 'ispwatch',
            'password_rb'  => 'secreto',
        ]);
        $this->assertTrue($soloVpn->isManageable());

        // Y sin credenciales no se salva ni con dirección: el CORE llegaría
        // para que el equipo le rechace la clave.
        $sinClave = Router::create([
            'name'      => 'Router sin clave ' . uniqid(),
            'tenant_id' => $tenant->id,
            'status'    => 'active',
            'ip'        => '172.16.16.30',
        ]);
        $this->assertFalse($sinClave->isManageable());
    }
}
