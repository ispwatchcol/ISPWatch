<?php

namespace App\Providers;

use App\Support\ProductionDatabaseGuard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Las dos salvaguardas de KAN-95 · P-39.
 *
 *  1. `DB_SCHEMA` deja de tener un valor por defecto que apunta a producción.
 *     Fuera de producción, si no está definida, la aplicación no arranca.
 *  2. Ningún comando de consola escribe en el esquema `public` de la base
 *     administrada sin que alguien lo teclee a mano.
 *
 * Por qué son dos y no una: la primera cubre el olvido (la línea comentada en
 * el `.env`), la segunda cubre el descuido (la línea puesta a `public` a
 * propósito para consultar algo, y el `migrate` que vino después).
 */
class DatabaseSafetyServiceProvider extends ServiceProvider
{
    /**
     * La decisión se toma UNA vez por proceso.
     *
     * `migrate:both` llama por dentro a `migrate` y `db:seed`, y cada llamada
     * anidada dispara su propio CommandStarting. Sin esto, confirmar el comando
     * de arriba no serviría de nada: el primer hijo volvería a preguntar, y en
     * un contexto no interactivo reventaría a media migración.
     */
    private bool $consoleGuardEvaluated = false;

    public function boot(): void
    {
        $this->requireExplicitSchema();

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $this->guardConsoleCommand($event);
        });
    }

    /**
     * Sin `DB_SCHEMA` no se adivina.
     *
     * `config/database.php` ya no trae `public` como valor por defecto: acertar
     * en silencio contra el esquema equivocado es peor que no arrancar. Pero en
     * `production` sí se asume `public` —allí es el valor correcto— porque
     * convertir una variable ausente en una caída total del producto es un
     * remedio peor que la enfermedad. Queda el aviso en el log.
     */
    private function requireExplicitSchema(): void
    {
        $default = config('database.default');

        if ($default !== 'pgsql') {
            return;
        }

        $schema = config("database.connections.{$default}.schema");

        if (! blank($schema)) {
            return;
        }

        if ($this->app->environment('production')) {
            config(["database.connections.{$default}.schema" => ProductionDatabaseGuard::PRODUCTION_SCHEMA]);

            Log::warning('DB_SCHEMA no está definida. Se asume «public» porque APP_ENV=production, pero la variable debería estar en la especificación de la app.');

            return;
        }

        throw new RuntimeException(
            'DB_SCHEMA no está definida. Defínela explícitamente en el .env: '
            . '«ispwatch_dev» para desarrollo, «public» SOLO si de verdad quieres trabajar contra producción. '
            . 'Sin ella, PostgreSQL resuelve el search_path por defecto del servidor y aterriza en producción sin avisar (KAN-95).'
        );
    }

    /** El freno de mano de la consola. */
    private function guardConsoleCommand(CommandStarting $event): void
    {
        if ($this->consoleGuardEvaluated) {
            return;
        }

        $default = config('database.default');
        $connection = config("database.connections.{$default}", []);

        if (! ProductionDatabaseGuard::shouldGuard(
            (string) $this->app->environment(),
            is_array($connection) ? $connection : [],
            $event->command,
            ProductionDatabaseGuard::overridden(),
        )) {
            return;
        }

        $this->consoleGuardEvaluated = true;

        $style = new OutputStyle($event->input, $event->output);
        $schema = ProductionDatabaseGuard::targetSchema($connection['schema'] ?? null)
            ?? ProductionDatabaseGuard::PRODUCTION_SCHEMA;

        $style->warning([
            'Este comando va a escribir en PRODUCCIÓN.',
            sprintf('Comando: %s · host: %s · esquema: %s', $event->command ?: '(sin nombre)', $connection['host'] ?? '(en DB_URL)', $schema),
            'APP_ENV=' . $this->app->environment() . ', pero la conexión resuelta es la base administrada.',
        ]);

        // No basta con `isInteractive()`: vale `true` también cuando la
        // salida está redirigida a un archivo o el proceso corre sin terminal,
        // y entonces `ask()` se queda esperando para siempre una respuesta que
        // nadie puede teclear. Se comprobó en carne propia: la primera versión
        // de esta salvaguarda colgó la suite de tests lanzada en segundo plano.
        $hasTerminal = $event->input->isInteractive()
            && defined('STDIN')
            && (! function_exists('stream_isatty') || @stream_isatty(STDIN));

        if (! $hasTerminal) {
            throw new RuntimeException(
                'Comando detenido: la conexión apunta al esquema «' . $schema . '» de producción y no hay terminal para confirmar. '
                . 'Cambia DB_SCHEMA a ispwatch_dev, o exporta ' . ProductionDatabaseGuard::OVERRIDE_ENV . '=true si esto es intencionado (KAN-95).'
            );
        }

        $answer = (string) $style->ask('Escribe el nombre del esquema para confirmar que sabes dónde estás escribiendo');

        if (trim($answer) !== $schema) {
            throw new RuntimeException(
                'Comando cancelado: la confirmación no coincide con «' . $schema . '» (KAN-95).'
            );
        }
    }
}
