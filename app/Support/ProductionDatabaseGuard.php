<?php

namespace App\Support;

/**
 * Decide si un comando de consola está a punto de escribir en PRODUCCIÓN.
 *
 * KAN-95 · P-39. El 2026-08-21 una migración sin revisar se aplicó al esquema
 * `public` mientras se creía estar validando contra una base desechable. La
 * salvaguarda de `tests/TestCase.php` no intervino porque sólo cubre las
 * pruebas: `migrate`, `db:seed` y `tinker` no pasan por ella.
 *
 * Lo que separa desarrollo de producción en ISPWatch NO es el host —Supabase
 * aloja `ispwatch_dev` y `public` en la MISMA base— sino el `search_path`. Por
 * eso la decisión mira el esquema resuelto, no la URL.
 *
 * Toda la lógica vive aquí, sin tocar el contenedor ni la salida, para que sea
 * comprobable con una tabla de casos. El provider sólo la consulta.
 */
class ProductionDatabaseGuard
{
    /** Escotilla de emergencia, para scripts que sí saben lo que hacen. */
    public const OVERRIDE_ENV = 'ISPWATCH_ALLOW_PRODUCTION_DB';

    /** El esquema de producción. `ispwatch_dev` es el de desarrollo. */
    public const PRODUCTION_SCHEMA = 'public';

    /**
     * Marcadores de host administrado donde vive la base de producción.
     *
     * No entran ni `localhost` ni `127.0.0.1`: un Postgres de contenedor —el de
     * CI, por ejemplo— usa `public` como esquema y sería un falso positivo
     * constante. Lo que hace peligrosa a la combinación es que el host sea el
     * remoto real.
     */
    private const MANAGED_HOST_MARKERS = [
        'supabase.com',
        'supabase.co',
        'supabase.in',
    ];

    /**
     * Comandos que NO pueden escribir en la base, y por tanto pasan sin
     * preguntar nada.
     *
     * La lista es una excepción explícita, no un filtro: lo que no esté aquí se
     * trata como escritura. Equivocarse hacia el lado de preguntar de más
     * cuesta una confirmación; equivocarse hacia el otro cuesta una fila en
     * producción.
     *
     * Dos entradas merecen explicación:
     *
     *  · `migrate:status` — es justo el comando con el que se diagnostica un
     *    despliegue. Negarlo dejaría a ciegas a quien más lo necesita, y no
     *    escribe nada.
     *  · `test` — la suite NO usa esta conexión: `phpunit.xml` fuerza
     *    `sqlite :memory:` y `tests/TestCase.php` aborta si se encuentra
     *    conectada a una base real. Frenarla aquí bloqueaba la suite entera en
     *    cualquier máquina cuyo .env apunte a Supabase.
     *  · `inventory:duplicate-identifiers` — su razón de existir es mirar los
     *    duplicados de PRODUCCIÓN antes de aplicar la migración que los sella
     *    (KAN-100). Exigirle confirmación al único comando que hay que correr
     *    contra producción para decidir si migrar sería llevar la contraria a
     *    su propósito. No escribe nada: sólo agrupa y cuenta.
     *  · `billing:audit-books` y `billing:statement` — por la misma razón: se
     *    inventaron para auditar los libros DE PRODUCCIÓN cuando un cliente
     *    reclama un descuadre. Son lectores puros (ni un UPDATE, ni un INSERT)
     *    y frenarlos aquí sería frenar justo el diagnóstico. Ojo: `audit-books`
     *    manda correo con `--mail`, que no es escritura en la base pero sí sale
     *    al mundo — por defecto no lo hace.
     */
    private const ALLOWED_COMMANDS = [
        'about',
        'billing:audit-books',
        'billing:statement',
        'config:show',
        'db:monitor',
        'db:show',
        'db:table',
        'env',
        'help',
        'inspire',
        'inventory:duplicate-identifiers',
        'list',
        'migrate:status',
        'route:list',
        'schedule:list',
        'test',
    ];

    /**
     * Familias completas que tampoco tocan la base: andamiaje, cachés de
     * archivos y ayuda. `cache:` NO está: con CACHE_STORE=database, un
     * `cache:clear` desde un portátil vacía la caché de producción.
     */
    private const ALLOWED_PREFIXES = [
        'config:',
        'ide-helper:',
        'key:',
        'lang:',
        'make:',
        'optimize',
        'route:',
        'storage:',
        'stub:',
        'vendor:',
        'view:',
    ];

    /**
     * Primer esquema del `search_path`: es donde Postgres CREA lo que no lleva
     * esquema explícito. `ispwatch_dev,public` escribe en desarrollo y sólo lee
     * de `public` (el fallback de PostGIS), así que no es un destino peligroso.
     */
    public static function targetSchema(?string $schema): ?string
    {
        if ($schema === null) {
            return null;
        }

        $first = trim((string) explode(',', $schema)[0], " \t\n\"'");

        return $first === '' ? null : $first;
    }

    /** ¿El host es la base administrada donde vive producción? */
    public static function isManagedHost(?string $host): bool
    {
        $host = strtolower(trim((string) $host));

        if ($host === '') {
            return false;
        }

        foreach (self::MANAGED_HOST_MARKERS as $marker) {
            if (str_contains($host, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿La conexión YA RESUELTA escribe en producción?
     *
     * Se mira `config('database.connections.pgsql')` y nunca `env()`: la
     * configuración resuelta es la que manda, y confiar en `env()` es
     * exactamente lo que engañó en agosto.
     */
    public static function pointsAtProduction(array $connection): bool
    {
        if (($connection['driver'] ?? null) !== 'pgsql') {
            return false;
        }

        $host = $connection['host'] ?? null;

        // Con `DB_URL` el host viaja dentro de la URL y no en su propia clave.
        if (! empty($connection['url'])) {
            $host = parse_url((string) $connection['url'], PHP_URL_HOST) ?: $host;
        }

        if (! self::isManagedHost($host)) {
            return false;
        }

        // Sin esquema, Postgres resuelve el `search_path` por defecto del
        // servidor —«"$user", public»—, que aterriza en producción. Es el caso
        // más silencioso de todos, así que cuenta como producción.
        $schema = self::targetSchema($connection['schema'] ?? null);

        return $schema === null || $schema === self::PRODUCTION_SCHEMA;
    }

    /** ¿Es uno de los comandos que no pueden escribir en la base? */
    public static function isReadOnlyCommand(?string $command): bool
    {
        $command = (string) $command;

        if (in_array($command, self::ALLOWED_COMMANDS, true)) {
            return true;
        }

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($command, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** ¿Alguien pidió explícitamente saltarse la salvaguarda? */
    public static function overridden(): bool
    {
        return filter_var(env(self::OVERRIDE_ENV, false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * La decisión completa: ¿hay que frenar este comando y pedir confirmación?
     *
     * En `production` NUNCA frena: allí `public` es el destino correcto y el
     * contenedor corre sin terminal, así que preguntar sería una caída.
     */
    public static function shouldGuard(
        string $environment,
        array $connection,
        ?string $command,
        bool $overridden = false
    ): bool {
        if ($environment === 'production' || $overridden) {
            return false;
        }

        if (self::isReadOnlyCommand($command)) {
            return false;
        }

        return self::pointsAtProduction($connection);
    }
}
