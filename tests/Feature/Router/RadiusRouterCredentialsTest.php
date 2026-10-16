<?php

namespace Tests\Feature\Router;

use App\Models\CustomerProfile;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CustomerProvisioningService;
use App\Services\MikroTik\RouterEndpointResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * KAN-102: en modo RADIUS el router puede ser un agrupador lógico sin MikroTik
 * detrás, así que IP, usuario, contraseña y firmware dejan de ser obligatorios.
 * Con radius = false las reglas siguen exactamente como antes.
 */
class RadiusRouterCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function actingAdmin(): Tenant
    {
        $tenant = Tenant::factory()->create();
        $role   = Role::create(['name' => 'Admin' . (++$this->seq), 'permissions' => ['*']]);
        Sanctum::actingAs(User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]));

        return $tenant;
    }

    private function classicRouter(Tenant $tenant, array $overrides = []): Router
    {
        return Router::create(array_merge([
            'name'             => 'RB ' . (++$this->seq),
            'tenant_id'        => $tenant->id,
            'status'           => 'active',
            'ip'               => '10.10.10.' . (++$this->seq),
            'user_rb'          => 'admin',
            'password_rb'      => 'secreta',
            'firmware_version' => '7.14.3',
            'pppoe'            => true,
        ], $overrides));
    }

    // ── Alta ───────────────────────────────────────────────────

    #[Test]
    public function se_crea_un_router_radius_sin_ip_usuario_contrasena_ni_firmware(): void
    {
        $this->actingAdmin();

        $this->postJson('/api/routers', [
            'name'   => 'Facturación electrónica',
            'status' => 'active',
            'radius' => true,
        ])->assertCreated();

        $router = Router::withoutTenantScope()->where('name', 'Facturación electrónica')->firstOrFail();
        $this->assertTrue($router->usesRadius());
        $this->assertNull($router->ip);
        $this->assertNull($router->user_rb);
        $this->assertNull($router->password_rb);
        $this->assertNull($router->firmware_version);
    }

    #[Test]
    public function el_formulario_vacio_del_frontend_tambien_pasa_en_radius(): void
    {
        // El frontend manda cadenas vacías, no omite los campos.
        $this->actingAdmin();

        $this->postJson('/api/routers', [
            'name' => 'Cuentas de cobro', 'status' => 'active', 'radius' => true,
            'ip' => '', 'user_rb' => '', 'password_rb' => '', 'firmware_version' => '',
        ])->assertCreated();
    }

    #[Test]
    public function en_radius_una_ip_que_si_viene_sigue_validandose(): void
    {
        $this->actingAdmin();

        $this->postJson('/api/routers', [
            'name' => 'NAS', 'status' => 'active', 'radius' => true, 'ip' => 'no-es-una-ip',
        ])->assertStatus(422)->assertJsonValidationErrors(['ip']);
    }

    #[Test]
    public function sin_radius_las_reglas_siguen_igual_que_antes(): void
    {
        $this->actingAdmin();

        $this->postJson('/api/routers', ['name' => 'RB', 'status' => 'active', 'pppoe' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ip', 'user_rb', 'password_rb', 'firmware_version']);

        $this->postJson('/api/routers', ['name' => 'RB', 'status' => 'active'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ip', 'user_rb', 'password_rb', 'firmware_version']);
    }

    #[Test]
    public function status_sigue_siendo_obligatorio_en_radius(): void
    {
        $this->actingAdmin();

        $this->postJson('/api/routers', ['name' => 'NAS', 'radius' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    // ── Edición ────────────────────────────────────────────────

    #[Test]
    public function un_router_existente_pasa_a_radius_y_se_vacian_sus_datos_de_equipo(): void
    {
        // El caso real de la migración de CNO.
        $tenant = $this->actingAdmin();
        $router = $this->classicRouter($tenant);

        $this->putJson("/api/routers/{$router->id}", [
            'radius' => true, 'pppoe' => false,
            'ip' => null, 'user_rb' => null, 'password_rb' => null, 'firmware_version' => null,
        ])->assertOk();

        $router->refresh();
        $this->assertTrue($router->usesRadius());
        $this->assertNull($router->ip);
        $this->assertNull($router->user_rb);
        $this->assertNull($router->password_rb);
        $this->assertNull($router->firmware_version);
    }

    #[Test]
    public function un_router_ya_radius_se_puede_vaciar_sin_volver_a_mandar_el_modo(): void
    {
        $tenant = $this->actingAdmin();
        $router = $this->classicRouter($tenant, ['radius' => true, 'pppoe' => false]);

        $this->putJson("/api/routers/{$router->id}", ['ip' => null, 'user_rb' => null])->assertOk();

        $router->refresh();
        $this->assertNull($router->ip);
        $this->assertNull($router->user_rb);
    }

    #[Test]
    public function un_router_clasico_no_se_puede_vaciar_igual_que_antes(): void
    {
        $tenant = $this->actingAdmin();
        $router = $this->classicRouter($tenant);

        $this->putJson("/api/routers/{$router->id}", ['ip' => null, 'password_rb' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ip', 'password_rb']);

        // Y un PATCH que no los menciona sigue pasando.
        $this->putJson("/api/routers/{$router->id}", ['name' => 'Renombrado'])->assertOk();
    }

    #[Test]
    public function salir_de_radius_exige_los_datos_que_el_router_no_tiene(): void
    {
        $tenant = $this->actingAdmin();
        $router = Router::create([
            'name' => 'NAS lógico', 'tenant_id' => $tenant->id, 'status' => 'active', 'radius' => true,
        ]);

        $this->putJson("/api/routers/{$router->id}", ['radius' => false, 'pppoe' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ip', 'user_rb', 'password_rb', 'firmware_version']);

        $this->putJson("/api/routers/{$router->id}", [
            'radius' => false, 'pppoe' => true,
            'ip' => '10.10.10.9', 'user_rb' => 'admin', 'password_rb' => 'secreta', 'firmware_version' => '7.14.3',
        ])->assertOk();

        $this->assertFalse($router->fresh()->usesRadius());
    }

    // ── Nada más abajo asume esos datos ────────────────────────

    #[Test]
    public function aprovisionar_en_un_router_radius_sin_datos_de_equipo_no_resuelve_endpoint(): void
    {
        $tenant = Tenant::factory()->create();
        $router = Router::create([
            'name' => 'NAS lógico', 'tenant_id' => $tenant->id, 'status' => 'active', 'radius' => true,
        ]);

        $resolver = \Mockery::mock(RouterEndpointResolver::class);
        $resolver->shouldNotReceive('resolve');
        $this->app->instance(RouterEndpointResolver::class, $resolver);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        CustomerProfile::create([
            'user_id'        => $user->id,
            'name'           => 'Cliente',
            'last_name'      => 'Radius',
            'router_id'      => $router->id,
            'service_id'     => Plan::factory()->create(['tenant_id' => $tenant->id])->id,
            'ip_user'        => '10.20.0.2',
            'pppoe_username' => 'cliente-radius',
            'pppoe_password' => 'secreta',
        ]);

        $result = app(CustomerProvisioningService::class)->provisionOne($user->id, $tenant->id);

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertSame(CustomerProvisioningService::MODE_RADIUS, $result['mode']);
    }
}
