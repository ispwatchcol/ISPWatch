<?php

namespace App\Console\Commands;

use App\Services\OverdueSuspensionService;
use Illuminate\Console\Command;

class ReconcileSuspensions extends Command
{
    /**
     * php artisan billing:reconcile-suspensions
     * php artisan billing:reconcile-suspensions --router=5
     * php artisan billing:reconcile-suspensions --dry-run
     * php artisan billing:reconcile-suspensions --force
     */
    protected $signature = 'billing:reconcile-suspensions
                            {--router= : ID del router a reconciliar (opcional, todos si no se especifica)}
                            {--dry-run : Solo reporta lo que re-cortaría, sin tocar la RB}
                            {--force : Ignora el backoff y re-asserta aunque el corte ya esté confirmado}';

    protected $description = 'Reconcilia DB ⇄ RB: re-corta en el router a los clientes suspendidos en la DB cuyo corte no está confirmado, y registra los fallos';

    public function __construct(protected OverdueSuspensionService $suspensionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $routerId = $this->option('router') ? (int) $this->option('router') : null;
        $dryRun   = (bool) $this->option('dry-run');
        $force    = (bool) $this->option('force');

        $this->info('Reconciliando cortes DB ⇄ RB'
            . ($routerId ? " (router #{$routerId})" : '')
            . ($dryRun ? ' [dry-run]' : '')
            . ($force ? ' [force]' : '')
            . '...');

        try {
            $stats = $this->suspensionService->reconcileSuspensions($routerId, $dryRun, $force);

            $this->table(
                ['Métrica', 'Cantidad'],
                [
                    ['Escaneados (DB suspendidos)', $stats['scanned']],
                    ['Ya confirmados en RB',        $stats['already_confirmed']],
                    ['Re-cortados OK',              $stats['reblocked_ok']],
                    ['Re-cortados con error',       $stats['reblocked_failed']],
                    ['Omitidos por backoff',        $stats['skipped_backoff']],
                    ['Agotados (acción manual)',    $stats['skipped_exhausted']],
                    ['Pendientes (dry-run)',        $stats['would_reblock']],
                ]
            );

            $this->info('Reconciliación de cortes completada.');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Error reconciliando cortes: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
