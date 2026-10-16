<?php

namespace Tests\Feature\HelpCenter;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * KAN-102: el Centro de Ayuda dejaba de decir la verdad en cuanto el router
 * RADIUS dejó de exigir IP y credenciales. Producción recibe el texto por la
 * migración 2026_10_05_100000; aquí se fija que lo haga sin pisar lo editado.
 */
class RadiusRouterFieldsHelpMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TITLE = 'RADIUS (AAA): cuando otro sistema gestiona la red';

    private const OLD_PARAGRAPH = '<p>En cambio, el formulario <strong>todavía te va a exigir</strong> nombre, IP, usuario y contraseña del equipo, versión de firmware y estado. Aunque en este modo el sistema nunca los usa, hoy siguen siendo obligatorios para poder guardar. Si el router es sólo un agrupador y no tienes esos datos, puedes poner valores de relleno: no se conectan a ningún lado.</p>';

    private function currentContent(): string
    {
        $radius = require database_path('seeders/content/radius_aaa_articles.php');

        return collect($radius['articles'])->firstWhere('title', self::TITLE)['content'];
    }

    /** El texto tal como lo publicó la migración del 2026-10-01. */
    private function previousContent(): string
    {
        return preg_replace(
            '#<p>Tampoco hacen falta la IP.*?</p>\n<p>Si después cambias el router.*?</p>#s',
            self::OLD_PARAGRAPH,
            $this->currentContent()
        );
    }

    private function runMigration(): void
    {
        (require database_path('migrations/2026_10_05_100000_update_help_center_radius_router_fields.php'))->up();
    }

    #[Test]
    public function la_huella_corresponde_al_texto_anterior(): void
    {
        // Si alguien edita el párrafo nuevo, esta prueba recuerda que la
        // huella de la migración es la del texto VIEJO, no la del nuevo.
        $this->assertStringContainsString('valores de relleno', $this->previousContent());
        $this->assertSame('a6e3c7f4883a8c0c4d7d2529c9ff5c23', md5($this->previousContent()));
    }

    #[Test]
    public function una_base_nueva_ya_no_recomienda_valores_de_relleno(): void
    {
        $content = DB::table('help_articles')->where('title', self::TITLE)->value('content');

        $this->assertNotNull($content);
        $this->assertStringNotContainsString('valores de relleno', $content);
        $this->assertStringContainsString('déjalos vacíos', $content);
    }

    #[Test]
    public function reescribe_el_articulo_que_nadie_toco(): void
    {
        DB::table('help_articles')->where('title', self::TITLE)->update([
            'content' => str_replace("\n", "\r\n", $this->previousContent()),
        ]);

        $this->runMigration();

        $this->assertSame(
            $this->currentContent(),
            DB::table('help_articles')->where('title', self::TITLE)->value('content')
        );
    }

    #[Test]
    public function respeta_lo_que_haya_escrito_un_superadmin(): void
    {
        $editado = $this->previousContent() . '<p>Nota del superadmin.</p>';
        DB::table('help_articles')->where('title', self::TITLE)->update(['content' => $editado]);

        $this->runMigration();

        $this->assertSame($editado, DB::table('help_articles')->where('title', self::TITLE)->value('content'));
    }
}
