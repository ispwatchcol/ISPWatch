<?php

namespace App\Console\Commands;

use App\Services\OverdueSuspensionService;
use Illuminate\Console\Command;

class ReconcileReconnections extends Command
{
    /**
     * php artisan billing:reconcile-reconnections
     * php artisan billing:reconcile-reconnections --router=5
     * php artisan billing:reconcile-reconnections --dry-run
     * php artisan billing:reconcile-reconnections --force
     */
    protected $signature = 'billing:reconcile-reconnections
                            {--router= : ID del router a reconciliar (opcional, todos si no se especifica)}
                            {--dry-run : Solo reporta lo que reconectaría, sin tocar la RB}
                            {--force : Ignora el backoff y los agotados (nunca un intento en curso)}';

    protected $description = 'Reconcilia DB ⇄ RB en el sentido inverso: reabre en el router a los clientes activos en la DB cuya reconexión no quedó confirmada (P-29)';

    public function __construct(protected OverdueSuspensionService $suspensionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $routerId = $this->option('router') ? (int) $this->option('router') : null;
        $dryRun   = (bool) $this->option('dry-run');
        $force    = (bool) $this->option('force');

        $this->info('Reconciliando reconexiones DB ⇄ RB'
            . ($routerId ? " (router #{$routerId})" : '')
            . ($dryRun ? ' [dry-run]' : '')
            . ($force ? ' [force]' : '')
            . '...');

        try {
            $stats = $this->suspensionService->reconcileReconnections($routerId, $dryRun, $force);

            $this->table(
                ['Métrica', 'Cantidad'],
                [
                    ['Escaneados (DB activos con reconexión abierta)', $stats['scanned']],
                    ['Reconectados OK',                 $stats['reconnected_ok']],
                    ['Siguen pendientes',               $stats['reconnect_failed']],
                    ['Omitidos: movimiento posterior',  $stats['skipped_superseded']],
                    ['Omitidos: ficha no activa',       $stats['skipped_not_active']],
                    ['Omitidos por backoff',            $stats['skipped_backoff']],
                    ['Omitidos: intento en curso',      $stats['skipped_in_flight']],
                    ['Agotados (acción manual)',        $stats['skipped_exhausted']],
                    ['Omitidos: AAA externo',           $stats['skipped_external']],
                    ['Pendientes (dry-run)',            $stats['would_reconnect']],
                ]
            );

            $this->info('Reconciliación de reconexiones completada.');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Error reconciliando reconexiones: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
