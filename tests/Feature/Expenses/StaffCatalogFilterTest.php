<?php

namespace Tests\Feature\Expenses;

use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Catálogo de personas para el campo "A nombre de quién" de un gasto.
 *
 * El desplegable mezclaba clientes y personal del ISP, así que se podía dejar un
 * gasto a nombre de un cliente. El filtro `?staff=1` deja sólo al personal.
 *
 * El discriminador es la AUSENCIA de `customer_profile` y no la presencia de
 * `staff_profile`: esa tabla está vacía en producción, así que filtrar por ella
 * habría dejado el desplegable sin ninguna opción — peor que el problema
 * original.
 */
class StaffCatalogFilterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $role = Role::create(['name' => 'Admin', 'permissions' => ['*']]);

        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id'   => $role->id,
            'name'      => 'Laura Cajera',
        ]);
    }

    private function customer(string $name): User
    {
        $customer = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name'      => $name,
        ]);

        CustomerProfile::create([
            'user_id'   => $customer->id,
            'name'      => $name,
            'last_name' => 'Cliente',
            'status'    => true,
        ]);

        return $customer;
    }

    #[Test]
    public function con_staff_solo_devuelve_personal_del_isp(): void
    {
        Sanctum::actingAs($this->staff);

        $tecnico = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Andrés Técnico',
        ]);
        $this->customer('Juan Cliente');

        $nombres = collect($this->getJson('/api/catalogs/users?staff=1')->assertStatus(200)->json())
            ->pluck('name');

        $this->assertContains('Laura Cajera', $nombres);
        $this->assertContains('Andrés Técnico', $nombres);
        $this->assertNotContains('Juan Cliente', $nombres, 'Un cliente no puede aparecer como beneficiario de un gasto.');
        $this->assertSame($tecnico->tenant_id, $this->tenant->id);
    }

    #[Test]
    public function sin_staff_el_catalogo_sigue_devolviendo_a_todos(): void
    {
        Sanctum::actingAs($this->staff);

        $this->customer('Juan Cliente');

        // Otros consumidores (inventario) dependen del comportamiento anterior:
        // el filtro es opcional, no un cambio de contrato.
        $nombres = collect($this->getJson('/api/catalogs/users')->assertStatus(200)->json())
            ->pluck('name');

        $this->assertContains('Laura Cajera', $nombres);
        $this->assertContains('Juan Cliente', $nombres);
    }

    #[Test]
    public function nunca_devuelve_personal_de_otro_tenant(): void
    {
        Sanctum::actingAs($this->staff);

        $otroTenant = Tenant::factory()->create();
        User::factory()->create([
            'tenant_id' => $otroTenant->id,
            'name'      => 'Ajeno Staff',
        ]);

        $nombres = collect($this->getJson('/api/catalogs/users?staff=1')->assertStatus(200)->json())
            ->pluck('name');

        $this->assertNotContains('Ajeno Staff', $nombres);
    }
}
