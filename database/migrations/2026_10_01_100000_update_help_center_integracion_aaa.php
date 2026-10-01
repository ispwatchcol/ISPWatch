<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lleva a producción el manual de la integración AAA actualizado (2026-10-01).
 *
 * Después de las §§ 85-86 de la bitácora el Centro de Ayuda decía cosas que ya
 * no eran ciertas —que en un router RADIUS la IP es obligatoria, que el
 * listado de cambios sólo trae cortes y reconexiones— y le faltaba la guía que
 * pidió el integrador de CNO: qué campo decide el acceso, qué garantiza el
 * feed y cómo sincronizar sin perder nada. El TEXTO vive en
 * `database/seeders/content/`, compartido con `HelpCenterSeeder`.
 *
 * NO PISA LO EDITADO DESDE EL PANEL
 * ---------------------------------
 * Un superadmin puede corregir cualquier artículo desde el panel, y esa versión
 * manda. Para distinguirla, cada artículo que se reescribe lleva la huella
 * (md5) del texto que publicó la migración anterior: sólo se actualiza si lo
 * guardado sigue siendo exactamente eso. Si alguien lo tocó, se deja como está.
 * Los saltos de línea se normalizan antes de comparar.
 *
 * La guía nueva se inserta si falta, idempotente por título.
 *
 * `down()` retira sólo la guía nueva. Las correcciones no se revierten: volver
 * a publicar que la IP es obligatoria sería restaurar un error.
 */
return new class extends Migration
{
    /** Título => md5 del texto publicado antes de esta migración. */
    private const PREVIOUS = [
        'Qué ve cada permiso de la llave'                    => '640fbd8db8dcf3502d103948a28ec832',
        'Probar la API: primeros comandos, Postman y curl'   => 'ba09fde2000aaf7be648657eae38e5f1',
        'RADIUS (AAA): cuando otro sistema gestiona la red'  => 'bbb852ec2becf647f548845c94585ab2',
    ];

    private const NEW_GUIDE = 'Guía técnica para integradores AAA: sincronizar sin perder cambios';

    public function up(): void
    {
        $api    = require database_path('seeders/content/api_publica_articles.php');
        $radius = require database_path('seeders/content/radius_aaa_articles.php');

        foreach (array_merge($api['articles'], $radius['articles']) as $article) {
            if (isset(self::PREVIOUS[$article['title']])) {
                $this->updateIfUntouched($article, self::PREVIOUS[$article['title']]);
            }
        }

        $guide = collect($api['articles'])->firstWhere('title', self::NEW_GUIDE);
        $categoryId = $this->categoryId($api);

        if (DB::table('help_articles')->where('category_id', $categoryId)->where('title', self::NEW_GUIDE)->exists()) {
            return;
        }

        $position = DB::table('help_articles')->where('category_id', $categoryId)->max('display_order');

        DB::table('help_articles')->insert([
            'category_id'   => $categoryId,
            'title'         => $guide['title'],
            'content'       => $guide['content'],
            'tips'          => $guide['tips'] ?? null,
            'is_published'  => true,
            'display_order' => ((int) $position) + 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('help_articles')->where('title', self::NEW_GUIDE)->delete();
    }

    /** @param array<string, mixed> $article */
    private function updateIfUntouched(array $article, string $previousHash): void
    {
        $rows = DB::table('help_articles')->where('title', $article['title'])->get(['id', 'content']);

        foreach ($rows as $row) {
            if (md5(str_replace("\r\n", "\n", (string) $row->content)) !== $previousHash) {
                continue;
            }

            DB::table('help_articles')->where('id', $row->id)->update([
                'content'    => $article['content'],
                'tips'       => $article['tips'] ?? null,
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * La categoría la crea la migración de 2026-08-19; si en algún entorno
     * falta, se crea aquí para que la guía no quede fuera justo de producción.
     *
     * @param array<string, mixed> $content
     */
    private function categoryId(array $content): int
    {
        $id = DB::table('help_categories')->where('name', $content['name'])->value('id');

        if ($id) {
            return (int) $id;
        }

        return (int) DB::table('help_categories')->insertGetId([
            'name'          => $content['name'],
            'icon'          => $content['icon'],
            'description'   => $content['description'],
            'display_order' => $content['display_order'],
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }
};
