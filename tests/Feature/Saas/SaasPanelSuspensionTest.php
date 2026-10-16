<?php

namespace Tests\Feature\Saas;

use App\Models\ApiClient;
use App\Models\CustomerProfile;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Saas\ConverzaServiceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * KAN-126 — Converza corta el panel de ISPWatch de un ISP que no pagó.
 *
 * Lo que fijan estas pruebas, en orden de importancia:
 *
 *   1. FALLA ABIERTO. Sólo `"suspended": true` corta. Un Converza caído, lento,
 *      mal configurado o con una respuesta rara deja trabajar al ISP.
 *   2. Se corta el PANEL y nada más: la API partner y los clientes finales del
 *      ISP no se enteran.
 *   3. La caché: activo 5 min, suspendido 1 min (un pago reabre rápido), error
 *      1 min (un Converza caído no se pregunta en cada petición).
 */
class SaasPanelSuspensionTest extends TestCase
{
    use RefreshDatabase;

    private const URL   = 'https://converza.test';
    private const TOKEN = 'token-compartido';

    private Tenant $tenant;
    private User $admin;

    /** El 1 lo trata CheckPermission como superadministrador: no se usa. */
    private int $siguienteRolId = 2;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.converza.url'               => self::URL,
            'services.converza.saas_status_token' => self::TOKEN,
        ]);

        $this->tenant = Tenant::factory()->create();
        $this->admin  = $this->userWithRole($this->tenant, 'admin');
    }

    // ─── El corte ────────────────────────────────────────────────────────────

    #[Test]
    public function un_isp_suspendido_recibe_423_con_el_mensaje_y_como_pagar(): void
    {
        $this->fakeConverza(['suspended' => true, 'known' => true, 'reason' => 'billing',
            'since' => '2026-10-10T08:00:00-05:00',
            'message' => 'El servicio está suspendido por una factura vencida. Se reactiva solo al registrar el pago.',
            'payment_instructions' => "Nequi 300 000 0000\nA nombre de Converza"]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/system/version')
            ->assertStatus(423)
            ->assertJsonPath('code', 'service_suspended')
            ->assertJsonPath('message', 'El servicio está suspendido por una factura vencida. Se reactiva solo al registrar el pago.')
            ->assertJsonPath('data.suspended', true)
            ->assertJsonPath('data.reason', 'billing')
            ->assertJsonPath('data.since', '2026-10-10T08:00:00-05:00')
            ->assertJsonPath('data.payment_instructions', "Nequi 300 000 0000\nA nombre de Converza");

        Http::assertSent(fn (HttpRequest $r) =>
            $r->url() === self::URL . '/saas/estado/' . $this->tenant->id
            && $r->hasHeader('Authorization', 'Bearer ' . self::TOKEN));
    }

    #[Test]
    public function tambien_se_cortan_las_escrituras_antes_de_validar_nada(): void
    {
        $this->fakeConverza(['suspended' => true, 'known' => true, 'reason' => 'manual']);
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/customers', [])->assertStatus(423);
    }

    #[Test]
    public function sin_mensaje_de_converza_se_muestra_uno_propio(): void
    {
        $this->fakeConverza(['suspended' => true, 'known' => true, 'reason' => 'cualquiera', 'message' => '  ']);
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/system/version')
            ->assertStatus(423)
            ->assertJsonPath('data.reason', null)
            ->assertJsonPath('data.payment_instructions', null)
            ->assertJsonPath('message', 'El servicio de ISPWatch está suspendido. Comunícate con soporte para reactivarlo.');
    }

    #[Test]
    public function quedan_abiertos_el_estado_y_la_identidad_de_la_sesion(): void
    {
        $this->fakeConverza(['suspended' => true, 'known' => true, 'reason' => 'billing', 'message' => 'Debe']);
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/saas/status')
            ->assertOk()
            ->assertJsonPath('data.suspended', true)
            ->assertJsonPath('data.message', 'Debe');

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.id', $this->admin->id);
    }

    #[Test]
    public function un_isp_al_dia_o_que_no_es_cuenta_de_converza_trabaja_normal(): void
    {
        Http::fakeSequence()
            ->push(['suspended' => false, 'known' => true, 'reason' => null])
            ->push(['suspended' => false, 'known' => false]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/system/version')->assertOk();

        app(ConverzaServiceStatus::class)->forget($this->tenant->id);

        $this->getJson('/api/system/version')->assertOk();
        $this->getJson('/api/saas/status')->assertOk()->assertJsonPath('data.suspended', false);
        Http::assertSentCount(2);
    }

    #[Test]
    public function la_suspension_de_un_isp_no_alcanza_a_otro(): void
    {
        $otro      = Tenant::factory()->create();
        $adminOtro = $this->userWithRole($otro, 'admin');

        Http::fake([
            self::URL . '/saas/estado/' . $this->tenant->id => Http::response(['suspended' => true, 'known' => true]),
            self::URL . '/saas/estado/' . $otro->id        => Http::response(['suspended' => false, 'known' => true]),
        ]);

        Sanctum::actingAs($adminOtro);
        $this->getJson('/api/system/version')->assertOk();

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/system/version')->assertStatus(423);
    }

    // ─── Falla abierto ───────────────────────────────────────────────────────

    #[Test]
    public function converza_caido_o_lento_no_corta(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/system/version')->assertOk();
    }

    public static function erroresDeConverza(): array
    {
        return [
            'token equivocado'       => [401],
            'sin token en Converza'  => [404],
            'limitador'              => [429],
            'error interno'          => [500],
            'mantenimiento'          => [503],
        ];
    }

    #[Test]
    #[DataProvider('erroresDeConverza')]
    public function un_error_http_de_converza_no_corta(int $status): void
    {
        // Aunque el cuerpo diga «suspendido»: un no-2xx no es una respuesta.
        $this->fakeConverza(['suspended' => true], $status);
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/system/version')->assertOk();
        Http::assertSentCount(1);
    }

    public static function respuestasQueNoSeEntienden(): array
    {
        return [
            'sin la clave suspended'  => [['known' => true]],
            'suspended en texto'      => [['suspended' => 'true']],
            'suspended numérico'      => [['suspended' => 1]],
            'no es un objeto'         => ['<html>Bad gateway</html>'],
        ];
    }

    #[Test]
    #[DataProvider('respuestasQueNoSeEntienden')]
    public function una_respuesta_que_no_se_entiende_no_corta(array|string $body): void
    {
        Http::fake(['*' => Http::response($body, 200)]);
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/system/version')->assertOk();
        Http::assertSentCount(1);
    }

    #[Test]
    public function sin_url_o_sin_token_no_se_pregunta_nada(): void
    {
        Http::fake();
        Sanctum::actingAs($this->admin);

        config(['services.converza.url' => null]);
        $this->getJson('/api/system/version')->assertOk();

        config(['services.converza.url' => self::URL, 'services.converza.saas_status_token' => '']);
        $this->getJson('/api/system/version')->assertOk();
        $this->getJson('/api/saas/status')->assertOk()->assertJsonPath('data.suspended', false);

        Http::assertNothingSent();
    }

    // ─── Caché ───────────────────────────────────────────────────────────────

    #[Test]
    public function un_isp_al_dia_se_recuerda_cinco_minutos(): void
    {
        $this->fakeConverza(['suspended' => false, 'known' => true]);
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/system/version')->assertOk();
        $this->travel(299)->seconds();
        $this->getJson('/api/system/version')->assertOk();
        Http::assertSentCount(1);

        $this->travel(2)->seconds();
        $this->getJson('/api/system/version')->assertOk();
        Http::assertSentCount(2);
    }

    #[Test]
    public function al_pagar_el_panel_se_reabre_en_un_minuto(): void
    {
        Http::fakeSequence()
            ->push(['suspended' => true, 'known' => true, 'reason' => 'billing'])
            ->push(['suspended' => false, 'known' => true, 'reason' => null]);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/system/version')->assertStatus(423);
        $this->travel(30)->seconds();
        $this->getJson('/api/saas/status')->assertJsonPath('data.suspended', true);

        $this->travel(31)->seconds();
        $this->getJson('/api/saas/status')->assertJsonPath('data.suspended', false);
        $this->getJson('/api/system/version')->assertOk();
    }

    #[Test]
    public function con_converza_caido_no_se_le_pregunta_en_cada_peticion(): void
    {
        $this->fakeConverza(['message' => 'Server Error'], 500);
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/system/version')->assertOk();
        $this->getJson('/api/system/version')->assertOk();
        $this->getJson('/api/system/version')->assertOk();
        Http::assertSentCount(1);

        $this->travel(61)->seconds();
        $this->getJson('/api/system/version')->assertOk();
        Http::assertSentCount(2);
    }

    // ─── A quién NO se le corta ──────────────────────────────────────────────

    #[Test]
    public function un_cliente_final_del_isp_no_ve_la_suspension(): void
    {
        $this->fakeConverza(['suspended' => true, 'known' => true, 'reason' => 'billing']);

        // Rol `client` de un tenant nuevo.
        Sanctum::actingAs($this->userWithRole($this->tenant, 'client'));
        $this->getJson('/api/saas/status')->assertOk()->assertJsonPath('data.suspended', false);
        $this->getJson('/api/auth/me')->assertOk();

        // Rol global «Cliente» sin código: se reconoce por la ficha de cliente.
        $cliente = $this->userWithRole($this->tenant, null);
        CustomerProfile::create([
            'user_id' => $cliente->id, 'name' => 'Ana', 'last_name' => 'Pérez',
            'address' => 'Calle 1', 'service_status' => 'activo', 'status' => true,
        ]);

        Sanctum::actingAs($cliente);
        $this->getJson('/api/saas/status')->assertOk()->assertJsonPath('data.suspended', false);
    }

    #[Test]
    public function el_superadministrador_de_la_plataforma_no_se_corta(): void
    {
        Http::fake();

        // `users.is_superadmin` no la crea ninguna migración (vive en la base de
        // producción): se fija en memoria sobre la instancia autenticada.
        $this->admin->forceFill(['is_superadmin' => true]);
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/saas/status')->assertOk()->assertJsonPath('data.suspended', false);
        Http::assertNothingSent();
    }

    #[Test]
    public function la_api_partner_del_isp_suspendido_sigue_respondiendo(): void
    {
        Http::fake(['*' => Http::response(['suspended' => true, 'known' => true])]);

        $client = ApiClient::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'CRM del ISP',
            'is_active' => true,
        ]);
        $token = $client->createToken('test', ['read:customers']);
        $token->accessToken->forceFill(['allowed_ips' => ['127.0.0.1']])->save();

        $this->getJson('/api/v1/partner/ping', ['Authorization' => 'Bearer ' . $token->plainTextToken])
            ->assertOk()
            ->assertJsonPath('data.tenant_id', $this->tenant->id);

        Http::assertNothingSent();
    }

    /**
     * La barrera está en el grupo y no ruta por ruta para que un endpoint nuevo
     * del panel no se quede sin ella. Esto lo fija: toda ruta del panel la lleva,
     * salvo las dos excepciones declaradas, y ninguna de la API partner.
     */
    #[Test]
    public function toda_ruta_del_panel_lleva_el_corte_y_ninguna_de_la_api_partner(): void
    {
        $abiertas = ['api/auth/me', 'api/saas/status'];
        $panel = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            // middleware() y no gatherMiddleware(): la segunda instancia cada
            // controlador para leer su middleware propio, y aquí sólo importa
            // el de grupo y ruta, que es donde vive el corte.
            $declarado = $route->middleware();
            $efectivo  = array_diff($declarado, $route->excludedMiddleware());

            if (str_starts_with($route->uri(), 'api/v1/partner')) {
                $this->assertNotContains('saas_active', $efectivo, "La API partner no se corta: {$route->uri()}");
                continue;
            }

            if (! in_array('deny_api_clients', $declarado, true)) {
                continue;
            }

            $panel++;

            if (in_array($route->uri(), $abiertas, true)) {
                $this->assertNotContains('saas_active', $efectivo, "Debe quedar abierta: {$route->uri()}");
            } else {
                $this->assertContains('saas_active', $efectivo, "Ruta del panel sin corte: {$route->uri()}");
            }
        }

        $this->assertGreaterThan(100, $panel, 'No se encontraron las rutas del panel.');
    }

    // ─── Ayudantes ───────────────────────────────────────────────────────────

    private function fakeConverza(array $body, int $status = 200): void
    {
        Http::fake([self::URL . '/saas/estado/*' => Http::response($body, $status)]);
    }

    private function userWithRole(Tenant $tenant, ?string $code): User
    {
        $role = new Role([
            'name'        => 'Rol ' . uniqid(),
            'code'        => $code,
            'tenant_id'   => $code === null ? null : $tenant->id,
            'permissions' => [],
        ]);
        $role->id = $this->siguienteRolId++;
        $role->save();

        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id'   => $role->id,
            'user_name' => 'Usuario',
            'status'    => true,
        ]);
    }
}
