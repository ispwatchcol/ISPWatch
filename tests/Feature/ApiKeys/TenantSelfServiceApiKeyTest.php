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
 * Auto-servicio de llaves: el ISP emite las suyas desde su panel.
 *
 * Abrir la emisión al ISP cambia quién decide el alcance de una llave: ya no es
 * alguien que administra la plataforma, sino alguien que quiere que su bot
 * funcione. El camino de menor resistencia para esa persona —todos los
 * permisos, `0.0.0.0/0`, sin vencimiento— es exactamente el que hay que cerrar,
 * y estas pruebas son las que lo mantienen cerrado.
 *
 * Se prueban dos familias distintas:
 *   1. Aislamiento: que un ISP no alcance las integraciones de otro. Es la
 *      frontera que no puede fallar nunca.
 *   2. Guardarraíles: que los topes de config/api_keys.php se apliquen de
 *      verdad. Un tope que sólo existe en el formulario no es un tope.
 */
class TenantSelfServiceApiKeyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $ispA;
    private Tenant $ispB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ispA = Tenant::factory()->create();
        $this->ispB = Tenant::factory()->create();

        config([
            'api_keys.self_service.enabled'             => true,
            'api_keys.self_service.abilities'           => ['read:customers', 'read:services'],
            'api_keys.self_service.max_active_keys'     => 2,
            'api_keys.self_service.max_clients'         => 2,
            'api_keys.self_service.max_expiration_days' => 90,
            'api_keys.self_service.min_ipv4_prefix'     => 24,
            'api_keys.self_service.min_ipv6_prefix'     => 64,
        ]);
    }

    private function adminOf(Tenant $tenant, array $permissions = ['manage_own_api_keys']): User
    {
        $role = Role::create([
            'name'        => 'Administrador',
            'code'        => 'admin',
            'permissions' => $permissions,
            'tenant_id'   => $tenant->id,
        ]);

        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id'   => $role->id,
        ]);
    }

    private function clientOf(Tenant $tenant, string $name = 'Integración'): ApiClient
    {
        return ApiClient::create([
            'tenant_id' => $tenant->id,
            'name'      => $name,
            'is_active' => true,
        ]);
    }

    /** Payload válido; cada prueba cambia sólo lo que quiere romper. */
    private function keyPayload(array $overrides = []): array
    {
        return array_merge([
            'name'        => 'produccion-bot',
            'abilities'   => ['read:customers'],
            'allowed_ips' => ['190.24.7.10'],
            'expires_at'  => now()->addDays(30)->toDateString(),
        ], $overrides);
    }

    // ── Camino feliz ───────────────────────────────────────────────────────

    #[Test]
    public function el_isp_emite_su_propia_llave_y_ve_el_texto_plano_una_sola_vez(): void
    {
        Sanctum::actingAs($this->adminOf($this->ispA));

        $client = $this->postJson('/api/my-api-keys/clients', ['name' => 'Bot de WhatsApp'])
            ->assertCreated()
            ->json('data');

        // El tenant sale de la sesión: no hay ningún campo que lo transporte.
        $this->assertSame($this->ispA->id, (int) $client['tenant_id']);

        $key = $this->postJson("/api/my-api-keys/clients/{$client['id']}/keys", $this->keyPayload())
            ->assertCreated()
            ->json('data');

        $this->assertNotEmpty($key['plain_text_token']);

        // El listado nunca vuelve a exponerlo.
        $listado = $this->getJson('/api/my-api-keys')->assertOk()->json();

        $this->assertArrayNotHasKey(
            'plain_text_token',
            $listado['data'][0]['keys'][0],
            'El texto plano de la llave no puede volver a salir después de emitirla.'
        );
    }

    #[Test]
    public function el_listado_expone_los_limites_para_que_el_formulario_avise_antes(): void
    {
        Sanctum::actingAs($this->adminOf($this->ispA));

        $limits = $this->getJson('/api/my-api-keys')->assertOk()->json('limits');

        $this->assertSame(2, $limits['max_active_keys']);
        $this->assertSame(90, $limits['max_expiration_days']);
        $this->assertSame(24, $limits['min_ipv4_prefix']);
    }

    // ── Aislamiento ────────────────────────────────────────────────────────

    #[Test]
    public function un_isp_no_ve_las_integraciones_de_otro(): void
    {
        $this->clientOf($this->ispB, 'Integración del ISP B');

        Sanctum::actingAs($this->adminOf($this->ispA));

        $this->assertSame([], $this->getJson('/api/my-api-keys')->assertOk()->json('data'));
    }

    #[Test]
    public function un_isp_no_puede_emitir_una_llave_sobre_la_integracion_de_otro(): void
    {
        $ajena = $this->clientOf($this->ispB);

        Sanctum::actingAs($this->adminOf($this->ispA));

        // 404 y no 403: un id ajeno tiene que ser indistinguible de uno que no
        // existe, o la diferencia de códigos permite enumerar integraciones.
        $this->postJson("/api/my-api-keys/clients/{$ajena->id}/keys", $this->keyPayload())
            ->assertNotFound();

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    #[Test]
    public function un_isp_no_puede_revocar_ni_auditar_la_llave_de_otro(): void
    {
        $ajena = $this->clientOf($this->ispB);
        $token = $ajena->createToken('la-de-B', ['read:customers']);

        Sanctum::actingAs($this->adminOf($this->ispA));

        $this->deleteJson("/api/my-api-keys/clients/{$ajena->id}/keys/{$token->accessToken->id}")
            ->assertNotFound();

        $this->getJson("/api/my-api-keys/clients/{$ajena->id}/logs")
            ->assertNotFound();

        $this->assertNull(
            PersonalAccessToken::find($token->accessToken->id)->revoked_at,
            'La llave del otro tenant no debía quedar revocada.'
        );
    }

    // ── Guardarraíles ──────────────────────────────────────────────────────

    #[Test]
    public function rechaza_una_allowlist_que_abarque_media_internet(): void
    {
        Sanctum::actingAs($this->adminOf($this->ispA));
        $client = $this->clientOf($this->ispA);

        foreach (['0.0.0.0/0', '10.0.0.0/8', '190.24.0.0/16'] as $rangoAncho) {
            $this->postJson(
                "/api/my-api-keys/clients/{$client->id}/keys",
                $this->keyPayload(['allowed_ips' => [$rangoAncho]])
            )->assertJsonValidationErrors('allowed_ips.0');
        }

        // Una IP suelta y un /24 sí pasan: son los casos legítimos.
        $this->postJson(
            "/api/my-api-keys/clients/{$client->id}/keys",
            $this->keyPayload(['allowed_ips' => ['190.24.7.10', '190.24.8.0/24']])
        )->assertCreated();
    }

    #[Test]
    public function no_se_puede_conceder_un_permiso_fuera_del_subconjunto_de_autoservicio(): void
    {
        Sanctum::actingAs($this->adminOf($this->ispA));
        $client = $this->clientOf($this->ispA);

        // read:billing existe en el catálogo global pero no en el de
        // auto-servicio: esa llave la emite el operador.
        $this->postJson(
            "/api/my-api-keys/clients/{$client->id}/keys",
            $this->keyPayload(['abilities' => ['read:billing']])
        )->assertJsonValidationErrors('abilities.0');
    }

    #[Test]
    public function el_vencimiento_es_obligatorio_y_tiene_techo(): void
    {
        Sanctum::actingAs($this->adminOf($this->ispA));
        $client = $this->clientOf($this->ispA);

        $payload = $this->keyPayload();
        unset($payload['expires_at']);

        $this->postJson("/api/my-api-keys/clients/{$client->id}/keys", $payload)
            ->assertJsonValidationErrors('expires_at');

        $this->postJson(
            "/api/my-api-keys/clients/{$client->id}/keys",
            $this->keyPayload(['expires_at' => now()->addDays(200)->toDateString()])
        )->assertJsonValidationErrors('expires_at');

        $this->postJson(
            "/api/my-api-keys/clients/{$client->id}/keys",
            $this->keyPayload(['expires_at' => now()->subDay()->toDateString()])
        )->assertJsonValidationErrors('expires_at');
    }

    #[Test]
    public function respeta_el_tope_de_llaves_vigentes(): void
    {
        Sanctum::actingAs($this->adminOf($this->ispA));
        $client = $this->clientOf($this->ispA);

        // El tope configurado en setUp() es 2.
        $this->postJson("/api/my-api-keys/clients/{$client->id}/keys", $this->keyPayload(['name' => 'k1']))
            ->assertCreated();
        $this->postJson("/api/my-api-keys/clients/{$client->id}/keys", $this->keyPayload(['name' => 'k2']))
            ->assertCreated();

        $this->postJson("/api/my-api-keys/clients/{$client->id}/keys", $this->keyPayload(['name' => 'k3']))
            ->assertJsonValidationErrors('name');
    }

    #[Test]
    public function una_llave_revocada_libera_cupo(): void
    {
        Sanctum::actingAs($this->adminOf($this->ispA));
        $client = $this->clientOf($this->ispA);

        $primera = $this->postJson("/api/my-api-keys/clients/{$client->id}/keys", $this->keyPayload(['name' => 'k1']))
            ->assertCreated()->json('data');
        $this->postJson("/api/my-api-keys/clients/{$client->id}/keys", $this->keyPayload(['name' => 'k2']))
            ->assertCreated();

        $this->deleteJson("/api/my-api-keys/clients/{$client->id}/keys/{$primera['id']}")->assertOk();

        // Contar las revocadas dejaría al ISP bloqueado por llaves muertas.
        $this->postJson("/api/my-api-keys/clients/{$client->id}/keys", $this->keyPayload(['name' => 'k3']))
            ->assertCreated();
    }

    #[Test]
    public function respeta_el_tope_de_integraciones(): void
    {
        Sanctum::actingAs($this->adminOf($this->ispA));

        $this->postJson('/api/my-api-keys/clients', ['name' => 'una'])->assertCreated();
        $this->postJson('/api/my-api-keys/clients', ['name' => 'dos'])->assertCreated();

        $this->postJson('/api/my-api-keys/clients', ['name' => 'tres'])
            ->assertJsonValidationErrors('name');
    }

    // ── Interruptores ──────────────────────────────────────────────────────

    #[Test]
    public function con_el_autoservicio_apagado_no_se_puede_emitir(): void
    {
        config(['api_keys.self_service.enabled' => false]);

        Sanctum::actingAs($this->adminOf($this->ispA));

        $this->getJson('/api/my-api-keys')->assertForbidden();
        $this->postJson('/api/my-api-keys/clients', ['name' => 'x'])->assertForbidden();
    }

    #[Test]
    public function sin_el_permiso_propio_no_se_alcanza_el_autoservicio(): void
    {
        // `manage_api_keys` es el del operador y NO habilita este camino: son
        // permisos distintos justamente para que no se hereden entre sí.
        Sanctum::actingAs($this->adminOf($this->ispA, ['manage_api_keys']));

        $this->getJson('/api/my-api-keys')->assertForbidden();
    }
}
