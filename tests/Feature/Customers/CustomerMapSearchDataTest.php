<?php

namespace Tests\Feature\Customers;

use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El buscador del Mapa de Clientes (CustomerMap.vue) filtra en el navegador
 * sobre la respuesta de /api/customers/map: no hay endpoint de búsqueda. Eso
 * convierte a este payload en el contrato del buscador — si un campo deja de
 * enviarse, la búsqueda simplemente deja de encontrar por él, en silencio y
 * sólo en producción.
 *
 * Estos tests fijan ese contrato: qué campos viajan, y que el aislamiento por
 * tenant siga intacto ahora que el payload incluye la cédula.
 */
class CustomerMapSearchDataTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $role = Role::create(['name' => 'Admin', 'permissions' => ['*']]);
        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
        ]);
    }

    private function makeCustomer(Tenant $tenant, array $profile = []): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        CustomerProfile::create(array_merge([
            'user_id'   => $user->id,
            'name'      => 'Juan',
            'last_name' => 'Gómez',
            'cedula'    => '1012345678',
            'address'   => 'Calle 3 #4-56',
            'city'      => 'Tocaima',
            'ip_user'   => '10.20.30.40',
            'precinto'  => 'PRE-001',
            'latitude'  => 4.458429,
            'longitude' => -74.636633,
            'status'    => true,
        ], $profile));

        return $user;
    }

    /**
     * Los campos por los que el técnico busca (cédula, IP, precinto) son los
     * que se agregaron para el buscador; el resto ya los dibujaba el mapa.
     */
    public function test_map_payload_includes_the_fields_the_search_box_relies_on(): void
    {
        Sanctum::actingAs($this->admin);
        $customer = $this->makeCustomer($this->tenant);

        $response = $this->getJson('/api/customers/map');

        $response->assertOk();
        $response->assertJsonPath('customers.0.user_id', $customer->id);

        foreach (['cedula', 'ip_user', 'precinto', 'name', 'last_name', 'address', 'city', 'email'] as $field) {
            $this->assertArrayHasKey(
                $field,
                $response->json('customers.0'),
                "El buscador del mapa busca por '{$field}': el endpoint dejó de enviarlo."
            );
        }

        $this->assertSame('1012345678', $response->json('customers.0.cedula'));
        $this->assertSame('10.20.30.40', $response->json('customers.0.ip_user'));
        $this->assertSame('PRE-001', $response->json('customers.0.precinto'));
    }

    /**
     * El payload del mapa ahora lleva cédulas: una fuga entre tenants dejaría
     * de ser "ver un pin ajeno" para ser un dato de identidad ajeno.
     */
    public function test_it_does_not_leak_customers_from_another_tenant(): void
    {
        Sanctum::actingAs($this->admin);

        $this->makeCustomer($this->tenant, ['name' => 'Propio', 'cedula' => '111']);

        $otroTenant = Tenant::factory()->create();
        $this->makeCustomer($otroTenant, ['name' => 'Ajeno', 'cedula' => '999']);

        $response = $this->getJson('/api/customers/map');

        $response->assertOk();
        $cedulas = array_column($response->json('customers'), 'cedula');
        $this->assertContains('111', $cedulas);
        $this->assertNotContains('999', $cedulas);
        $this->assertCount(1, $response->json('customers'));
    }

    /**
     * Un cliente sin coordenadas no se puede "encontrar en el mapa": el
     * endpoint lo excluye, así que el buscador nunca debe ofrecerlo.
     */
    public function test_customers_without_coordinates_are_excluded(): void
    {
        Sanctum::actingAs($this->admin);

        $this->makeCustomer($this->tenant, ['name' => 'Ubicado', 'cedula' => '111']);
        $this->makeCustomer($this->tenant, [
            'name'      => 'SinMapa',
            'cedula'    => '222',
            'latitude'  => null,
            'longitude' => null,
        ]);

        $response = $this->getJson('/api/customers/map');

        $response->assertOk();
        $cedulas = array_column($response->json('customers'), 'cedula');
        $this->assertSame(['111'], $cedulas);
    }
}
