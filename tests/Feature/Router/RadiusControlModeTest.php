<?php

namespace Tests\Feature\Router;

use App\Models\CustomerProfile;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CustomerProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RADIUS como sexto método de control del router.
 *
 * Lo que estos tests cuidan de verdad es que el modo RADIUS NO toque la red:
 * si el dispatcher intentara resolver el endpoint o abrir SSH, en el entorno de
 * pruebas se colgaría o fallaría, y en producción metería 17-34s y un punto de
 * falla en una operación que no necesita el router para nada.
 */
class RadiusControlModeTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function admin(Tenant $tenant): User
    {
        $role = Role::create(['name' => 'Admin' . (++$this->seq), 'permissions' => ['*']]);

        return User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]);
    }

    private function radiusRouter(Tenant $tenant, array $overrides = []): Router
    {
        return Router::create(array_merge([
            'name'      => 'NAS ' . (++$this->seq),
            'tenant_id' => $tenant->id,
            'status'    => 'active',
            'radius'    => true,
        ], $overrides));
    }

    private function customerOn(Tenant $tenant, Router $router, array $overrides = []): CustomerProfile
    {
        $this->seq++;
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $plan = Plan::factory()->create(['tenant_id' => $tenant->id]);

        return CustomerProfile::create(array_merge([
            'user_id'         => $user->id,
            'name'            => "Cliente{$this->seq}",
            'last_name'       => "Apellido{$this->seq}",
            'router_id'       => $router->id,
            'service_id'      => $plan->id,
            'ip_user'         => '10.20.0.' . (($this->seq % 200) + 2),
            'pppoe_username'  => "cliente{$this->seq}",
            'pppoe_password'  => 'secreta',
        ], $overrides));
    }

    #[Test]
    public function radius_gana_la_resolucion_del_metodo_de_control(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->radiusRouter($tenant);

        $this->assertSame(
            CustomerProvisioningService::MODE_RADIUS,
            CustomerProvisioningService::resolveControlMode($router)
        );
    }

    #[Test]
    public function radius_desempata_contra_un_modo_heredado_encendido(): void
    {
        // Fila legada con dos banderas: gana RADIUS por ser el único que no
        // escribe en el RouterBoard.
        $tenant = Tenant::factory()->create();
        $router = $this->radiusRouter($tenant, ['pppoe' => true]);

        $this->assertSame(
            CustomerProvisioningService::MODE_RADIUS,
            CustomerProvisioningService::resolveControlMode($router)
        );
    }

    #[Test]
    public function aprovisionar_en_radius_no_toca_el_router_y_reporta_exito(): void
    {
        $tenant   = Tenant::factory()->create();
        // Sin ip / user_rb / password_rb a propósito: el modo RADIUS no las
        // necesita, y exigirlas rechazaría clientes perfectamente válidos.
        $router   = $this->radiusRouter($tenant);
        $customer = $this->customerOn($tenant, $router);

        $result = app(CustomerProvisioningService::class)
            ->provisionOne($customer->user_id, $tenant->id);

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertSame(CustomerProvisioningService::MODE_RADIUS, $result['mode']);

        // Ningún paso de red debe haberse ejecutado.
        $this->assertNull($result['queue_result']);
        $this->assertNull($result['pppoe_result']);
        $this->assertNull($result['dhcp_result']);
        $this->assertFalse($result['pppoe_applies']);
    }

    #[Test]
    public function aprovisionar_en_radius_falla_si_el_cliente_no_tiene_credenciales_pppoe(): void
    {
        $tenant   = Tenant::factory()->create();
        $router   = $this->radiusRouter($tenant);
        $customer = $this->customerOn($tenant, $router, [
            'pppoe_username' => null,
            'pppoe_password' => null,
        ]);

        $result = app(CustomerProvisioningService::class)
            ->provisionOne($customer->user_id, $tenant->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('credenciales PPPoE', $result['message']);
    }

    #[Test]
    public function guardar_un_router_con_radius_apaga_los_demas_modos(): void
    {
        $tenant = Tenant::factory()->create();
        Sanctum::actingAs($this->admin($tenant));

        $response = $this->postJson('/api/routers', [
            'name'             => 'NAS Central',
            'ip'               => '10.10.10.1',
            'user_rb'          => 'admin',
            'password_rb'      => 'secreta',
            'firmware_version' => '7.14.3',
            'status'           => 'active',
            // El cliente manda dos modos: el backend debe quedarse con uno.
            'radius'           => true,
            'pppoe'            => true,
        ]);

        $response->assertCreated();

        $router = Router::withoutTenantScope()->where('name', 'NAS Central')->firstOrFail();
        $this->assertTrue($router->radius);
        $this->assertFalse((bool) $router->pppoe);
        $this->assertFalse((bool) $router->simple_queue);
    }

    #[Test]
    public function cambiar_a_otro_modo_apaga_radius(): void
    {
        // El camino de vuelta importa tanto como el de ida: un router que se
        // saca de RADIUS tiene que volver a recibir configuración por SSH, y
        // para eso la bandera debe quedar en false de verdad.
        $tenant = Tenant::factory()->create();
        Sanctum::actingAs($this->admin($tenant));

        $router = $this->radiusRouter($tenant, [
            'ip'               => '10.10.10.2',
            'user_rb'          => 'admin',
            'password_rb'      => 'secreta',
            'firmware_version' => '7.14.3',
        ]);

        $this->putJson("/api/routers/{$router->id}", [
            'radius'       => false,
            'simple_queue' => true,
        ])->assertOk();

        $router->refresh();
        $this->assertFalse((bool) $router->radius);
        $this->assertTrue((bool) $router->simple_queue);
        $this->assertSame(
            CustomerProvisioningService::MODE_SIMPLE_QUEUE,
            CustomerProvisioningService::resolveControlMode($router)
        );
    }

    #[Test]
    public function un_router_sin_radius_no_expone_la_bandera_encendida(): void
    {
        $tenant = Tenant::factory()->create();
        $router = Router::create([
            'name'      => 'RB clásico',
            'tenant_id' => $tenant->id,
            'status'    => 'active',
        ]);

        $this->assertFalse($router->usesRadius());
        $this->assertNull(CustomerProvisioningService::resolveControlMode($router));
    }
}
