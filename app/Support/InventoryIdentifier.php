<?php

namespace App\Support;

/**
 * Una sola definición de qué significa «el mismo serial» (KAN-100 · P-44).
 *
 * El problema que resuelve: los dos caminos de alta no entendían lo mismo por
 * «repetido». El formulario usaba la regla `unique`, que en PostgreSQL compara
 * con `=` y por tanto distingue mayúsculas —dejaba convivir `SN-001` y
 * `sn-001`—, mientras que la carga masiva comparaba en minúsculas y rechazaba
 * el segundo. Resultado: un inventario cargado uno por uno podía terminar con
 * el mismo equipo dos veces escrito distinto, y esas dos filas bloqueaban
 * después una carga masiva. Nadie recibía un aviso; la primera vez parecía que
 * había funcionado.
 *
 * DOS OPERACIONES, NO UNA:
 *
 *  · `store()`   — lo que se GUARDA. Sólo se recortan los espacios: el serial
 *                  se conserva como lo escribió el operador, porque es lo que
 *                  está impreso en la etiqueta del equipo y lo que va a buscar
 *                  con la vista. Pasarlo a minúsculas sería inventarse un dato.
 *  · `comparable()` — lo que se COMPARA. Minúsculas, para que `SN-001` y
 *                  `sn-001` sean el mismo equipo, que es lo que son.
 */
class InventoryIdentifier
{
    /** Valor tal cual se guarda: sin espacios sobrantes, y null si queda vacío. */
    public static function store(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Clave de comparación. null cuando no hay nada que comparar. */
    public static function comparable(?string $value): ?string
    {
        $value = self::store($value);

        return $value === null ? null : mb_strtolower($value);
    }
}
