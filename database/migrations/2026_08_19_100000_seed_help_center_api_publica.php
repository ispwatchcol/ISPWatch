<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lleva a producción los artículos del Centro de Ayuda sobre la API pública.
 *
 * POR QUÉ UNA MIGRACIÓN Y NO UN SEEDER
 * `migrate:both` corre las migraciones en los dos esquemas, pero los seeders
 * SÓLO en `ispwatch_dev` —a propósito: crean filas y nadie quiere un seeder
 * suelto contra producción—. Este contenido tiene que existir en producción,
 * que es donde el ISP entra a leerlo, así que viaja como migración.
 *
 * El TEXTO no está aquí: vive en `database/seeders/content/api_publica_articles.php`,
 * compartido con `HelpCenterSeeder`. Dos copias del mismo artículo terminan
 * siempre igual — la de producción se queda vieja, y es la única que alguien lee.
 *
 * Es idempotente por título y NO sobrescribe: si alguien editó el texto desde el
 * panel, su versión manda. Una migración que pisa ediciones del usuario borra
 * trabajo ajeno en cada despliegue.
 *
 * El Centro de Ayuda es global (no tiene `tenant_id`): estos artículos los ven
 * todos los ISP de la plataforma, que es lo correcto para una función del
 * producto.
 */
return new class extends Migration
{
    public function up(): void
    {
        $contenido = $this->contenido();

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

    public function down(): void
    {
        $contenido = $this->contenido();

        $categoriaId = DB::table('help_categories')->where('name', $contenido['name'])->value('id');

        if (!$categoriaId) {
            return;
        }

        DB::table('help_articles')
            ->where('category_id', $categoriaId)
            ->whereIn('title', array_column($contenido['articles'], 'title'))
            ->delete();

        // La categoría sólo se borra si quedó vacía: puede que alguien haya
        // agregado sus propios artículos ahí, y no son nuestros para eliminar.
        $quedan = DB::table('help_articles')->where('category_id', $categoriaId)->count();

        if ($quedan === 0) {
            DB::table('help_categories')->where('id', $categoriaId)->delete();
        }
    }

    /** @return array<string, mixed> */
    private function contenido(): array
    {
        return require database_path('seeders/content/api_publica_articles.php');
    }
};
