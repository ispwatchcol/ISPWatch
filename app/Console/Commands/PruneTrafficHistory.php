<?php

namespace App\Console\Commands;

use App\Services\TrafficHistoryService;
use Illuminate\Console\Command;

class PruneTrafficHistory extends Command
{
    protected $signature = 'traffic:prune {--days=30}';
    protected $description = 'Borra muestras finas de tráfico más antiguas que N días (los agregados diarios se conservan)';

    public function __construct(protected TrafficHistoryService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $days    = (int) $this->option('days');
        $deleted = $this->service->prune($days);
        $this->info("Borradas {$deleted} muestra(s) de tráfico con más de {$days} días.");

        return Command::SUCCESS;
    }
}
