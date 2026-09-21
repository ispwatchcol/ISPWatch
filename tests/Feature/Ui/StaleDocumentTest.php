<?php

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KAN-101 · P-46 — que tras un despliegue nadie siga viendo la aplicación
 * anterior.
 *
 * El 2026-09-10, con el arreglo ya desplegado y verificado desde fuera —bundle
 * en vivo con el marcador correcto, /health en verde—, el cliente seguía viendo
 * el formulario roto hasta que limpió la caché a mano. Se perdió tiempo
 * buscando en el código un bug que ya no existía.
 *
 * Los chunks de Vite llevan hash y nunca se sirven rancios; el documento HTML
 * que los referencia, sí. Aquí se fijan las dos mitades del arreglo: que el
 * documento no se cachee, y que el servidor publique una huella del bundle con
 * la que el frontend pueda darse cuenta de que hay código nuevo.
 */
class StaleDocumentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * El bundle no se compila en CI, así que `@vite` no encuentra su
     * manifiesto y el documento del SPA revienta al renderizar. `withoutVite()`
     * sustituye las etiquetas por nada: a estas pruebas les importa la
     * respuesta (código, cabeceras, que sea el HTML del SPA), no qué chunks
     * referencia. En local pasaba sin esto sólo porque había un `public/build`
     * de una compilación anterior — un verde que no se reproducía en CI.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_el_documento_del_spa_no_se_cachea(): void
    {
        $response = $this->get('/dashboard');

        $response->assertOk();

        $cacheControl = $response->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
    }

    /** El portal de pago es otro documento HTML y corre el mismo riesgo. */
    public function test_el_portal_de_pago_tampoco_se_cachea(): void
    {
        $response = $this->get('/portal-pago');

        $response->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    /**
     * Lo que NO debe pasar: que la cabecera se cuele en una respuesta JSON de
     * la API. No haría daño, pero la regla es del documento HTML y conviene que
     * el test diga dónde empieza y dónde acaba.
     */
    public function test_la_api_no_hereda_el_no_store(): void
    {
        $response = $this->actingAs(User::factory()->create())->getJson('/api/system/version');

        $response->assertOk();
        $this->assertStringNotContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * La huella del bundle. Es lo que el frontend compara contra la que vio al
     * cargar: `version` no vale, porque la mayoría de los despliegues no mueven
     * el número de SemVer y el aviso se quedaría mudo justo en los más
     * frecuentes.
     */
    public function test_la_version_publica_la_huella_del_bundle(): void
    {
        $response = $this->actingAs(User::factory()->create())->getJson('/api/system/version');

        $response->assertOk();
        $response->assertJsonStructure(['version', 'released_at', 'build']);
    }
}
