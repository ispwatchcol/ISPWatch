<?php

namespace Tests\Feature\Health;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `/health`, el chequeo que faltó.
 *
 * Durante la caída del 2026-08-20 el endpoint de salud (`/up`) devolvió 200 las
 * quince horas que el sistema estuvo inutilizable, porque no toca la base de
 * datos. Nadie recibió una alerta. Estas pruebas fijan las tres propiedades que
 * hacen útil al reemplazo:
 *
 *   1. Reporta el estado por componente, no un sí/no global.
 *   2. Devuelve 503 —no 200— cuando algo está mal, que es lo que un monitor
 *      externo sabe interpretar.
 *   3. Vive fuera de todo middleware, para poder informar sobre la base de
 *      datos sin depender de ella.
 */
class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Por defecto, un planificador sano. Cada prueba que quiera romperlo lo
        // hace explícito.
        $this->latidoReciente();
    }

    #[Test]
    public function reporta_ok_con_todo_sano(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('failing', [])
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.cache.status', 'ok')
            ->assertJsonPath('checks.migrations.status', 'ok')
            ->assertJsonPath('checks.scheduler.status', 'ok');
    }

    #[Test]
    public function informa_la_version_desplegada(): void
    {
        // Permite verificar desde fuera QUÉ quedó desplegado, sin entrar al panel.
        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('version', config('version.number'));
    }

    #[Test]
    public function devuelve_503_cuando_el_planificador_dejo_de_latir(): void
    {
        Cache::put(
            config('health.scheduler.cache_key'),
            now()->subHour()->getTimestamp(),
            3600
        );

        $this->getJson('/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('failing', ['scheduler'])
            ->assertJsonPath('checks.scheduler.status', 'fail');
    }

    #[Test]
    public function devuelve_503_cuando_el_planificador_nunca_ha_latido(): void
    {
        // El modo de fallo real del despliegue actual: el planificador corre de
        // fondo dentro del `worker` (`schedule:work &` antes de `exec queue:work`).
        // Si ese proceso muere, el contenedor sigue vivo porque el principal es la
        // cola, la plataforma lo ve sano, y simplemente deja de ocurrir todo el
        // ciclo automático. No falla nada visible. El chequeo tiene que gritarlo.
        Cache::forget(config('health.scheduler.cache_key'));

        $this->getJson('/health')
            ->assertStatus(503)
            ->assertJsonPath('checks.scheduler.status', 'fail');
    }

    #[Test]
    public function el_planificador_puede_declararse_no_esperado(): void
    {
        config(['health.scheduler.expected' => false]);
        Cache::forget(config('health.scheduler.cache_key'));

        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('checks.scheduler.status', 'skipped');
    }

    #[Test]
    public function el_comando_de_latido_deja_el_planificador_en_ok(): void
    {
        Cache::forget(config('health.scheduler.cache_key'));

        $this->artisan('system:heartbeat')->assertSuccessful();

        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('checks.scheduler.status', 'ok');
    }

    #[Test]
    public function exige_el_token_cuando_esta_configurado(): void
    {
        config(['health.token' => 'un-token-secreto']);

        // 404 y no 403: si está protegido, tampoco conviene confirmar que existe.
        $this->getJson('/health')->assertNotFound();

        $this->getJson('/health?token=incorrecto')->assertNotFound();

        $this->getJson('/health?token=un-token-secreto')->assertOk();

        $this->withHeader('X-Health-Token', 'un-token-secreto')
            ->getJson('/health')
            ->assertOk();
    }

    #[Test]
    public function responde_sin_autenticacion(): void
    {
        // El centinela externo no tiene sesión ni token de Sanctum. Si este
        // endpoint exigiera autenticación, no serviría para nada.
        $this->getJson('/health')->assertOk();
    }

    #[Test]
    public function no_filtra_credenciales_en_la_respuesta(): void
    {
        $respuesta = $this->getJson('/health')->assertOk()->getContent();

        foreach ([config('database.connections.pgsql.password'), 'DB_PASSWORD', 'pooler.supabase.com'] as $secreto) {
            if (blank($secreto)) {
                continue;
            }

            $this->assertStringNotContainsString((string) $secreto, $respuesta);
        }
    }

    #[Test]
    public function el_latido_avisa_a_healthchecks_cuando_hay_url(): void
    {
        config(['health.scheduler.ping_url' => 'https://hc-ping.com/uuid-de-prueba']);
        Http::fake();

        $this->artisan('system:heartbeat')->assertSuccessful();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://hc-ping.com/uuid-de-prueba');
    }

    #[Test]
    public function sin_url_configurada_no_sale_ninguna_peticion(): void
    {
        config(['health.scheduler.ping_url' => null]);
        Http::fake();

        $this->artisan('system:heartbeat')->assertSuccessful();

        Http::assertNothingSent();
    }

    #[Test]
    public function un_fallo_de_red_no_tumba_el_latido(): void
    {
        // Si el ping fallara el comando, el planificador registraría un error cada
        // minuto por cualquier blip de red. El silencio ya lo detecta Healthchecks
        // por su cuenta: no hace falta romper la tarea para enterarse.
        config(['health.scheduler.ping_url' => 'https://hc-ping.com/uuid-de-prueba']);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('sin red'));

        $this->artisan('system:heartbeat')->assertSuccessful();

        // Y el latido local sí quedó escrito, que es lo que de verdad importa.
        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('checks.scheduler.status', 'ok');
    }

    private function latidoReciente(): void
    {
        Cache::put(
            config('health.scheduler.cache_key'),
            now()->getTimestamp(),
            600
        );
    }
}
