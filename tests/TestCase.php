<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->abortIfConnectedToARealDatabase();
    }

    /**
     * La suite usa RefreshDatabase, que ejecuta `migrate:fresh`: apuntarla por
     * error a la base real la deja vacía. El riesgo no es teórico — el `.env`
     * local apunta a Supabase, así que un `DB_CONNECTION` mal puesto basta.
     *
     * La versión anterior de este guardia exigía que el driver fuese sqlite y
     * nada más. Eso dejaba imposible el job "PHPUnit (PostgreSQL, motor real)"
     * del CI, que existe justamente para cazar las diferencias que SQLite
     * esconde (booleanos, LIKE sensible a mayúsculas, índices parciales): el
     * job abortaba en TODOS los tests con base de datos.
     *
     * Lo que hay que prohibir no es un motor, es una base *real*. Así que:
     *
     *   · sqlite  → sólo en memoria (nunca un archivo del proyecto).
     *   · pgsql   → sólo si es desechable: host local y base terminada en
     *               `_test`. Es exactamente el contenedor del CI.
     *   · cualquier otra cosa → se aborta.
     *
     * Se comprueba la conexión YA RESUELTA, no la configuración escrita: un
     * `DB_URL` perdido puede reescribir driver y host de una conexión llamada
     * "sqlite" sin que el nombre cambie, y eso fue lo que casi manda un script
     * a la base real durante el desarrollo.
     */
    private function abortIfConnectedToARealDatabase(): void
    {
        $connection = DB::connection();
        $driver     = $connection->getDriverName();

        if ($driver === 'sqlite') {
            if ($connection->getDatabaseName() !== ':memory:') {
                throw new \RuntimeException(
                    "Salvaguarda: la conexión sqlite usa la base '{$connection->getDatabaseName()}' "
                    . "en vez de ':memory:' — abortando para no tocar un archivo real."
                );
            }

            return;
        }

        if ($driver === 'pgsql') {
            $host     = (string) $connection->getConfig('host');
            $database = (string) $connection->getConfig('database');

            $esLocal      = in_array($host, ['127.0.0.1', 'localhost', '::1', 'postgres'], true);
            $esDesechable = str_ends_with($database, '_test');

            if ($esLocal && $esDesechable && !str_contains($host, 'supabase')) {
                return;
            }

            throw new \RuntimeException(
                "Salvaguarda: la suite intentó correr sobre PostgreSQL en '{$host}/{$database}'. "
                . 'Sólo se admite una base desechable (host local y nombre terminado en `_test`), '
                . 'porque RefreshDatabase ejecuta migrate:fresh y vaciaría la base.'
            );
        }

        throw new \RuntimeException(
            "Salvaguarda: la suite intentó correr sobre el driver '{$driver}'. "
            . 'Sólo se admiten sqlite en memoria y un PostgreSQL desechable; '
            . 'revisa .env.testing y que no haya un DB_URL perdido.'
        );
    }
}
