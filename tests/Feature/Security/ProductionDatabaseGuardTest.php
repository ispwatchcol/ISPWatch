<?php

namespace Tests\Feature\Security;

use App\Support\ProductionDatabaseGuard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * KAN-95 · P-39 — que un `migrate` desde un portátil no pueda escribir en
 * producción sin que alguien lo teclee.
 *
 * La regresión que persiguen estos tests es invisible desde fuera: si la
 * salvaguarda deja de funcionar, todo sigue pareciendo correcto hasta el día en
 * que una migración sin revisar entra en `public`. Por eso se fija tanto la
 * decisión (qué combinación se considera producción) como el efecto real sobre
 * el comando.
 *
 * No se usa RefreshDatabase: nada de esto toca la base — que es justo el punto,
 * la salvaguarda actúa ANTES de la primera consulta.
 */
class ProductionDatabaseGuardTest extends TestCase
{
    /** El primer elemento del search_path es donde PostgreSQL escribe. */
    public function test_el_esquema_destino_es_el_primero_del_search_path(): void
    {
        $this->assertSame('public', ProductionDatabaseGuard::targetSchema('public'));
        $this->assertSame('ispwatch_dev', ProductionDatabaseGuard::targetSchema('ispwatch_dev,public'));
        $this->assertSame('ispwatch_dev', ProductionDatabaseGuard::targetSchema(' ispwatch_dev , public '));
        $this->assertNull(ProductionDatabaseGuard::targetSchema(null));
        $this->assertNull(ProductionDatabaseGuard::targetSchema(''));
    }

    public function test_host_administrado_solo_es_supabase(): void
    {
        $this->assertTrue(ProductionDatabaseGuard::isManagedHost('aws-0-us-east-1.pooler.supabase.com'));
        $this->assertTrue(ProductionDatabaseGuard::isManagedHost('db.proyecto.supabase.co'));
        $this->assertFalse(ProductionDatabaseGuard::isManagedHost('127.0.0.1'));
        $this->assertFalse(ProductionDatabaseGuard::isManagedHost('localhost'));
        $this->assertFalse(ProductionDatabaseGuard::isManagedHost(null));
    }

    public function test_supabase_con_esquema_public_es_produccion(): void
    {
        $this->assertTrue(ProductionDatabaseGuard::pointsAtProduction($this->supabaseConnection()));
    }

    /**
     * El caso más silencioso: sin `DB_SCHEMA`, PostgreSQL resuelve el
     * search_path por defecto del servidor y aterriza en producción.
     */
    public function test_supabase_sin_esquema_cuenta_como_produccion(): void
    {
        $this->assertTrue(ProductionDatabaseGuard::pointsAtProduction([
            'driver' => 'pgsql',
            'host'   => 'aws-0-us-east-1.pooler.supabase.com',
            'schema' => null,
        ]));
    }

    public function test_el_host_tambien_se_lee_de_db_url(): void
    {
        $this->assertTrue(ProductionDatabaseGuard::pointsAtProduction([
            'driver' => 'pgsql',
            'host'   => '127.0.0.1',
            'url'    => 'postgres://postgres:secreto@aws-0-us-east-1.pooler.supabase.com:5432/postgres',
            'schema' => 'public',
        ]));
    }

    public function test_el_esquema_de_desarrollo_no_es_produccion(): void
    {
        $this->assertFalse(ProductionDatabaseGuard::pointsAtProduction([
            'driver' => 'pgsql',
            'host'   => 'aws-0-us-east-1.pooler.supabase.com',
            'schema' => 'ispwatch_dev,public',
        ]));
    }

    /** El PostgreSQL desechable del CI usa `public` y no debe molestar nunca. */
    public function test_postgres_local_de_ci_no_dispara_la_salvaguarda(): void
    {
        $this->assertFalse(ProductionDatabaseGuard::pointsAtProduction([
            'driver' => 'pgsql',
            'host'   => '127.0.0.1',
            'schema' => 'public',
        ]));
    }

    public function test_sqlite_nunca_dispara_la_salvaguarda(): void
    {
        $this->assertFalse(ProductionDatabaseGuard::pointsAtProduction([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]));
    }

    /**
     * En el contenedor de producción `public` ES el destino correcto y no hay
     * terminal: preguntar allí convertiría la salvaguarda en una caída.
     */
    public function test_en_produccion_no_frena_nada(): void
    {
        $this->assertFalse(ProductionDatabaseGuard::shouldGuard(
            'production',
            $this->supabaseConnection(),
            'migrate',
        ));
    }

    public function test_los_comandos_de_solo_lectura_pasan_sin_preguntar(): void
    {
        $this->assertFalse(ProductionDatabaseGuard::shouldGuard('local', $this->supabaseConnection(), 'migrate:status'));
        $this->assertFalse(ProductionDatabaseGuard::shouldGuard('local', $this->supabaseConnection(), 'db:show'));
        $this->assertFalse(ProductionDatabaseGuard::shouldGuard('local', $this->supabaseConnection(), 'list'));
    }

    /**
     * `test` es el caso que costó aprender: la primera versión de la
     * salvaguarda frenaba a la suite entera, porque `php artisan test` arranca
     * con el .env local —Supabase y `public`— aunque PHPUnit corra después
     * sobre sqlite en memoria.
     */
    public function test_la_suite_y_el_andamiaje_no_se_frenan(): void
    {
        foreach (['test', 'make:model', 'config:clear', 'route:cache', 'optimize', 'view:clear', 'storage:link'] as $command) {
            $this->assertFalse(
                ProductionDatabaseGuard::shouldGuard('local', $this->supabaseConnection(), $command),
                "El comando {$command} no escribe en la base y no debería pedir confirmación."
            );
        }
    }

    /** `cache:clear` sí: con CACHE_STORE=database vacía la caché de producción. */
    public function test_cache_clear_si_se_frena(): void
    {
        $this->assertTrue(ProductionDatabaseGuard::shouldGuard('local', $this->supabaseConnection(), 'cache:clear'));
    }

    public function test_los_comandos_de_escritura_se_frenan(): void
    {
        foreach (['migrate', 'migrate:fresh', 'migrate:both', 'db:seed', 'db:wipe', 'tinker'] as $command) {
            $this->assertTrue(
                ProductionDatabaseGuard::shouldGuard('local', $this->supabaseConnection(), $command),
                "El comando {$command} debería frenarse contra producción."
            );
        }
    }

    public function test_la_escotilla_de_emergencia_desactiva_la_salvaguarda(): void
    {
        $this->assertFalse(ProductionDatabaseGuard::shouldGuard(
            'local',
            $this->supabaseConnection(),
            'migrate',
            true,
        ));
    }

    /**
     * El efecto real: sin terminal para confirmar, el comando no llega a correr.
     * Es el caso de un script o un cron en una máquina de desarrollo.
     */
    public function test_sin_terminal_el_comando_se_detiene(): void
    {
        $this->pointApplicationAtProduction();

        $input = new ArrayInput([]);
        $input->setInteractive(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/producción/u');

        Event::dispatch(new CommandStarting('migrate', $input, new BufferedOutput()));
    }

    /** Y el complemento: `migrate:status` sigue siendo consultable. */
    public function test_migrate_status_sigue_pasando_contra_produccion(): void
    {
        $this->pointApplicationAtProduction();

        $input = new ArrayInput([]);
        $input->setInteractive(false);

        Event::dispatch(new CommandStarting('migrate:status', $input, new BufferedOutput()));

        $this->assertTrue(true, 'migrate:status no debe frenarse: es la herramienta de diagnóstico.');
    }

    private function supabaseConnection(): array
    {
        return [
            'driver' => 'pgsql',
            'host'   => 'aws-0-us-east-1.pooler.supabase.com',
            'schema' => 'public',
        ];
    }

    /**
     * Deja la CONFIGURACIÓN apuntando a producción sin abrir ninguna conexión:
     * la salvaguarda mira la configuración resuelta, nunca `env()`.
     */
    private function pointApplicationAtProduction(): void
    {
        config([
            'database.default'           => 'pgsql',
            'database.connections.pgsql' => $this->supabaseConnection(),
        ]);
    }
}
