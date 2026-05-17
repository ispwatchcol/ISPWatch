<?php

namespace App\Console\Commands;

use App\Services\PaymentReminderService;
use Illuminate\Console\Command;

class SendPaymentReminders extends Command
{
    protected $signature = 'billing:send-reminders';

    protected $description = 'Envía recordatorios de pago automáticos según billing.payment_reminder de cada router';

    public function __construct(protected PaymentReminderService $reminderService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Enviando recordatorios de pago...');

        try {
            $stats = $this->reminderService->sendDueReminders();

            $this->table(
                ['Métrica', 'Valor'],
                [
                    ['Routers procesados', $stats['routers_processed']],
                    ['Recordatorios enviados', $stats['reminded']],
                    ['Routers no vencidos aún', $stats['skipped_not_due']],
                    ['Errores', $stats['errors']],
                ]
            );

            $this->info('Recordatorios completados.');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Error enviando recordatorios: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
