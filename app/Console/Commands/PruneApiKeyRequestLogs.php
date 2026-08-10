<?php

namespace App\Console\Commands;

use App\Models\ApiKeyRequestLog;
use Illuminate\Console\Command;

/**
 * Purga la bitácora de la API pública.
 *
 * Sin esto la tabla crece sin techo: al límite del rate limiter son ~86 mil
 * filas por llave y por día. Se borra por lotes para no mantener un lock largo
 * sobre una tabla en la que se escribe en cada petición.
 */
class PruneApiKeyRequestLogs extends Command
{
    protected $signature = 'api-keys:prune-logs {--days=}';

    protected $description = 'Borra registros de peticiones de la API pública más antiguos que N días';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('api_keys.log_retention_days', 90));

        if ($days < 1) {
            $this->error('El número de días debe ser al menos 1.');

            return Command::FAILURE;
        }

        $cutoff  = now()->subDays($days);
        $deleted = 0;

        // Se seleccionan ids y luego se borra por id: `DELETE ... LIMIT` no
        // existe en PostgreSQL, y la aplicación corre sobre pgsql en producción
        // aunque los tests usen sqlite.
        do {
            $ids = ApiKeyRequestLog::query()
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(5000)
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                $deleted += ApiKeyRequestLog::whereIn('id', $ids)->delete();
            }
        } while ($ids->isNotEmpty());

        $this->info("Borrados {$deleted} registro(s) con más de {$days} días.");

        return Command::SUCCESS;
    }
}
