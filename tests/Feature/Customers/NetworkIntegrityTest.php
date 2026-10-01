<?php

namespace Tests\Feature\Customers;

use App\Jobs\PurgeCustomerFromPreviousRouterJob;
use App\Models\AuditLog;
use App\Models\CustomerProfile;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CustomerDeletionService;
use App\Services\MikroTik\CustomerDeprovisionManager;
use App\Services\MikroTik\RouterEndpointResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Integridad de los datos de red de un cliente (KAN-117, KAN-118, KAN-119).
 */
class NetworkIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function router(string $name, bool $radius = false, bool $credentials = true): Router
    {
        return Router::create(array_filter([
            'tenant_id'   => $this->tenant->id,
            'name'        => $name,
            'status'      => 'active',
            'radius'      => $radius,
            'ip'          => '172.16.30.' . random_int(2, 250),
            'user_rb'     => $credentials ? 'ispwatch' : null,
            'password_rb' => $credentials ? 'secreto' : null,
        ], fn ($v) => $v !== null));
    }

    private function customer(?Router $router, array $profile = []): CustomerProfile
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        return CustomerProfile::create(array_merge([
            'user_id'          => $user->id,
            'name'             => 'Cliente',
            'last_name'        => 'Red ' . $user->id,
            'router_id'        => $router?->id,
            'ip_user'          => '10.60.0.' . (($user->id % 200) + 2),
            'pppoe_username'   => "cliente{$user->id}",
            'hotspot_username' => "hs{$user->id}",
            'mac_address'      => sprintf('AA:BB:CC:DD:EE:%02X', $user->id % 255),
            'status'           => true,
            'service_status'   => 'activo',
        ], $profile));
    }

    private function fakeNetwork(): \Mockery\MockInterface
    {
        $resolver = \Mockery::mock(RouterEndpointResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(['ip' => '172.16.30.9', 'ssh_port' => 22]);
        $this->app->instance(RouterEndpointResolver::class, $resolver);

        $deprovision = \Mockery::mock(CustomerDeprovisionManager::class);
        $this->app->instance(CustomerDeprovisionManager::class, $deprovision);

        return $deprovision;
    }

    // ─── KAN-118: IP única por router en la base ───────────────────────────

    #[Test]
    public function la_base_rechaza_dos_clientes_del_mismo_router_con_la_misma_ip(): void
    {
        // Sin pasar por la validación: es lo que hace una carrera entre dos
        // guardados, o cualquier escritor que no sea el formulario.
        $router = $this->router('Core IP');
        $this->customer($router, ['ip_user' => '10.70.0.5']);
        $otro = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->expectException(QueryException::class);

        DB::table('customer_profile')->insert([
            'user_id'   => $otro->id,
            'name'      => 'Duplicado',
            'last_name' => 'IP',
            'router_id' => $router->id,
            'ip_user'   => '10.70.0.5',
            'status'    => true,
        ]);
    }

    #[Test]
    public function la_misma_ip_sigue_valiendo_en_otro_router_o_sin_ip(): void
    {
        $uno = $this->router('Core Uno');
        $dos = $this->router('Core Dos');

        $this->customer($uno, ['ip_user' => '10.70.0.9']);
        $this->customer($dos, ['ip_user' => '10.70.0.9']);
        $this->customer($uno, ['ip_user' => null]);
        $this->customer($uno, ['ip_user' => null]);
        $this->customer(null, ['ip_user' => '10.70.0.9']);

        $this->assertSame(5, CustomerProfile::count());
    }

    // ─── KAN-119: el router anterior se limpia ─────────────────────────────

    #[Test]
    public function mudar_de_router_encola_la_limpieza_del_anterior_con_la_identidad_original(): void
    {
        Queue::fake();

        $viejo   = $this->router('Core Viejo');
        $nuevo   = $this->router('Core Nuevo');
        $cliente = $this->customer($viejo, ['ip_user' => '10.80.0.1', 'pppoe_username' => 'viejo.pppoe']);

        // En la misma edición cambian también IP y usuario: en el router viejo
        // lo que hay que borrar es lo que tenía ANTES.
        $cliente->update(['router_id' => $nuevo->id, 'ip_user' => '10.80.0.2', 'pppoe_username' => 'nuevo.pppoe']);

        Queue::assertPushed(PurgeCustomerFromPreviousRouterJob::class, function ($job) use ($viejo, $cliente) {
            return $job->routerId === $viejo->id
                && $job->customerId === $cliente->user_id
                && $job->tenantId === $this->tenant->id
                && $job->identity['ip'] === '10.80.0.1'
                && $job->identity['pppoe_username'] === 'viejo.pppoe';
        });
    }

    #[Test]
    public function no_se_encola_nada_si_el_router_anterior_es_radius_o_no_tiene_credenciales(): void
    {
        Queue::fake();

        $nuevo = $this->router('Core Destino');

        $this->customer($this->router('NAS Radius', radius: true))->update(['router_id' => $nuevo->id]);
        $this->customer($this->router('Sin acceso', credentials: false))->update(['router_id' => $nuevo->id]);
        $this->customer(null)->update(['router_id' => $nuevo->id]);

        Queue::assertNotPushed(PurgeCustomerFromPreviousRouterJob::class);
    }

    #[Test]
    public function la_limpieza_no_borra_lo_que_hoy_usa_otro_cliente_del_router_viejo(): void
    {
        $viejo = $this->router('Core Compartido');
        $mudado = $this->customer($this->router('Core Destino'), ['ip_user' => '10.90.0.1', 'pppoe_username' => 'mudado']);

        // Otro cliente del router viejo quedó con la IP que tenía el mudado.
        $this->customer($viejo, ['ip_user' => '10.90.0.1', 'pppoe_username' => 'heredero']);

        $deprovision = $this->fakeNetwork();
        $deprovision->shouldReceive('purge')
            ->once()
            ->withArgs(fn ($ip, $user, $pass, array $identity) => !array_key_exists('ip', $identity)
                && $identity['pppoe_username'] === 'mudado')
            ->andReturn(['success' => true, 'message' => 'ok', 'statements' => 3]);

        (new PurgeCustomerFromPreviousRouterJob($viejo->id, $mudado->user_id, $this->tenant->id, [
            'ip'               => '10.90.0.1',
            'pppoe_username'   => 'mudado',
            'hotspot_username' => null,
            'mac_address'      => null,
        ]))->handle(app(CustomerDeprovisionManager::class), app(RouterEndpointResolver::class));

        $this->assertDatabaseHas('audit_logs', [
            'action'   => 'customer.previous_router_cleaned',
            'model_id' => $mudado->user_id,
        ]);
    }

    #[Test]
    public function si_la_limpieza_falla_queda_visible_en_la_auditoria(): void
    {
        $viejo   = $this->router('Core Caido');
        $mudado  = $this->customer($this->router('Core Destino'));

        $this->fakeNetwork()->shouldReceive('purge')
            ->andReturn(['success' => false, 'message' => 'No se pudo conectar al CORE por SSH.']);

        (new PurgeCustomerFromPreviousRouterJob($viejo->id, $mudado->user_id, $this->tenant->id, [
            'ip' => '10.91.0.1', 'pppoe_username' => null, 'hotspot_username' => null, 'mac_address' => null,
        ]))->handle(app(CustomerDeprovisionManager::class), app(RouterEndpointResolver::class));

        $log = AuditLog::where('action', 'customer.previous_router_cleanup_failed')->first();

        $this->assertNotNull($log, 'Un router viejo sin limpiar no puede ser silencioso');
        $this->assertSame($this->tenant->id, (int) $log->tenant_id);
        $this->assertStringContainsString('a mano', $log->description);
    }

    #[Test]
    public function borrar_un_cliente_de_un_router_radius_no_abre_ssh(): void
    {
        // Router lógico del piloto: RADIUS y credenciales de relleno.
        $deprovision = $this->fakeNetwork();
        $deprovision->shouldNotReceive('purge');

        $cliente = $this->customer($this->router('NAS Piloto', radius: true));

        $result = app(CustomerDeletionService::class)->delete(User::findOrFail($cliente->user_id), $cliente);

        $this->assertTrue($result['router']['success']);
        $this->assertTrue($result['router']['skipped']);
    }

    // ─── KAN-117: auditoría de señales de acceso ───────────────────────────

    #[Test]
    public function la_auditoria_cuenta_las_fichas_desalineadas_y_no_toca_nada(): void
    {
        $this->customer(null, ['status' => true, 'service_status' => 'activo']);

        $deshabilitado = $this->customer(null);
        $suspendido    = $this->customer(null);
        $deBaja        = $this->customer(null);

        // Por query builder: así llegaron los datos viejos, sin observer.
        CustomerProfile::where('user_id', $deshabilitado->user_id)->update(['status' => false]);
        CustomerProfile::where('user_id', $suspendido->user_id)->update(['service_status' => 'suspendido']);
        CustomerProfile::where('user_id', $deBaja->user_id)->update(['service_status' => 'retirado']);

        $this->artisan('customers:audit-access-flags')
            ->expectsOutputToContain('3 ficha(s) desalineadas')
            ->assertFailed();

        $this->assertFalse((bool) CustomerProfile::where('user_id', $deshabilitado->user_id)->value('status'));
        $this->assertSame('retirado', CustomerProfile::where('user_id', $deBaja->user_id)->value('service_status'));
    }

    #[Test]
    public function la_auditoria_pasa_cuando_todo_coincide(): void
    {
        $this->customer(null, ['status' => true, 'service_status' => 'activo']);
        $this->customer(null, ['status' => false, 'service_status' => 'suspendido']);

        $this->artisan('customers:audit-access-flags')->assertSuccessful();
    }
}
