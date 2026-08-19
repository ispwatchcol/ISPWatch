<?php

namespace Tests\Feature\Router;

use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Borrar un core con clientes encima era un borrado silencioso de datos.
 *
 * La FK customer_profile.router_id es ON DELETE SET NULL, así que el DELETE
 * devolvía 200 y dejaba a los abonados sin router: sin ciclo de facturación
 * por-router, sin cortes, sin avisos de falla masiva y sin forma de recuperar a
 * qué core estaban conectados. Estos tests fijan el rechazo.
 */
class RouterDeletionTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function admin(Tenant $tenant): User
    {
        $role = Role::create(['name' => 'Admin' . (++$this->seq), 'permissions' => ['*']]);

        return User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]);
    }

    private function router(Tenant $tenant, string $name = 'Core Norte'): Router
    {
        return Router::create([
            'name'      => $name . ' ' . (++$this->seq),
            'tenant_id' => $tenant->id,
            'status'    => 'active',
        ]);
    }

    private function customer(Tenant $tenant, Router $router, string $serviceStatus): CustomerProfile
    {
        $this->seq++;
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        return CustomerProfile::create([
            'user_id'        => $user->id,
            'tenant_id'      => $tenant->id,
            'name'           => "Cliente{$this->seq}",
            'last_name'      => "Apellido{$this->seq}",
            'router_id'      => $router->id,
            'status'         => $serviceStatus !== 'suspendido',
            'service_status' => $serviceStatus,
        ]);
    }

    #[Test]
    public function a_router_with_live_customers_cannot_be_deleted(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant);
        $this->customer($tenant, $router, 'activo');
        $this->customer($tenant, $router, 'suspendido'); // cortado por mora: sigue dependiendo

        Sanctum::actingAs($this->admin($tenant));

        $this->deleteJson("/api/routers/{$router->id}")
            ->assertStatus(409)
            ->assertJson(['active_customers' => 2, 'inactive_customers' => 0]);

        $this->assertNotNull(Router::find($router->id));
        $this->assertSame(
            2,
            CustomerProfile::where('router_id', $router->id)->count(),
            'los clientes no pueden quedar huérfanos'
        );
    }

    #[Test]
    public function force_does_not_override_live_customers(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant);
        $this->customer($tenant, $router, 'activo');

        Sanctum::actingAs($this->admin($tenant));

        $this->deleteJson("/api/routers/{$router->id}?force=1")->assertStatus(409);

        $this->assertNotNull(Router::find($router->id));
    }

    #[Test]
    public function a_row_with_no_service_status_still_counts_as_a_live_customer(): void
    {
        // Filas anteriores a la columna service_status: en la práctica son
        // clientes normales, y el ciclo de facturación las trata como tales.
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        CustomerProfile::create([
            'user_id'   => $user->id,
            'tenant_id' => $tenant->id,
            'name'      => 'Antiguo',
            'last_name' => 'Cliente',
            'router_id' => $router->id,
            'status'    => true,
        ]);

        Sanctum::actingAs($this->admin($tenant));

        $this->deleteJson("/api/routers/{$router->id}")
            ->assertStatus(409)
            ->assertJson(['active_customers' => 1]);
    }

    #[Test]
    public function a_router_with_only_terminated_customers_needs_an_explicit_force(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant);
        $this->customer($tenant, $router, 'retirado');
        $this->customer($tenant, $router, 'cancelado');

        Sanctum::actingAs($this->admin($tenant));

        $this->deleteJson("/api/routers/{$router->id}")
            ->assertStatus(409)
            ->assertJson([
                'active_customers'   => 0,
                'inactive_customers' => 2,
                'requires_force'     => true,
            ]);
        $this->assertNotNull(Router::find($router->id));

        $this->deleteJson("/api/routers/{$router->id}?force=1")->assertOk();
        $this->assertNull(Router::find($router->id));
    }

    #[Test]
    public function customers_on_another_router_do_not_block_the_deletion(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant, 'Core vacío');
        $other  = $this->router($tenant, 'Core con clientes');
        $this->customer($tenant, $other, 'activo');

        Sanctum::actingAs($this->admin($tenant));

        $this->deleteJson("/api/routers/{$router->id}")->assertOk();

        $this->assertNull(Router::find($router->id));
        $this->assertNotNull(Router::find($other->id));
    }

    #[Test]
    public function the_router_list_reports_how_many_live_customers_each_one_has(): void
    {
        $tenant = Tenant::factory()->create();
        $router = $this->router($tenant);
        $this->customer($tenant, $router, 'activo');
        $this->customer($tenant, $router, 'gratis');
        $this->customer($tenant, $router, 'retirado'); // baja: no cuenta

        Sanctum::actingAs($this->admin($tenant));

        $row = collect($this->getJson('/api/routers')->assertOk()->json())
            ->firstWhere('id', $router->id);

        $this->assertSame(2, (int) $row['active_customers_count']);
    }
}
