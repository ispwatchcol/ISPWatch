<?php

namespace Tests\Feature\HelpCenter;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El manual de la integración AAA actualizado llega a producción sin pisar lo
 * que un superadmin haya editado desde el panel (2026-10-01).
 */
class IntegracionAaaHelpContentTest extends TestCase
{
    use RefreshDatabase;

    private const GUIDE  = 'Guía técnica para integradores AAA: sincronizar sin perder cambios';
    private const RADIUS = 'RADIUS (AAA): cuando otro sistema gestiona la red';

    private function migration(): object
    {
        return require database_path('migrations/2026_10_01_100000_update_help_center_integracion_aaa.php');
    }

    #[Test]
    public function el_despliegue_publica_la_guia_tecnica_una_sola_vez(): void
    {
        // RefreshDatabase ya corrió las migraciones, como al desplegar.
        $this->assertSame(1, DB::table('help_articles')->where('title', self::GUIDE)->count());

        $this->migration()->up();

        $this->assertSame(1, DB::table('help_articles')->where('title', self::GUIDE)->count());
    }

    #[Test]
    public function el_articulo_de_radius_deja_de_decir_que_la_ip_es_obligatoria(): void
    {
        $content = DB::table('help_articles')->where('title', self::RADIUS)->value('content');

        $this->assertStringContainsString('La IP es opcional', $content);
        $this->assertStringNotContainsString('Una IP asignada', $content);
    }

    #[Test]
    public function un_articulo_sin_editar_se_actualiza_y_uno_editado_se_respeta(): void
    {
        $old = $this->previousRadiusContent();
        DB::table('help_articles')->where('title', self::RADIUS)->update(['content' => $old]);

        $this->migration()->up();
        $this->assertStringContainsString('La IP es opcional',
            DB::table('help_articles')->where('title', self::RADIUS)->value('content'));

        // Un superadmin lo reescribió desde el panel: su versión manda.
        DB::table('help_articles')->where('title', self::RADIUS)->update(['content' => '<p>Versión propia del ISP</p>']);

        $this->migration()->up();
        $this->assertSame('<p>Versión propia del ISP</p>',
            DB::table('help_articles')->where('title', self::RADIUS)->value('content'));
    }

    /** El texto exacto que publicó la migración del 2026-09-22. */
    private function previousRadiusContent(): string
    {
        $old = file_get_contents(base_path('tests/Fixtures/help_center/radius_aaa_2026-09-22.html'));

        $this->assertSame('bbb852ec2becf647f548845c94585ab2', md5(str_replace("\r\n", "\n", $old)),
            'El fixture tiene que ser idéntico a lo que hay en producción, o la prueba no prueba nada.');

        return $old;
    }
}
