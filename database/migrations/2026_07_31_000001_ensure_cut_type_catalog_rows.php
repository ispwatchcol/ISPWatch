<?php

use App\Models\CutType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Garantiza que el catálogo `cut_type` tenga sus tres filas en TODOS los
 * esquemas.
 *
 * Por qué una migración y no el seeder: `migrate:both` aplica las migraciones en
 * `ispwatch_dev` y en `public`, pero **nunca siembra `public`** (los seeders
 * crean datos de demostración que contaminarían producción). Resultado: un
 * catálogo que sólo existe por seeder puede acabar vacío en producción, y sin
 * filas en `cut_type` ningún router entra al corte automático — fuga de ingreso
 * silenciosa, porque el servicio no reporta error: simplemente no encuentra
 * routers elegibles.
 *
 * Es idempotente: sólo inserta lo que falta y no toca las filas existentes ni
 * sus ids (a los que apunta `router.cut_type_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        $existentes = DB::table('cut_type')->pluck('name')->all();

        // Comparación normalizada: si ya existe 'Corte Automatico' sin tilde no
        // se inserta un duplicado con tilde — se respeta lo que hay.
        foreach (CutType::ALL as $nombre) {
            $yaEsta = collect($existentes)
                ->contains(fn ($actual) => CutType::matches($actual, $nombre));

            if ($yaEsta) {
                continue;
            }

            DB::table('cut_type')->insert([
                'name'       => $nombre,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // No se borra nada: `router.cut_type_id` apunta a estas filas y perderlas
        // dejaría los routers sin modo de corte.
    }
};
