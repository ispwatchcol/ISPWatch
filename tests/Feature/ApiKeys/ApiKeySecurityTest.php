<?php

namespace Tests\Feature\ApiKeys;

use App\Models\ApiClient;
use App\Models\ApiKeyRequestLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Controles de acceso de la API pública: allowlist de IPs, abilities,
 * revocación, solo-lectura y la separación con las rutas del panel.
 *
 * Cada caso corresponde a una forma concreta en que una llave podría
 * convertirse en un problema: que se filtre (allowlist), que se use para más
 * de lo pactado (abilities), que siga viva tras terminar el contrato
 * (revocación) o que sirva para entrar al panel (DenyApiClients).
 */
class ApiKeySecurityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private ApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->client = ApiClient::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Integrador',
            'is_active' => true,
        ]);
    }

    private function issueKey(array $abilities = ['read:customers'], array $ips = ['127.0.0.1']): string
    {
        $token = $this->client->createToken('test', $abilities);
        $token->accessToken->forceFill(['allowed_ips' => $ips])->save();

        return $token->plainTextToken;
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    #[Test]
    public function una_llave_valida_puede_verificarse_con_ping(): void
    {
        $response = $this->getJson('/api/v1/partner/ping', $this->bearer($this->issueKey()));

        $response->assertOk()
            ->assertJsonPath('data.tenant_id', $this->tenant->id)
            ->assertJsonPath('data.client', 'Integrador');
    }

    #[Test]
    public function una_ip_fuera_de_la_allowlist_es_rechazada(): void
    {
        $token = $this->issueKey(['read:customers'], ['10.20.30.40']);

        $this->getJson('/api/v1/partner/customers', $this->bearer($token))
            ->assertForbidden()
            ->assertJsonPath('error', 'ip_not_allowed');
    }

    #[Test]
    public function un_rango_cidr_que_contiene_la_ip_de_origen_es_aceptado(): void
    {
        $token = $this->issueKey(['read:customers'], ['127.0.0.0/24']);

        $this->getJson('/api/v1/partner/customers', $this->bearer($token))->assertOk();
    }

    #[Test]
    public function una_llave_sin_ips_registradas_no_sirve_para_nada(): void
    {
        // Falla cerrado: una allowlist vacía no es "sin restricción", es una
        // llave inutilizable. Un error de configuración no puede terminar en
        // una llave abierta al mundo.
        $token = $this->issueKey(['read:customers'], []);

        $this->getJson('/api/v1/partner/customers', $this->bearer($token))
            ->assertForbidden()
            ->assertJsonPath('error', 'ip_not_allowed');
    }

    #[Test]
    public function una_llave_sin_la_ability_no_alcanza_el_endpoint(): void
    {
        $token = $this->issueKey(['read:customers']);

        $this->getJson('/api/v1/partner/invoices', $this->bearer($token))->assertForbidden();
        $this->getJson('/api/v1/partner/tickets', $this->bearer($token))->assertForbidden();
        $this->getJson('/api/v1/partner/customers', $this->bearer($token))->assertOk();
    }

    #[Test]
    public function una_llave_revocada_deja_de_autenticar(): void
    {
        $token = $this->issueKey();
        $this->getJson('/api/v1/partner/customers', $this->bearer($token))->assertOk();

        // Sólo se marca la fecha, sin tocar el hash: así se comprueba que el
        // middleware honra `revoked_at` por sí mismo. Que el endpoint de
        // revocación además rompa el hash se prueba en ApiKeyManagementTest.
        $this->client->tokens()->update(['revoked_at' => now()]);

        // En un test todas las peticiones comparten la misma instancia de la
        // aplicación, y el RequestGuard cachea el usuario ya resuelto. En
        // producción cada petición arranca limpia; aquí hay que forzarlo o se
        // reutilizaría el token cargado ANTES de revocarlo.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/partner/customers', $this->bearer($token))
            ->assertUnauthorized()
            ->assertJsonPath('error', 'key_revoked');
    }

    #[Test]
    public function una_llave_vencida_deja_de_autenticar(): void
    {
        $token = $this->client->createToken('vencida', ['read:customers']);
        $token->accessToken->forceFill([
            'allowed_ips' => ['127.0.0.1'],
            'expires_at'  => now()->subDay(),
        ])->save();

        $this->getJson('/api/v1/partner/customers', $this->bearer($token->plainTextToken))
            ->assertUnauthorized();
    }

    #[Test]
    public function desactivar_el_cliente_apaga_todas_sus_llaves(): void
    {
        $token = $this->issueKey();
        $this->client->update(['is_active' => false]);

        $this->getJson('/api/v1/partner/customers', $this->bearer($token))
            ->assertForbidden()
            ->assertJsonPath('error', 'client_disabled');
    }

    #[Test]
    public function la_api_publica_no_admite_escrituras(): void
    {
        $token = $this->issueKey();

        $this->postJson('/api/v1/partner/customers', [], $this->bearer($token))
            ->assertStatus(405);
    }

    #[Test]
    public function una_llave_de_api_no_sirve_para_las_rutas_del_panel(): void
    {
        $token = $this->issueKey();

        // 401 y no 403: el rechazo llega del propio guard `sanctum`, cuyo
        // provider es `users`, así que un token de ApiClient ni siquiera
        // autentica — no hace falta que DenyApiClients llegue a opinar. Esa
        // barrera es estructural (config/auth.php) y no depende de recordar
        // ningún middleware al agregar rutas nuevas al panel.
        $this->getJson('/api/customers', $this->bearer($token))->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/dashboard/stats', $this->bearer($token))->assertUnauthorized();
    }

    #[Test]
    public function una_sesion_del_panel_no_sirve_para_la_api_publica(): void
    {
        // La dirección contraria: un usuario del panel autenticado no alcanza
        // la API pública, porque el guard `api_key` sólo admite ApiClient.
        Sanctum::actingAs($this->operatorAdmin());

        $this->getJson('/api/v1/partner/customers')->assertUnauthorized();
    }

    #[Test]
    public function cada_peticion_queda_en_la_bitacora(): void
    {
        $token = $this->issueKey();

        $this->getJson('/api/v1/partner/customers', $this->bearer($token))->assertOk();
        $this->getJson('/api/v1/partner/invoices', $this->bearer($token))->assertForbidden();

        $logs = ApiKeyRequestLog::orderBy('id')->get();

        $this->assertCount(2, $logs);
        $this->assertSame(200, $logs[0]->status_code);
        $this->assertSame('api/v1/partner/customers', $logs[0]->path);
        $this->assertSame($this->tenant->id, $logs[0]->tenant_id);
        $this->assertSame(403, $logs[1]->status_code);
        $this->assertSame('missing_ability', $logs[1]->denied_reason);
    }

    /**
     * Administrador del tenant operador.
     *
     * El id del tenant operador se apunta al recién creado en vez de forzar el
     * id 1: en la base de pruebas el 1 ya lo ocupa el tenant del setUp, y fijar
     * ids a mano hace que el test dependa del orden de creación.
     */
    private function operatorAdmin(): User
    {
        $operatorTenant = Tenant::factory()->create();
        config(['api_keys.operator_tenant_id' => $operatorTenant->id]);

        $role = Role::create([
            'name'        => 'Administrador',
            'code'        => 'admin',
            'permissions' => ['manage_api_keys'],
            'tenant_id'   => $operatorTenant->id,
        ]);

        return User::factory()->create([
            'tenant_id' => $operatorTenant->id,
            'role_id'   => $role->id,
        ]);
    }
}
