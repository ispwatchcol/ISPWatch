<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KAN-41 (P-30) y KAN-97 (P-41) — bajo `api/*` la respuesta es JSON, mande o no
 * el cliente su cabecera `Accept`.
 *
 * Los dos defectos son el mismo error visto desde dos lados: la API contestaba
 * como si fuera una página web a quien no pidió una página web.
 *
 *  · Sin credenciales válidas, `redirectGuestsTo('/')` devolvía un 302 al panel.
 *    El integrador que sigue el redirect recibe un 200 con HTML de login y cree
 *    que su llave sirve.
 *  · Una URL `/api/...` inexistente caía en el catch-all del SPA y devolvía 200
 *    con la aplicación entera. Un 200 con HTML es la peor respuesta posible a
 *    «este endpoint no existe»: el cliente da la petición por buena.
 *
 * Ninguna petición de este archivo manda `Accept: application/json` a
 * propósito: `$this->get()` no la añade —`getJson()` sí—, así que reproduce
 * exactamente a un curl o a un Postman recién abierto.
 */
class ApiJsonContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_ruta_de_api_inexistente_responde_404_json(): void
    {
        $response = $this->get('/api/esto-no-existe');

        $response->assertStatus(404);
        $response->assertHeader('content-type', 'application/json');
        $response->assertJsonPath('success', false);
    }

    /** `Route::fallback()` sólo cubre GET; los demás verbos van por el handler. */
    public function test_los_demas_verbos_tambien_responden_404_json(): void
    {
        $response = $this->post('/api/esto-tampoco-existe');

        $response->assertStatus(404);
        $response->assertJson(['success' => false]);
    }

    public function test_una_ruta_protegida_sin_sesion_responde_401_y_no_302(): void
    {
        $response = $this->get('/api/customers');

        $response->assertStatus(401);
        $response->assertJsonPath('success', false);
    }

    /**
     * La API pública conserva su propio sobre {error, message}: es el mismo que
     * usa EnsureApiKeyRequest para los demás rechazos, y el integrador no tiene
     * por qué distinguir dos formatos según qué capa lo rechazó.
     */
    public function test_la_api_partner_sin_llave_responde_401_con_su_propio_sobre(): void
    {
        $response = $this->get('/api/v1/partner/ping');

        $response->assertStatus(401);
        $response->assertJsonPath('error', 'invalid_credentials');
    }

    public function test_una_ruta_inexistente_de_la_api_partner_usa_el_sobre_partner(): void
    {
        $response = $this->get('/api/v1/partner/no-existe');

        // 404 y no 401: el fallback no lleva la cadena `auth:api_key` —no
        // pertenece al grupo—, así que una URL que no existe se responde antes
        // de mirar la llave. Se deja así a propósito: lo único que revela es
        // que esa ruta no existe, y a cambio el integrador con la URL mal
        // escrita recibe la respuesta que describe su problema en vez de un
        // «credenciales no válidas» que le haría revisar la llave correcta.
        $response->assertStatus(404);
        $response->assertJsonStructure(['error', 'message']);
    }

    /**
     * La otra mitad del fallback: si la URL existe y lo que falla es el verbo,
     * la respuesta es 405 con `Allow` y no 404. La API pública es de solo
     * lectura y ese 405 es precisamente cómo lo comunica.
     */
    public function test_un_verbo_equivocado_responde_405_con_allow(): void
    {
        $response = $this->post('/api/v1/partner/ping');

        $response->assertStatus(405);
        $response->assertHeader('Allow');
        $response->assertJsonStructure(['error', 'message']);
    }

    /** El SPA sigue sirviéndose: la exclusión del catch-all es sólo para `api`. */
    public function test_el_catch_all_del_spa_sigue_devolviendo_la_aplicacion(): void
    {
        $response = $this->get('/dashboard');

        $response->assertStatus(200);
        $response->assertSee('<div id="app">', false);
    }

    /** Y una ruta del SPA que sólo empieza por «api» no se rompe. */
    public function test_una_ruta_del_spa_que_empieza_por_api_sigue_funcionando(): void
    {
        $response = $this->get('/apiclientes');

        $response->assertStatus(200);
        $response->assertSee('<div id="app">', false);
    }
}
