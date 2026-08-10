<?php

namespace Tests\Feature\ApiKeys;

use App\Models\ApiClient;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Emisión y revocación de llaves desde el panel.
 *
 * El punto central: `manage_api_keys` lo tiene el rol Administrador de TODOS
 * los tenants (se siembra con el set completo), así que el permiso por sí solo
 * no puede ser lo único que separe a un admin de un ISP cualquiera de la
 * capacidad de emitirse llaves — o de emitirlas apuntando al tenant de otro.
 * Ese segundo control es lo que estas pruebas fijan.
 */
class ApiKeyManagementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $operatorTenant;
    private Tenant $ispTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operatorTenant = Tenant::factory()->create();
        $this->ispTenant      = Tenant::factory()->create();

        config(['api_keys.operator_tenant_id' => $this->operatorTenant->id]);
    }

    private function adminOf(Tenant $tenant): User
    {
        $role = Role::create([
            'name'        => 'Administrador',
            'code'        => 'admin',
            'permissions' => ['manage_api_keys'],
            'tenant_id'   => $tenant->id,
        ]);

        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id'   => $role->id,
        ]);
    }

    #[Test]
    public function un_admin_de_otro_tenant_no_puede_administrar_llaves(): void
    {
        Sanctum::actingAs($this->adminOf($this->ispTenant));

        $this->getJson('/api/api-clients')->assertForbidden();

        $this->postJson('/api/api-clients', [
            'tenant_id' => $this->ispTenant->id,
            'name'      => 'Mi propia llave',
        ])->assertForbidden();
    }

    #[Test]
    public function el_operador_emite_una_llave_y_ve_el_texto_plano_una_sola_vez(): void
    {
        Sanctum::actingAs($this->adminOf($this->operatorTenant));

        $client = $this->postJson('/api/api-clients', [
            'tenant_id'     => $this->ispTenant->id,
            'name'          => 'CRM del ISP',
            'contact_email' => 'it@isp.example',
        ])->assertCreated()->json('data');

        $created = $this->postJson("/api/api-clients/{$client['id']}/keys", [
            'name'        => 'produccion',
            'abilities'   => ['read:customers', 'read:billing'],
            'allowed_ips' => ['190.24.7.10', '190.24.8.0/24'],
        ])->assertCreated();

        $plain = $created->json('data.plain_text_token');
        $this->assertNotEmpty($plain);

        // El listado posterior no vuelve a mostrarlo: en la base sólo hay hash.
        $listed = $this->getJson('/api/api-clients')->assertOk();
        $this->assertStringNotContainsString($plain, $listed->getContent());
        $this->assertSame(
            ['read:customers', 'read:billing'],
            $listed->json('data.0.keys.0.abilities')
        );
    }

    #[Test]
    public function la_llave_queda_atada_al_tenant_del_cliente_no_al_del_operador(): void
    {
        Sanctum::actingAs($this->adminOf($this->operatorTenant));

        $client = $this->postJson('/api/api-clients', [
            'tenant_id' => $this->ispTenant->id,
            'name'      => 'CRM del ISP',
        ])->assertCreated()->json('data');

        $this->assertSame(
            $this->ispTenant->id,
            ApiClient::find($client['id'])->tenant_id
        );
    }

    #[Test]
    public function no_se_admiten_abilities_fuera_del_catalogo(): void
    {
        Sanctum::actingAs($this->adminOf($this->operatorTenant));

        $client = ApiClient::create([
            'tenant_id' => $this->ispTenant->id,
            'name'      => 'CRM',
            'is_active' => true,
        ]);

        // '*' es el comodín de Sanctum: aceptarlo daría una llave que pasa
        // cualquier control de abilities futuro sin que nadie lo revise.
        $this->postJson("/api/api-clients/{$client->id}/keys", [
            'name'        => 'k',
            'abilities'   => ['*'],
            'allowed_ips' => ['127.0.0.1'],
        ])->assertStatus(422)->assertJsonValidationErrors('abilities.0');

        $this->postJson("/api/api-clients/{$client->id}/keys", [
            'name'        => 'k',
            'abilities'   => ['write:customers'],
            'allowed_ips' => ['127.0.0.1'],
        ])->assertStatus(422);
    }

    #[Test]
    public function no_se_admite_una_llave_sin_ips(): void
    {
        Sanctum::actingAs($this->adminOf($this->operatorTenant));

        $client = ApiClient::create([
            'tenant_id' => $this->ispTenant->id,
            'name'      => 'CRM',
            'is_active' => true,
        ]);

        $this->postJson("/api/api-clients/{$client->id}/keys", [
            'name'      => 'k',
            'abilities' => ['read:customers'],
        ])->assertStatus(422)->assertJsonValidationErrors('allowed_ips');

        $this->postJson("/api/api-clients/{$client->id}/keys", [
            'name'        => 'k',
            'abilities'   => ['read:customers'],
            'allowed_ips' => ['no-es-una-ip'],
        ])->assertStatus(422)->assertJsonValidationErrors('allowed_ips.0');
    }

    #[Test]
    public function revocar_rompe_el_hash_ademas_de_marcar_la_fecha(): void
    {
        Sanctum::actingAs($this->adminOf($this->operatorTenant));

        $client = ApiClient::create([
            'tenant_id' => $this->ispTenant->id,
            'name'      => 'CRM',
            'is_active' => true,
        ]);

        $issued = $client->createToken('k', ['read:customers']);
        $hashAntes = $issued->accessToken->token;

        $this->deleteJson("/api/api-clients/{$client->id}/keys/{$issued->accessToken->id}")
            ->assertOk();

        $token = PersonalAccessToken::find($issued->accessToken->id);

        $this->assertNotNull($token, 'La fila debe conservarse para la auditoría.');
        $this->assertNotNull($token->revoked_at);
        $this->assertNotSame($hashAntes, $token->token, 'El hash debe invalidarse al revocar.');
    }

    #[Test]
    public function no_se_puede_mover_un_cliente_de_tenant(): void
    {
        Sanctum::actingAs($this->adminOf($this->operatorTenant));

        $client = ApiClient::create([
            'tenant_id' => $this->ispTenant->id,
            'name'      => 'CRM',
            'is_active' => true,
        ]);

        $otro = Tenant::factory()->create();

        $this->putJson("/api/api-clients/{$client->id}", [
            'name'      => 'CRM renombrado',
            'tenant_id' => $otro->id,
        ])->assertOk();

        // Cambiar el tenant redirigiría en silencio todas las llaves vivas
        // hacia los datos de otro ISP: `tenant_id` se ignora a propósito.
        $this->assertSame($this->ispTenant->id, $client->fresh()->tenant_id);
    }
}
