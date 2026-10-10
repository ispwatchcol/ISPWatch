<?php

namespace Tests\Feature\ApiKeys;

use App\Mail\ApiKeyExpiringMail;
use App\Models\ApiClient;
use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P-KEYS-1 / KAN-43: api-keys:expiring avisa una sola vez, con una semana de
 * margen, de las llaves vivas que van a vencer.
 */
class NotifyExpiringApiKeysTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['api_keys.self_service.notify_email' => null]);
        $this->tenant = Tenant::factory()->create();
    }

    private function client(array $overrides = []): ApiClient
    {
        return ApiClient::create(array_merge([
            'tenant_id'     => $this->tenant->id,
            'name'          => 'CNO',
            'contact_email' => 'it@cno.test',
            'is_active'     => true,
        ], $overrides));
    }

    private function key(ApiClient $client, ?\DateTimeInterface $expiresAt, array $overrides = []): PersonalAccessToken
    {
        $token = $client->createToken('produccion', ['read:customers'])->accessToken;
        $token->forceFill(array_merge(['expires_at' => $expiresAt, 'allowed_ips' => ['127.0.0.1']], $overrides))->save();

        return $token;
    }

    #[Test]
    public function avisa_al_contacto_de_la_llave_que_vence_dentro_de_la_semana_y_la_marca(): void
    {
        $token = $this->key($this->client(), now()->addDays(5));

        $this->artisan('api-keys:expiring')->assertExitCode(0);

        Mail::assertSent(ApiKeyExpiringMail::class, function (ApiKeyExpiringMail $mail) {
            return $mail->hasTo('it@cno.test')
                && $mail->keyName === 'produccion'
                && $mail->integrationName === 'CNO'
                && $mail->daysLeft === 5;
        });
        $this->assertNotNull($token->fresh()->expiry_notified_at);
    }

    #[Test]
    public function avisa_una_sola_vez_aunque_corra_todos_los_dias(): void
    {
        $this->key($this->client(), now()->addDays(5));

        $this->artisan('api-keys:expiring');
        $this->artisan('api-keys:expiring');

        Mail::assertSentCount(1);
    }

    #[Test]
    public function tambien_avisa_al_operador_sin_duplicar_destinatarios(): void
    {
        config(['api_keys.self_service.notify_email' => 'IT@cno.test']);
        $this->key($this->client(), now()->addDays(3));

        $this->artisan('api-keys:expiring');

        Mail::assertSent(ApiKeyExpiringMail::class, fn ($mail) => count($mail->to) === 1);
    }

    #[Test]
    public function no_avisa_de_lo_que_no_esta_por_vencer_ni_de_lo_que_ya_no_sirve(): void
    {
        $client = $this->client();
        $this->key($client, now()->addDays(30));                                  // lejos
        $this->key($client, null);                                                // sin vencimiento
        $this->key($client, now()->subDay());                                     // ya vencida
        $this->key($client, now()->addDays(2), ['revoked_at' => now()]);          // revocada
        $this->key($this->client(['is_active' => false]), now()->addDays(2));     // cliente desactivado

        $this->artisan('api-keys:expiring');

        Mail::assertNothingSent();
    }

    #[Test]
    public function no_avisa_si_la_integracion_ya_roto_a_una_llave_nueva(): void
    {
        $client = $this->client();
        $vieja  = $this->key($client, now()->addDays(4));
        $this->key($client, now()->addDays(90));

        $this->artisan('api-keys:expiring');

        Mail::assertNothingSent();
        $this->assertNull($vieja->fresh()->expiry_notified_at);
    }

    #[Test]
    public function sin_destinatario_no_marca_para_avisar_cuando_haya_uno(): void
    {
        $token = $this->key($this->client(['contact_email' => null]), now()->addDays(4));

        $this->artisan('api-keys:expiring');

        Mail::assertNothingSent();
        $this->assertNull($token->fresh()->expiry_notified_at);

        config(['api_keys.self_service.notify_email' => 'operador@ispwatch.test']);
        $this->artisan('api-keys:expiring');

        Mail::assertSent(ApiKeyExpiringMail::class, fn ($mail) => $mail->hasTo('operador@ispwatch.test'));
    }

    #[Test]
    public function dry_run_no_envia_ni_marca(): void
    {
        $token = $this->key($this->client(), now()->addDays(4));

        $this->artisan('api-keys:expiring', ['--dry-run' => true])->assertExitCode(0);

        Mail::assertNothingSent();
        $this->assertNull($token->fresh()->expiry_notified_at);
    }

    #[Test]
    public function el_correo_no_lleva_el_token_ni_la_allowlist(): void
    {
        $token = $this->key($this->client(), now()->addDays(4), ['allowed_ips' => ['203.0.113.9']]);

        $this->artisan('api-keys:expiring');

        Mail::assertSent(ApiKeyExpiringMail::class, function (ApiKeyExpiringMail $mail) use ($token) {
            $body = $mail->render();

            return !str_contains($body, '203.0.113.9')
                && !str_contains($body, (string) $token->token)
                && str_contains($body, 'Configuración → Llaves API');
        });
    }

    #[Test]
    public function esta_agendado_todos_los_dias(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'api-keys:expiring'));

        $this->assertNotNull($event);
        $this->assertSame('30 8 * * *', $event->expression);
    }
}
