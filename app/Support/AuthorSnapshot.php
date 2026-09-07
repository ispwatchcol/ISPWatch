<?php

namespace App\Support;

use App\Models\User;

/**
 * Nombre visible de un usuario, congelado en el momento de escribir.
 *
 * H-6 · Las notas y los adjuntos del ticket conservan a su autor aunque el
 * usuario se dé de baja: la clave foránea pasa a `SET NULL` y el nombre queda
 * guardado en la fila. Sin esto, la bitácora de un cliente eliminado quedaría
 * llena de «—».
 *
 * Congelar es además lo correcto para un expediente: refleja quién firmaba
 * ENTONCES, no cómo se llama hoy. Es el mismo criterio que el historial del
 * PR #3 aplica a las etiquetas de catálogo.
 *
 * SÓLO EL NOMBRE. Ni correo, ni teléfono, ni documento. El expediente necesita
 * saber quién escribió, no reconstruir la ficha de alguien que pidió su baja;
 * guardar de más sería crear una copia que sobrevive al borrado solicitado.
 */
class AuthorSnapshot
{
    /** Longitud de la columna `author_name` en ambas tablas. */
    private const MAX = 120;

    public static function para(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        // Sin el scope de tenant: al escribir una nota el actor siempre es del
        // mismo tenant, pero el respaldo tiene que funcionar también desde
        // consola y desde jobs, donde no hay sesión que resuelva el scope.
        $usuario = User::withoutGlobalScopes()->find($userId);

        if (!$usuario) {
            return null;
        }

        $nombre = trim(($usuario->user_name ?? '') . ' ' . ($usuario->user_lastname ?? ''));

        if ($nombre === '') {
            $nombre = (string) ($usuario->email ?? '');
        }

        return $nombre !== '' ? mb_substr($nombre, 0, self::MAX) : null;
    }
}
