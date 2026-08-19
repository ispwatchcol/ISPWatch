<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sincroniza los artículos de «Integraciones y API» que falten en producción.
 *
 * POR QUÉ UNA MIGRACIÓN NUEVA Y NO EDITAR LA ANTERIOR
 * La 2026_08_19_100000 ya corrió (lote 88): Laravel no la vuelve a ejecutar, así
 * que agregar un artículo al archivo compartido no lo lleva a ningún sitio donde
 * alguien lo lea. Editar una migración ya aplicada es peor todavía — funciona en
 * las bases nuevas y deja fuera exactamente a la que importa.
 *
 * El bucle es el mismo de la anterior a propósito. Podría extraerse a una clase
 * compartida, pero una migración es un registro histórico: si esa clase cambia
 * dentro de seis meses, cambia también lo que hicieron las migraciones ya
 * ejecutadas, y deja de poder reconstruirse el estado desde cero.
 *
 * Sigue siendo idempotente por título y sigue sin sobrescribir: si alguien editó
 * un artículo desde el panel, su versión manda.
 */
return new class extends Migration
{
    public function up(): void
    {
        $contenido = $this->contenido();

        // La categoría la crea la migración anterior. Si no está —base nueva
        // donde ésta corre primero por algún motivo— se crea aquí igual, en vez
        // de fallar y dejar la migración a medias.
        $categoriaId = DB::table('help_categories')->where('name', $contenido['name'])->value('id');

        if (!$categoriaId) {
            $categoriaId = DB::table('help_categories')->insertGetId([
                'name'          => $contenido['name'],
                'icon'          => $contenido['icon'],
                'description'   => $contenido['description'],
                'display_order' => $contenido['display_order'],
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        foreach ($contenido['articles'] as $orden => $articulo) {
            $existe = DB::table('help_articles')
                ->where('category_id', $categoriaId)
                ->where('title', $articulo['title'])
                ->exists();

            if ($existe) {
                continue;
            }

            DB::table('help_articles')->insert([
                'category_id'   => $categoriaId,
                'title'         => $articulo['title'],
                'content'       => $articulo['content'],
                'tips'          => $articulo['tips'] ?? null,
                'is_published'  => true,
                'display_order' => $articulo['display_order'] ?? ($orden + 1),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
    }

    /**
     * Sólo revierte el artículo que ESTA migración introdujo.
     *
     * Borrar los cinco anteriores sería pisar el trabajo de la migración previa,
     * que tiene su propio `down()`.
     */
    public function down(): void
    {
        DB::table('help_articles')
            ->where('title', 'Probar la API: primeros comandos, Postman y curl')
            ->delete();
    }

    /** @return array<string, mixed> */
    private function contenido(): array
    {
        return require database_path('seeders/content/api_publica_articles.php');
    }
};
