<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lleva a producción la corrección del artículo de RADIUS (KAN-102).
 *
 * El artículo decía que el formulario del router exigía IP, usuario, contraseña
 * y firmware aun en RADIUS, y recomendaba poner «valores de relleno». Desde
 * KAN-102 ya no se exigen, así que el consejo pasó a ser falso. El TEXTO vive en
 * `database/seeders/content/radius_aaa_articles.php`, compartido con el seeder.
 *
 * Mismo criterio que 2026_10_01_100000: sólo se reescribe si lo guardado sigue
 * siendo exactamente lo que publicó la migración anterior (md5, con saltos de
 * línea normalizados). Lo que un superadmin haya corregido desde el panel manda.
 *
 * `down()` no hace nada: volver a publicar que hay que inventarse datos sería
 * restaurar un error.
 */
return new class extends Migration
{
    private const TITLE = 'RADIUS (AAA): cuando otro sistema gestiona la red';

    /** md5 del texto publicado antes de esta migración. */
    private const PREVIOUS = 'a6e3c7f4883a8c0c4d7d2529c9ff5c23';

    public function up(): void
    {
        $radius  = require database_path('seeders/content/radius_aaa_articles.php');
        $article = collect($radius['articles'])->firstWhere('title', self::TITLE);

        if (!$article) {
            return;
        }

        $rows = DB::table('help_articles')->where('title', self::TITLE)->get(['id', 'content']);

        foreach ($rows as $row) {
            if (md5(str_replace("\r\n", "\n", (string) $row->content)) !== self::PREVIOUS) {
                continue;
            }

            DB::table('help_articles')->where('id', $row->id)->update([
                'content'    => $article['content'],
                'tips'       => $article['tips'] ?? null,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        //
    }
};
