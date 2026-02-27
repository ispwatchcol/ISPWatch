<?php

namespace App\Console\Commands;

use App\Services\OverdueSuspensionService;
use Illuminate\Console\Command;

class AutoCutByRouter extends Command
{
    /**
     * php artisan billing:auto-cut
     * php artisan billing:auto-cut --router=5
     */
    protected $signature = 'billing:auto-cut
                            {--router= : ID del router a procesar (opcional, procesa todos si no se especifica)}';

    protected $description = 'Ejecuta el corte automático masivo por router según configuración de facturación (cut_day, cut_time, overdue_invoices)';

    public function __construct(protected OverdueSuspensionService $suspensionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $routerId = $this->option('router') ? (int) $this->option('router') : null;

        if ($routerId) {
            $this->info("Ejecutando corte automático para router #{$routerId}...");
        } else {
            $this->info('Ejecutando corte automático para todos los routers...');
        }

        try {
            $stats = $this->suspensionService->processOverdueInvoices($routerId);

            $this->table(
                ['Acción', 'Cantidad'],
                [
                    ['Routers procesados', $stats['routers_processed']],
                    ['Suspendidos (Auto)', $stats['suspended']],
                    ['Pendiente Manual', $stats['manual_pending']],
                    ['Sin Acción', $stats['no_action']],
                    ['Errores', $stats['errors']],
                ]
            );

            $this->info('Corte automático completado.');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Error ejecutando el corte automático: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
