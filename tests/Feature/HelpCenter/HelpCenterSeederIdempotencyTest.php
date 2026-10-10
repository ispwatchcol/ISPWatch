<?php

namespace Tests\Feature\HelpCenter;

use App\Models\HelpArticle;
use App\Models\HelpCategory;
use Database\Seeders\HelpCenterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P-12 / KAN-75: re-sembrar el Centro de Ayuda ya no borra lo que escribió un
 * superadmin desde la UI, y sembrar dos veces no duplica nada.
 */
class HelpCenterSeederIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sembrar_dos_veces_no_duplica_categorias_ni_articulos(): void
    {
        $this->seed(HelpCenterSeeder::class);
        $categorias = HelpCategory::count();
        $articulos  = HelpArticle::count();

        $this->seed(HelpCenterSeeder::class);

        $this->assertSame($categorias, HelpCategory::count());
        $this->assertSame($articulos, HelpArticle::count());
    }

    #[Test]
    public function un_articulo_creado_desde_la_ui_sobrevive_al_re_sembrado(): void
    {
        $this->seed(HelpCenterSeeder::class);
        $categoria = HelpCategory::firstOrFail();

        $propio = HelpArticle::create([
            'category_id'   => $categoria->id,
            'title'         => 'Cómo atendemos en Chaguaní los domingos',
            'content'       => '<p>Escrito por el superadmin.</p>',
            'is_published'  => true,
            'display_order' => 99,
        ]);
        $categoriaPropia = HelpCategory::create(['name' => 'Procedimientos internos', 'display_order' => 99]);

        $this->seed(HelpCenterSeeder::class);

        $this->assertNotNull(HelpArticle::find($propio->id));
        $this->assertSame('<p>Escrito por el superadmin.</p>', HelpArticle::find($propio->id)->content);
        $this->assertNotNull(HelpCategory::find($categoriaPropia->id));
    }

    #[Test]
    public function el_contenido_que_define_el_seeder_se_pone_al_dia(): void
    {
        $this->seed(HelpCenterSeeder::class);
        $articulo = HelpArticle::firstOrFail();
        $original = $articulo->content;
        $articulo->update(['content' => '<p>versión vieja</p>']);

        $this->seed(HelpCenterSeeder::class);

        $this->assertSame($original, $articulo->fresh()->content);
    }
}
