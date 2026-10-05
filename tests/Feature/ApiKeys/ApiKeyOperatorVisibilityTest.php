<?php

namespace Tests\Feature\ApiKeys;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ApiKeyOperator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P-35 / KAN-38: un tenant operador de llaves que no existe no falla, hace
 * desaparecer la emisión centralizada. Esto fija que el problema SE VEA, y que
 * lo vea sólo quien puede arreglarlo.
 */
class ApiKeyOperatorVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        // CheckPermission deja pasar role_id = 1: se ocupa antes.
        Role::create(['name' => 'Administrador', 'permissions' => ['*']]);
        $this->tenant = Tenant::factory()->create();
    }

    private function user(bool $superadmin): User
    {
        $role = Role::create(['name' => 'Rol ' . uniqid(), 'permissions' => ['view_settings']]);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $role->id]);

        // `users.is_superadmin` existe en producción pero no en el esquema de
        // las migraciones (ver BASE_DATOS.md): en pruebas va en memoria.
        $user->setAttribute('is_superadmin', $superadmin);

        return $user;
    }

    #[Test]
    public function un_tenant_operador_inexistente_se_reporta(): void
    {
        config(['api_keys.operator_tenant_id' => 999999]);

        $issue = ApiKeyOperator::configurationIssue();

        $this->assertNotNull($issue);
        $this->assertStringContainsString('999999', $issue);
        $this->assertStringContainsString('API_KEYS_OPERATOR_TENANT_ID', $issue);
    }

    #[Test]
    public function sin_tenant_operador_configurado_tambien_se_reporta(): void
    {
        config(['api_keys.operator_tenant_id' => 0]);

        $this->assertNotNull(ApiKeyOperator::configurationIssue());
    }

    #[Test]
    public function un_tenant_operador_real_no_reporta_nada(): void
    {
        config(['api_keys.operator_tenant_id' => $this->tenant->id]);

        $this->assertNull(ApiKeyOperator::configurationIssue());
    }

    #[Test]
    public function el_superadmin_lo_recibe_en_su_sesion(): void
    {
        config(['api_keys.operator_tenant_id' => 999999]);
        Sanctum::actingAs($this->user(superadmin: true));

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.is_api_key_operator', false)
            ->assertJsonPath('data.api_key_operator_issue', ApiKeyOperator::configurationIssue());
    }

    #[Test]
    public function un_usuario_normal_no_ve_la_configuracion_de_la_plataforma(): void
    {
        config(['api_keys.operator_tenant_id' => 999999]);
        Sanctum::actingAs($this->user(superadmin: false));

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.api_key_operator_issue', null);
    }

    #[Test]
    public function con_el_operador_bien_configurado_el_superadmin_no_recibe_aviso(): void
    {
        config(['api_keys.operator_tenant_id' => $this->tenant->id]);
        Sanctum::actingAs($this->user(superadmin: true));

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.is_api_key_operator', true)
            ->assertJsonPath('data.api_key_operator_issue', null);
    }
}
