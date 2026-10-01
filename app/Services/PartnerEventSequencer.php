<?php

namespace App\Services;

use App\Models\PartnerEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Publica los eventos partner ya confirmados asignándoles `seq` (KAN-112).
 *
 * EL PROBLEMA QUE RESUELVE
 * ------------------------
 * Un evento se inserta dentro de la transacción del cambio que lo origina (si
 * el cambio hace rollback, el evento también: eso no se negocia). Pero su `id`
 * se toma al insertar, no al confirmar, así que los ids no se vuelven visibles
 * en orden. Un cursor sobre `id` se salta para siempre al que confirma tarde.
 *
 * POR QUÉ ES CORRECTO
 * -------------------
 * - Sólo ve filas confirmadas: las de una transacción abierta no existen para
 *   otra conexión.
 * - Corre en serie (lock consultivo en PostgreSQL; SQLite ya serializa las
 *   escrituras), y cada corrida numera por encima del máximo `seq` vigente.
 *   Su propia transacción publica todo el lote de una vez, así que un lector
 *   nunca ve un `seq` antes que otro menor.
 * - Una fila que confirma después de la corrida queda con `seq` nulo y la
 *   numera la siguiente, por encima de todo lo ya publicado. Ese es
 *   exactamente el caso que el cursor por `id` perdía.
 *
 * El rango se acota por abajo con el `id` mínimo pendiente: una fila con un id
 * menor que confirme entre el SELECT y el UPDATE recibiría un `seq` por debajo
 * del ya publicado y rompería el orden. Queda para la siguiente corrida.
 *
 * UNA SOLA SENTENCIA
 * ------------------
 * `seq = id + desplazamiento` numera cualquier cantidad de filas en un solo
 * UPDATE, portable a PostgreSQL y SQLite. Deja huecos en la numeración, que el
 * contrato ya admite (la secuencia es compartida entre tenants).
 *
 * CUÁNDO CORRE
 * ------------
 * Al leer: los controladores partner lo llaman antes de consultar. No hace
 * falta un proceso aparte para que el feed sea correcto —lo que no se publicó
 * es invisible, y quien lee lo publica—, y cuando no hay nada pendiente cuesta
 * una consulta por índice.
 */
class PartnerEventSequencer
{
    /** Clave del lock consultivo de PostgreSQL. Arbitraria, pero fija. */
    private const LOCK_KEY = 7_402_113_112;

    /**
     * @return int eventos publicados en esta corrida
     */
    public function publishPending(): int
    {
        try {
            if (!PartnerEvent::query()->whereNull('seq')->exists()) {
                return 0;
            }

            $connection = PartnerEvent::query()->getConnection();

            return $connection->transaction(function () use ($connection) {
                if ($connection->getDriverName() === 'pgsql') {
                    $connection->select('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);
                }

                $pending = PartnerEvent::query()
                    ->whereNull('seq')
                    ->selectRaw('MIN(id) AS min_id, MAX(id) AS max_id')
                    ->first();

                if (!$pending || $pending->min_id === null) {
                    return 0;
                }

                $minId  = (int) $pending->min_id;
                $maxId  = (int) $pending->max_id;
                $base   = (int) PartnerEvent::query()->max('seq');
                $offset = $base - $minId + 1;

                return PartnerEvent::query()
                    ->whereNull('seq')
                    ->whereBetween('id', [$minId, $maxId])
                    ->update(['seq' => DB::raw('id + (' . $offset . ')')]);
            });
        } catch (\Throwable $e) {
            // Nunca tumba la lectura: lo que no se publique ahora se publica
            // en la próxima, sin perder nada.
            Log::error('PartnerEventSequencer: no se pudieron publicar los eventos pendientes.', [
                'exception' => $e->getMessage(),
            ]);

            return 0;
        }
    }
}
