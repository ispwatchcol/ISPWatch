<?php

namespace Tests\Feature\ApiKeys;

use App\Http\Controllers\Api\Partner\PartnerMetaController;
use App\Models\ApiClient;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El contrato OpenAPI tiene que describir la API que existe de verdad.
 *
 * POR QUÉ ESTE TEST
 * -----------------
 * Un archivo de especificación es documentación que el integrador mete en su
 * cadena de compilación: le genera el cliente, le valida las respuestas y le
 * arma las pruebas. Cuando se desincroniza del código no produce un error
 * nuestro — produce un error suyo, en su entorno, semanas después, y lo
 * primero que se pone en duda es su implementación y no nuestro archivo.
 *
 * Por eso la especificación no puede depender de que alguien se acuerde de
 * actualizarla: agregar, quitar o cambiarle el permiso a una ruta del grupo
 * `v1/partner` rompe esta prueba en el CI, en el mismo PR.
 *
 * POR QUÉ NO SE PARSEA EL YAML COMPLETO
 * --------------------------------------
 * El proyecto no trae `symfony/yaml`, y sumar una dependencia para leer un
 * archivo en las pruebas es peor negocio que leerlo de forma acotada. Lo que
 * este test necesita saber es sólo qué rutas declara y con qué permiso, y eso
 * son dos formas de línea perfectamente reconocibles. El test es honesto sobre
 * su alcance: verifica la CORRESPONDENCIA ruta ↔ contrato, no la validez del
 * esquema entero.
 */
class PartnerOpenApiContractTest extends TestCase
{
    use RefreshDatabase;

    /** Rutas del grupo partner => ability declarada (null si no exige ninguna). */
    private function routeAbilities(): array
    {
        $map = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (!str_starts_with($uri, 'api/v1/partner/')) {
                continue;
            }

            // La ruta del contrato usa `{customer}` / `{service}`; la
            // especificación usa `{id}`, que es lo que lee un humano. Se
            // normalizan para poder compararlas.
            $path = preg_replace('/\{[^}]+\}/', '{id}', substr($uri, strlen('api/v1/partner')));

            $ability = null;

            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'ability:')) {
                    $ability = substr($middleware, strlen('ability:'));
                }
            }

            $map[$path] = $ability;
        }

        return $map;
    }

    /** Rutas declaradas en el YAML => valor de `x-ability` (null si no lo trae). */
    private function specAbilities(): array
    {
        $file = base_path(PartnerMetaController::SPEC_PATH);

        $this->assertFileExists($file, 'Falta el contrato OpenAPI de la API partner.');

        $map     = [];
        $current = null;

        foreach (file($file) as $line) {
            $line = rtrim($line, "\r\n");

            // Clave de ruta: exactamente dos espacios de sangría y empieza por
            // «/». Con más sangría ya es contenido de la operación.
            if (preg_match('#^  (/\S*):$#', $line, $m)) {
                $current      = $m[1];
                $map[$current] = null;

                continue;
            }

            if ($current !== null && preg_match('/^\s+x-ability:\s*(\S+)\s*$/', $line, $m)) {
                $map[$current] = $m[1];
            }
        }

        return $map;
    }

    #[Test]
    public function el_contrato_declara_exactamente_las_rutas_que_existen(): void
    {
        $rutas = array_keys($this->routeAbilities());
        $spec  = array_keys($this->specAbilities());

        sort($rutas);
        sort($spec);

        $sinDocumentar = array_diff($rutas, $spec);
        $fantasma      = array_diff($spec, $rutas);

        $this->assertSame([], array_values($sinDocumentar),
            'Hay rutas de la API partner que el contrato OpenAPI no describe. '
            . 'El integrador no puede consumir lo que no está en el archivo.');

        $this->assertSame([], array_values($fantasma),
            'El contrato OpenAPI describe rutas que ya no existen. Prometerle a '
            . 'un integrador un endpoint que responde 404 es peor que no ofrecerlo.');
    }

    #[Test]
    public function cada_ruta_declara_en_el_contrato_el_mismo_permiso_que_exige_el_codigo(): void
    {
        $rutas = $this->routeAbilities();
        $spec  = $this->specAbilities();

        foreach ($rutas as $path => $ability) {
            $this->assertArrayHasKey($path, $spec, "«{$path}» no está en el contrato.");

            $this->assertSame(
                $ability,
                $spec[$path],
                "El permiso de «{$path}» no coincide: el código exige "
                . var_export($ability, true) . ' y el contrato anuncia '
                . var_export($spec[$path], true) . '. Un integrador pediría el '
                . 'permiso equivocado y recibiría 403 sin entender por qué.'
            );
        }
    }

    #[Test]
    public function el_contrato_apunta_al_host_de_produccion_y_no_a_uno_de_desarrollo(): void
    {
        $yaml = file_get_contents(base_path(PartnerMetaController::SPEC_PATH));

        // El `servers` es lo primero que copia un integrador. Un `localhost`
        // filtrado ahí le hace perder la tarde antes de la primera llamada.
        $this->assertMatchesRegularExpression(
            '#^\s+- url: https://#m',
            $yaml,
            'El servidor del contrato debe ser HTTPS y absoluto.'
        );

        $this->assertStringNotContainsString('localhost', $yaml,
            'El contrato que se entrega al integrador no puede mencionar localhost.');
    }

    #[Test]
    public function la_api_sirve_su_propio_contrato_a_una_llave_valida(): void
    {
        $tenant = Tenant::factory()->create();

        $client = ApiClient::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Integrador',
            'is_active' => true,
        ]);

        // Sin ningún ability: el contrato se sirve igual, como el ping.
        $token = $client->createToken('test', []);
        $token->accessToken->forceFill(['allowed_ips' => ['127.0.0.1']])->save();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token->plainTextToken])
            ->get('/api/v1/partner/openapi.yaml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/yaml; charset=UTF-8');

        $this->assertStringContainsString('openapi: 3.0.3', $response->getContent());
    }

    #[Test]
    public function el_contrato_no_se_sirve_sin_llave(): void
    {
        // `getJson` y no `get`: la app redirige a `/` a los invitados que no
        // piden JSON (`redirectGuestsTo` en bootstrap/app.php), así que sin la
        // cabecera `Accept` esto devuelve 302 y no 401. Es el comportamiento
        // de TODA la API partner, no de esta ruta, y está anotado como
        // papercut en MEJORAS_RECOMENDADAS.md; lo que este test protege es que
        // el contrato no salga sin llave, y eso se cumple en ambos casos.
        $this->getJson('/api/v1/partner/openapi.yaml')->assertUnauthorized();
    }
}
