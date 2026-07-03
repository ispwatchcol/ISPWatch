<?php

namespace Tests\Feature\Router;

use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\Router;
use App\Models\RouterOutageEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Falla masiva" broadcast: the operator flips a core in/out of failure and
 * ISPWatch records an append-only event (which Converza later polls read-only).
 */
class RouterOutageTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function admin(Tenant $tenant): User
    {
        $role = Role::create(['name' => 'Admin' . (++$this->seq), 'permissions' => ['*']]);
        return User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]);
    }

    private function makeCustomer(Tenant $tenant, ?Router $router, bool $active): User
    {
        $this->seq++;
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        CustomerProfile::create([
            'user_id'   => $user->id,
            'name'      => "Cliente{$this->seq}",
            'last_name' => "Apellido{$this->seq}",
            'router_id' => $router?->id,
            'status'    => $active,
        ]);
        return $user;
    }

    #[Test]
    public function notify_marks_the_core_in_failure_and_records_an_outage_event(): void
    {
        $tenant = Tenant::factory()->create();
        $admin  = $this->admin($tenant);
        $router = Router::create(['name' => 'Core Norte', 'tenant_id' => $tenant->id, 'status' => 'active']);
        $other  = Router::create(['name' => 'Core Sur', 'tenant_id' => $tenant->id, 'status' => 'active']);

        // 2 active on the core, 1 inactive on the core, 1 active on ANOTHER core.
        $this->makeCustomer($tenant, $router, true);
        $this->makeCustomer($tenant, $router, true);
        $this->makeCustomer($tenant, $router, false);
        $this->makeCustomer($tenant, $other, true);

        Sanctum::actingAs($admin);

        $this->postJson("/api/routers/{$router->id}/outage/notify")
            ->assertOk()
            ->assertJson([
                'falla_general'  => true,
                'affected_count' => 2, // only active customers on THIS core
            ]);

        $this->assertTrue((bool) $router->fresh()->falla_general);

        $event = RouterOutageEvent::where('router_id', $router->id)->sole();
        $this->assertSame(RouterOutageEvent::TYPE_OUTAGE, $event->type);
        $this->assertSame(2, (int) $event->affected_count);
        $this->assertSame($admin->id, (int) $event->created_by);
        $this->assertSame($tenant->id, (int) $event->tenant_id);
    }

    #[Test]
    public function resolve_marks_the_core_restored_and_records_a_restored_event(): void
    {
        $tenant = Tenant::factory()->create();
        $admin  = $this->admin($tenant);
        $router = Router::create(['name' => 'Core Norte', 'tenant_id' => $tenant->id, 'status' => 'active', 'falla_general' => true]);
        $this->makeCustomer($tenant, $router, true);

        Sanctum::actingAs($admin);

        $this->postJson("/api/routers/{$router->id}/outage/resolve")
            ->assertOk()
            ->assertJson(['falla_general' => false, 'affected_count' => 1]);

        $this->assertFalse((bool) $router->fresh()->falla_general);

        $event = RouterOutageEvent::where('router_id', $router->id)->sole();
        $this->assertSame(RouterOutageEvent::TYPE_RESTORED, $event->type);
    }

    #[Test]
    public function show_returns_current_state_and_affected_count(): void
    {
        $tenant = Tenant::factory()->create();
        $admin  = $this->admin($tenant);
        $router = Router::create(['name' => 'Core Norte', 'tenant_id' => $tenant->id, 'status' => 'active']);
        $this->makeCustomer($tenant, $router, true);
        $this->makeCustomer($tenant, $router, true);

        Sanctum::actingAs($admin);

        $this->getJson("/api/routers/{$router->id}/outage")
            ->assertOk()
            ->assertJson([
                'router_id'      => $router->id,
                'falla_general'  => false,
                'affected_count' => 2,
            ])
            ->assertJsonStructure(['recent']);
    }

    #[Test]
    public function notify_requires_the_manage_routers_permission(): void
    {
        $tenant = Tenant::factory()->create();
        // Occupy id=1 so ReadOnly is NOT the superadmin role (role_id==1 bypasses
        // permission checks in CheckPermission).
        Role::create(['name' => 'Superadmin', 'permissions' => ['*']]);
        $role   = Role::create(['name' => 'ReadOnly', 'permissions' => []]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]);
        $router = Router::create(['name' => 'Core Norte', 'tenant_id' => $tenant->id, 'status' => 'active']);

        Sanctum::actingAs($user);

        $this->postJson("/api/routers/{$router->id}/outage/notify")->assertForbidden();
        $this->assertSame(0, RouterOutageEvent::count());
    }
}
