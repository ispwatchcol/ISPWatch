<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La versión que dice el producto tiene que ser la que está publicada.
 *
 * POR QUÉ ESTE TEST
 * -----------------
 * El modo de fallo del versionado no es fallar: es **quedarse quieto**. Alguien
 * publica, se olvida de subir el número, y durante meses todo el mundo cree que
 * corre una versión que no corre. Eso ya pasó aquí: el panel mostró `v1.0.0`
 * escrito a mano desde mayo de 2026, con 395 commits encima y con el único tag
 * de git diciendo `v1.0.0-beta`.
 *
 * Un número de versión en el que nadie confía es peor que no tener número: la
 * primera pregunta de cualquier diagnóstico —«¿qué versión tienes?»— deja de
 * servir para nada.
 *
 * Estas pruebas no verifican que la versión sea "la correcta" (nadie puede saber
 * eso desde el código). Verifican que las tres cosas que se mueven juntas al
 * publicar —`config/version.php`, `CHANGELOG.md` y lo que responde la API— no se
 * hayan separado.
 */
class VersionConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function changelog(): string
    {
        $ruta = base_path('CHANGELOG.md');

        $this->assertFileExists($ruta, 'Falta el CHANGELOG.');

        return file_get_contents($ruta);
    }

    /** Primera entrada publicada del CHANGELOG: [versión, fecha]. */
    private function ultimaEntrada(): array
    {
        preg_match('/^## \[(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)\] — (\d{4}-\d{2}-\d{2})$/m',
            $this->changelog(), $m);

        $this->assertNotEmpty($m,
            'El CHANGELOG no tiene ninguna entrada con el formato «## [1.2.3] — 2026-08-19».');

        return [$m[1], $m[2]];
    }

    #[Test]
    public function la_version_configurada_es_semver_valida(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/',
            (string) config('version.number'),
            'La versión debe seguir SemVer (MAYOR.MENOR.PARCHE).'
        );
    }

    #[Test]
    public function el_changelog_encabeza_con_la_version_configurada(): void
    {
        [$version, $fecha] = $this->ultimaEntrada();

        $this->assertSame(config('version.number'), $version,
            'La primera entrada del CHANGELOG no es la versión configurada. Al publicar, '
            . '`config/version.php` y `CHANGELOG.md` se mueven juntos.');

        $this->assertSame(config('version.released_at'), $fecha,
            'La fecha de publicación no coincide con la del CHANGELOG. Es la que ve el '
            . 'usuario en Configuración → Sistema como «última actualización».');
    }

    #[Test]
    public function la_api_devuelve_la_version_a_cualquier_usuario_autenticado(): void
    {
        // Sin permisos: quien llama a soporte tiene que poder responder qué
        // versión le aparece, y no siempre es un administrador.
        $user = \App\Models\User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/system/version');

        $response->assertOk()->assertJson([
            'version'     => config('version.number'),
            'released_at' => config('version.released_at'),
        ]);
    }

    #[Test]
    public function la_version_no_se_sirve_sin_sesion(): void
    {
        $this->getJson('/api/system/version')->assertUnauthorized();
    }

    #[Test]
    public function el_frontend_no_trae_la_version_escrita_a_mano(): void
    {
        // La regresión concreta que este archivo existe para impedir: que
        // alguien vuelva a teclear el número en la plantilla. Un valor escrito
        // ahí gana sobre el del servidor y nadie lo nota, porque *parece* bien.
        $vue = file_get_contents(resource_path('js/pages/Settings.vue'));

        $this->assertDoesNotMatchRegularExpression(
            '/>\s*v\d+\.\d+\.\d+\s*</',
            $vue,
            'Hay una versión escrita a mano en Settings.vue. Debe venir de /api/system/version.'
        );
    }
}
