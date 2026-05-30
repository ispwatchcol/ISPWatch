<?php

namespace App\Console\Commands;

use App\Services\TrafficHistoryService;
use Illuminate\Console\Command;

class CollectTrafficHistory extends Command
{
    protected $signature = 'traffic:collect';
    protected $description = 'Muestrea el tráfico WAN de los routers con Historial de Tráfico activo';

    public function __construct(protected TrafficHistoryService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $r = $this->service->collect();
        $this->info("Tráfico WAN: {$r['sampled']} router(es) muestreados, {$r['failed']} con error (de {$r['total']} con historial activo).");

        return Command::SUCCESS;
    }
}
