<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/**
 * Macros de búsqueda de texto insensible a mayúsculas, portables entre motores.
 *
 * El problema: en PostgreSQL `LIKE` distingue mayúsculas; en SQLite no. Como la
 * suite de tests corre sobre SQLite, una búsqueda escrita con `LIKE` pasa todos
 * los tests y luego, en producción, no encuentra nada: buscar "eliud" no
 * devolvía al cliente guardado como "Eliud". Ya ocurrió en la búsqueda de
 * Facturación.
 *
 * La solución opuesta —escribir `ilike` a pelo— tiene el defecto simétrico:
 * SQLite no conoce ese operador y la consulta revienta en los tests. Eso estaba
 * pasando en ProspectController.
 *
 * Estas macros eligen el operador según el driver, así que la misma línea
 * funciona en los dos sitios:
 *
 *     $query->whereLike('name', $termino)
 *           ->orWhereLike('cedula', $termino);
 *
 * Los comodines los pone la macro: se pasa el término tal cual lo escribió el
 * usuario. Los caracteres especiales de LIKE (`%` y `_`) se escapan para que
 * buscar "100%" no se convierta en un comodín.
 */
class SearchMacrosServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $operador = fn () => DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $escapar = function (string $termino): string {
            // `!` como carácter de escape (ver la cláusula ESCAPE de abajo).
            return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $termino) . '%';
        };

        foreach ([EloquentBuilder::class, QueryBuilder::class] as $builder) {
            $builder::macro('whereLike', function (string $columna, ?string $termino) use ($operador, $escapar) {
                if ($termino === null || trim($termino) === '') {
                    return $this;
                }

                return $this->whereRaw(
                    "{$columna} {$operador()} ? ESCAPE '!'",
                    [$escapar(trim($termino))]
                );
            });

            $builder::macro('orWhereLike', function (string $columna, ?string $termino) use ($operador, $escapar) {
                if ($termino === null || trim($termino) === '') {
                    return $this;
                }

                return $this->orWhereRaw(
                    "{$columna} {$operador()} ? ESCAPE '!'",
                    [$escapar(trim($termino))]
                );
            });
        }
    }
}
