<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lleva a producción la documentación del método de control RADIUS (AAA).
 *
 * POR QUÉ UNA MIGRACIÓN Y NO UN SEEDER
 * `migrate:both` corre las migraciones en los dos esquemas, pero los seeders
 * SÓLO en `ispwatch_dev`. Este contenido tiene que existir en producción, que
 * es donde el ISP entra a leerlo, así que viaja como migración.
 *
 * El TEXTO no está aquí: vive en `database/seeders/content/radius_aaa_articles.php`,
 * compartido con `HelpCenterSeeder`.
 *
 * DOS OPERACIONES DISTINTAS, CON REGLAS DISTINTAS
 * ------------------------------------------------
 * 1. INSERTAR el artículo nuevo. Idempotente por título y sin sobrescribir,
 *    igual que la migración de la API pública: si alguien ya escribió uno con
 *    ese título, su versión manda.
 *
 * 2. CORREGIR el artículo «El método de control del router», que ya existe en
 *    producción y listaba CINCO métodos — RADIUS (AAA) no aparecía, pese a
 *    estar en el formulario del router desde 2026-08-14. Un cliente preguntó
 *    por una opción que el propio manual no mencionaba.
 *
 * La corrección sí reescribe contenido existente, cosa que las migraciones del
 * Centro de Ayuda evitan por regla. La condición la vuelve segura: se actualiza
 * **sólo si el texto guardado no menciona RADIUS**. Si un superadmin ya lo
 * documentó por su cuenta, su redacción se respeta y no se toca nada.
 *
 * `UPPER(content) NOT LIKE` y no `ILIKE`: la suite de tests corre sobre sqlite,
 * que no conoce `ILIKE` y revienta la migración entera.
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

        $categoriaId = $this->categoriaId($contenido['category'], crear: true);

        // El artículo del método de control puede faltar por completo (entorno
        // sin el manual sembrado) o estar presente pero incompleto. Son dos
        // situaciones distintas y cada una tiene su arreglo.
        $this->insertarArticulosNuevos($categoriaId, [$contenido['control_mode_article']]);
        $this->corregirArticuloMetodoDeControl($categoriaId, $contenido['control_mode_article']);

        $this->insertarArticulosNuevos($categoriaId, $contenido['articles']);
    }

    public function down(): void
    {
        $contenido = $this->contenido();

        $categoriaId = $this->categoriaId($contenido['category'], crear: false);

        if (!$categoriaId) {
            return;
        }

        // Sólo se retira el artículo nuevo. La corrección del artículo de
        // método de control NO se revierte a propósito: volver a publicar una
        // lista de métodos incompleta sería restaurar un error, no deshacer
        // un cambio.
        $borrados = DB::table('help_articles')
            ->where('category_id', $categoriaId)
            ->whereIn('title', array_column($contenido['articles'], 'title'))
            ->delete();

        // Sólo se devuelve el orden si de verdad se quitó el artículo. Si `up()`
        // no lo insertó —porque ya existía uno con ese título— tampoco corrió
        // a nadie, y decrementar aquí desordenaría la categoría entera.
        if ($borrados === 0) {
            return;
        }

        DB::table('help_articles')
            ->where('category_id', $categoriaId)
            ->where('display_order', '>=', 4)
            ->decrement('display_order');

        // La categoría sólo se borra si quedó vacía: en un entorno con el
        // manual sembrado está llena de artículos que no son nuestros para
        // eliminar, y allí esta línea no hace nada.
        if (DB::table('help_articles')->where('category_id', $categoriaId)->count() === 0) {
            DB::table('help_categories')->where('id', $categoriaId)->delete();
        }
    }

    /**
     * Id de la categoría, creándola si falta y así se pide.
     *
     * En producción el Centro de Ayuda sólo tiene lo que alguna migración haya
     * sembrado —los seeders no corren allí—, así que dar la categoría por
     * existente dejaría este contenido fuera justo del entorno que se lee.
     *
     * @param array<string, mixed> $categoria
     */
    private function categoriaId(array $categoria, bool $crear): ?int
    {
        $id = DB::table('help_categories')->where('name', $categoria['name'])->value('id');

        if ($id) {
            return (int) $id;
        }

        if (!$crear) {
            return null;
        }

        return (int) DB::table('help_categories')->insertGetId([
            'name'          => $categoria['name'],
            'icon'          => $categoria['icon'],
            'description'   => $categoria['description'],
            'display_order' => $categoria['display_order'],
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    /**
     * Reescribe el artículo del método de control, pero sólo si sigue sin
     * mencionar RADIUS: es la señal de que nadie lo editó a mano desde el panel.
     *
     * @param array<string, mixed> $articulo
     */
    private function corregirArticuloMetodoDeControl(int $categoriaId, array $articulo): void
    {
        DB::table('help_articles')
            ->where('category_id', $categoriaId)
            ->where('title', $articulo['title'])
            ->whereRaw('UPPER(content) NOT LIKE ?', ['%RADIUS%'])
            ->update([
                'content'    => $articulo['content'],
                'tips'       => $articulo['tips'],
                'updated_at' => now(),
            ]);
    }

    /**
     * Inserta los artículos que falten, abriéndoles sitio en el orden.
     *
     * @param array<int, array<string, mixed>> $articulos
     */
    private function insertarArticulosNuevos(int $categoriaId, array $articulos): void
    {
        foreach ($articulos as $orden => $articulo) {
            $existe = DB::table('help_articles')
                ->where('category_id', $categoriaId)
                ->where('title', $articulo['title'])
                ->exists();

            if ($existe) {
                continue;
            }

            $posicion = $articulo['display_order'] ?? ($orden + 1);

            // El artículo va justo detrás del de método de control, no al
            // final: quien acaba de leer sobre los seis métodos es exactamente
            // quien necesita leer este. Los que estaban en esa posición o más
            // abajo se corren un puesto, conservando su orden relativo aunque
            // alguien los haya reordenado desde el panel.
            DB::table('help_articles')
                ->where('category_id', $categoriaId)
                ->where('display_order', '>=', $posicion)
                ->increment('display_order');

            DB::table('help_articles')->insert([
                'category_id'   => $categoriaId,
                'title'         => $articulo['title'],
                'content'       => $articulo['content'],
                'tips'          => $articulo['tips'] ?? null,
                'is_published'  => true,
                'display_order' => $posicion,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function contenido(): array
    {
        return require database_path('seeders/content/radius_aaa_articles.php');
    }
};
