<?php

namespace Tests\Feature\HelpCenter;

use Database\Seeders\HelpCenterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La documentación de RADIUS (AAA) llega por los DOS caminos.
 *
 * Mismo modo de fallo que en `ApiPublicaHelpContentTest`: el Centro de Ayuda se
 * puebla por migración en producción y por seeder en desarrollo, y el que se
 * queda sin el contenido suele ser producción — el único que un ISP lee.
 *
 * Aquí hay además un segundo riesgo propio: el artículo «El método de control
 * del router» ya existía listando CINCO métodos. RADIUS (AAA) estaba en el
 * formulario del router pero no en el manual, así que un cliente preguntó por
 * una opción que la ayuda no mencionaba. Estas pruebas fijan que no vuelva a
 * quedar incompleto.
 */
class RadiusAaaHelpContentTest extends TestCase
{
    use RefreshDatabase;

    private const CATEGORIA = 'Routers y Red';
    private const ARTICULO_METODO = 'El método de control del router';

    /** @return array<string, mixed> */
    private function contenido(): array
    {
        return require database_path('seeders/content/radius_aaa_articles.php');
    }

    private function categoriaId(): ?int
    {
        $id = DB::table('help_categories')->where('name', self::CATEGORIA)->value('id');

        return $id === null ? null : (int) $id;
    }

    #[Test]
    public function la_migracion_publica_el_articulo_de_radius(): void
    {
        // RefreshDatabase ya corrió las migraciones: el artículo debería estar
        // sin hacer nada más, que es lo que ocurre al desplegar.
        $categoriaId = $this->categoriaId();

        $this->assertNotNull($categoriaId, 'No existe la categoría de routers en el Centro de Ayuda.');

        foreach ($this->contenido()['articles'] as $articulo) {
            $this->assertDatabaseHas('help_articles', [
                'category_id'  => $categoriaId,
                'title'        => $articulo['title'],
                'is_published' => true,
            ]);
        }
    }

    #[Test]
    public function el_articulo_del_metodo_de_control_menciona_los_seis_metodos(): void
    {
        $contenido = DB::table('help_articles')
            ->where('category_id', $this->categoriaId())
            ->where('title', self::ARTICULO_METODO)
            ->value('content');

        $this->assertNotNull($contenido, 'Falta el artículo del método de control.');

        foreach (['Simple Queue', 'PCQ', 'HotSpot', 'PPPoE', 'DHCP', 'RADIUS'] as $metodo) {
            $this->assertStringContainsString($metodo, $contenido,
                "El manual del método de control no menciona «{$metodo}». El formulario del router sí lo ofrece.");
        }
    }

    #[Test]
    public function la_correccion_respeta_lo_que_haya_escrito_un_superadmin(): void
    {
        $categoriaId = $this->categoriaId();

        // Un superadmin redactó su propia versión, y ya habla de RADIUS.
        $propio = '<h2>Mi versión</h2><p>Aquí explico RADIUS a mi manera.</p>';

        DB::table('help_articles')
            ->where('category_id', $categoriaId)
            ->where('title', self::ARTICULO_METODO)
            ->update(['content' => $propio]);

        // Volver a correr la migración no debe pisarlo: la condición es
        // «sólo si NO menciona RADIUS».
        $this->migracion()->up();

        $this->assertSame($propio, DB::table('help_articles')
            ->where('category_id', $categoriaId)
            ->where('title', self::ARTICULO_METODO)
            ->value('content'), 'La migración sobrescribió una edición del usuario.');
    }

    #[Test]
    public function volver_a_correr_la_migracion_no_duplica_el_articulo(): void
    {
        $this->migracion()->up();
        $this->migracion()->up();

        foreach ($this->contenido()['articles'] as $articulo) {
            $this->assertSame(1, DB::table('help_articles')
                ->where('category_id', $this->categoriaId())
                ->where('title', $articulo['title'])
                ->count(), "El artículo «{$articulo['title']}» quedó duplicado.");
        }
    }

    #[Test]
    public function el_articulo_de_radius_queda_junto_al_del_metodo_de_control(): void
    {
        $categoriaId = $this->categoriaId();

        $ordenMetodo = DB::table('help_articles')
            ->where('category_id', $categoriaId)
            ->where('title', self::ARTICULO_METODO)
            ->value('display_order');

        $ordenRadius = DB::table('help_articles')
            ->where('category_id', $categoriaId)
            ->where('title', $this->contenido()['articles'][0]['title'])
            ->value('display_order');

        // Quien acaba de leer sobre los seis métodos es justo quien necesita
        // este artículo; enterrado al final del índice no lo encuentra.
        $this->assertSame((int) $ordenMetodo + 1, (int) $ordenRadius,
            'El artículo de RADIUS no quedó inmediatamente después del de método de control.');
    }

    #[Test]
    public function el_seeder_siembra_lo_mismo_sin_duplicar_la_categoria(): void
    {
        // El seeder borra y vuelve a sembrar TODO. Si el archivo compartido no
        // estuviera enganchado, desarrollo se quedaría sin este artículo y
        // nadie se enteraría hasta que alguien lo buscara.
        $this->seed(HelpCenterSeeder::class);

        $this->assertSame(1, DB::table('help_categories')->where('name', self::CATEGORIA)->count());

        $categoriaId = $this->categoriaId();

        foreach ($this->contenido()['articles'] as $articulo) {
            $this->assertSame(1, DB::table('help_articles')
                ->where('category_id', $categoriaId)
                ->where('title', $articulo['title'])
                ->count());
        }

        $contenidoMetodo = DB::table('help_articles')
            ->where('category_id', $categoriaId)
            ->where('title', self::ARTICULO_METODO)
            ->value('content');

        $this->assertStringContainsString('RADIUS', $contenidoMetodo,
            'El seeder volvió a sembrar la lista de métodos incompleta.');
    }

    #[Test]
    public function el_articulo_responde_lo_que_el_operador_necesita_saber(): void
    {
        $articulo = $this->contenido()['articles'][0];

        // Un artículo de ayuda de dos líneas ocupa lugar en el índice y no
        // responde nada.
        $this->assertGreaterThan(400, strlen($articulo['content']));

        // Las preguntas que de verdad llegan por soporte cuando alguien activa
        // este modo. Si alguna deja de estar respondida, la ayuda dejó de
        // servir para lo que se escribió.
        $imprescindibles = [
            'PPPoE',      // qué credenciales necesita el cliente
            'suspendido', // qué pasa con el corte por mora
            'factur',     // qué sigue haciendo ISPWatch
            'vacío',      // qué campos del router puede dejar en blanco
        ];

        foreach ($imprescindibles as $tema) {
            $this->assertStringContainsString($tema, $articulo['content'],
                "El artículo de RADIUS no cubre «{$tema}».");
        }
    }

    /** La migración bajo prueba, instanciada desde su archivo. */
    private function migracion(): object
    {
        return require database_path('migrations/2026_09_22_100000_seed_help_center_radius_aaa.php');
    }
}
