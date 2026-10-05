<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P-33 / KAN-68: el tile «Estado del Sistema» de Configuración refleja el
 * latido real del planificador en vez de decir «Operativo» con texto fijo.
 */
class SystemStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['health.scheduler.expected' => true, 'health.scheduler.max_silence_seconds' => 300]);
        Cache::forget(config('health.scheduler.cache_key'));
    }

    private function getStatus(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs(User::factory()->create())->getJson('/api/system/status');
    }

    #[Test]
    public function con_latido_reciente_el_estado_es_ok(): void
    {
        Cache::put(config('health.scheduler.cache_key'), now()->subSeconds(40)->getTimestamp());

        $this->getStatus()->assertOk()
            ->assertJsonPath('scheduler.status', 'ok')
            ->assertJsonPath('scheduler.max_silence_seconds', 300);
    }

    #[Test]
    public function con_el_planificador_callado_el_estado_es_stale_y_dice_cuanto(): void
    {
        Cache::put(config('health.scheduler.cache_key'), now()->subHours(3)->getTimestamp());

        $response = $this->getStatus()->assertOk()->assertJsonPath('scheduler.status', 'stale');

        $this->assertGreaterThanOrEqual(3 * 3600, $response->json('scheduler.last_run_seconds_ago'));
    }

    #[Test]
    public function si_nunca_latio_no_dice_operativo(): void
    {
        $this->getStatus()->assertOk()->assertJsonPath('scheduler.status', 'never');
    }

    #[Test]
    public function donde_no_se_espera_planificador_lo_dice(): void
    {
        config(['health.scheduler.expected' => false]);

        $this->getStatus()->assertOk()->assertJsonPath('scheduler.status', 'not_expected');
    }

    #[Test]
    public function exige_sesion(): void
    {
        $this->getJson('/api/system/status')->assertUnauthorized();
    }

    #[Test]
    public function el_tile_ya_no_tiene_operativo_escrito_a_mano(): void
    {
        // El «Operativo» que queda en Settings.vue tiene que salir del estado,
        // no estar fijo en la plantilla junto al punto verde.
        $vue = file_get_contents(resource_path('js/pages/Settings.vue'));

        $this->assertStringContainsString('/system/status', $vue);
        $this->assertStringNotContainsString(
            "bg-green-500 rounded-full animate-pulse\"\n                                    ></span>\n                                    Operativo",
            $vue
        );
    }
}
