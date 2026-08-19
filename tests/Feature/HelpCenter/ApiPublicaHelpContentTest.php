<?php

namespace Tests\Feature\HelpCenter;

use Database\Seeders\HelpCenterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Los artículos de la API pública llegan por los DOS caminos.
 *
 * El Centro de Ayuda se puebla de dos formas incompatibles entre sí:
 *
 *  - en producción, por migración (los seeders nunca corren allí);
 *  - en desarrollo, por `HelpCenterSeeder`, que BORRA todo y vuelve a sembrar.
 *
 * El modo de fallo real es que alguien agregue contenido por uno solo de los
 * dos y quede un entorno con el artículo y otro sin él — normalmente el que
 * falta es producción, que es el único que un ISP lee. Estas pruebas fijan que
 * el archivo compartido alimente los dos caminos.
 */
class ApiPublicaHelpContentTest extends TestCase
{
    use RefreshDatabase;

    private const CATEGORIA = 'Integraciones y API';

    /** @return array<string, mixed> */
    private function contenido(): array
    {
        return require database_path('seeders/content/api_publica_articles.php');
    }

    #[Test]
    public function la_migracion_deja_la_categoria_y_sus_articulos(): void
    {
        // RefreshDatabase ya corrió las migraciones: los artículos deberían
        // estar sin hacer nada más, que es exactamente lo que pasa al desplegar.
        $categoriaId = DB::table('help_categories')->where('name', self::CATEGORIA)->value('id');

        $this->assertNotNull($categoriaId, 'La migración no creó la categoría de integraciones.');

        foreach ($this->contenido()['articles'] as $articulo) {
            $this->assertDatabaseHas('help_articles', [
                'category_id'  => $categoriaId,
                'title'        => $articulo['title'],
                'is_published' => true,
            ]);
        }
    }

    #[Test]
    public function el_seeder_siembra_los_mismos_articulos_sin_duplicarlos(): void
    {
        // El seeder borra y vuelve a sembrar TODO. Si el archivo compartido no
        // estuviera enganchado, este paso dejaría el Centro de Ayuda sin la
        // categoría de integraciones y nadie se enteraría hasta que un ISP la
        // buscara en desarrollo.
        $this->seed(HelpCenterSeeder::class);

        $categorias = DB::table('help_categories')->where('name', self::CATEGORIA)->count();

        $this->assertSame(1, $categorias, 'La categoría de integraciones quedó duplicada o desapareció.');

        $categoriaId = DB::table('help_categories')->where('name', self::CATEGORIA)->value('id');

        $this->assertSame(
            count($this->contenido()['articles']),
            DB::table('help_articles')->where('category_id', $categoriaId)->count()
        );
    }

    #[Test]
    public function los_articulos_traen_contenido_util_y_no_placeholders(): void
    {
        foreach ($this->contenido()['articles'] as $articulo) {
            $this->assertNotEmpty($articulo['title']);

            // Un artículo de ayuda de dos líneas es peor que no tenerlo: ocupa
            // un lugar en el índice y no responde nada.
            $this->assertGreaterThan(400, strlen($articulo['content']),
                "El artículo «{$articulo['title']}» está demasiado corto para servir de algo.");

            $this->assertStringContainsString('<', $articulo['content'],
                'El contenido del Centro de Ayuda se renderiza como HTML.');
        }
    }
}
