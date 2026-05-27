<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateBothSchemas extends Command
{
    protected $signature = 'migrate:both
                            {--fresh : Drop all tables and re-run migrations (USE WITH CAUTION)}
                            {--seed : Seed after migrating}
                            {--path= : Path to a specific migration file}
                            {--force : Force run in production}';

    protected $description = 'Run migrations on both ispwatch_dev and public schemas';

    public function handle(): int
    {
        if (config('database.default') !== 'pgsql') {
            $this->error('This command only works with PostgreSQL.');
            return 1;
        }

        $schemas = ['ispwatch_dev', 'public'];

        foreach ($schemas as $schema) {
            $this->info("─── Schema: <fg=yellow>{$schema}</> ───────────────────────");

            config(['database.connections.pgsql.schema' => $schema]);
            DB::purge('pgsql');
            DB::reconnect('pgsql');

            $options = ['--force' => true];

            if ($this->option('path')) {
                $options['--path'] = $this->option('path');
            }

            if ($this->option('fresh')) {
                if (!$this->confirm("¿Seguro que quieres hacer migrate:fresh en <{$schema}>? Se borran TODOS los datos.")) {
                    $this->warn("Saltando schema {$schema}.");
                    continue;
                }
                $command = 'migrate:fresh';
                if ($this->option('seed')) {
                    $options['--seed'] = true;
                }
            } else {
                $command = 'migrate';
            }

            $this->call($command, $options);
        }

        // Restore original search_path
        config(['database.connections.pgsql.schema' => env('DB_SCHEMA', 'ispwatch_dev,public')]);
        DB::purge('pgsql');
        DB::reconnect('pgsql');

        $this->info('');
        $this->info('✅ Migrations applied to both schemas.');
        return 0;
    }
}
