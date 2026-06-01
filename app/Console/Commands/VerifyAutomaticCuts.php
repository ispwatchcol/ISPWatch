<?php

namespace App\Console\Commands;

use App\Services\OverdueSuspensionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class VerifyAutomaticCuts extends Command
{
    /**
     * php artisan billing:verify-cuts
     * php artisan billing:verify-cuts --router=45
     * php artisan billing:verify-cuts --no-mail
     */
    protected $signature = 'billing:verify-cuts
                            {--router= : Limitar la auditoría a un router}
                            {--no-mail : No enviar el email de alerta (solo log/consola)}';

    protected $description = 'Audita que los cortes automáticos estén ocurriendo y alerta si un router de Corte Automático dejó clientes morosos sin cortar tras su día/hora de corte. (Un router sin cut_day = corte apagado a propósito, no alerta.) No corta a nadie.';

    public function __construct(protected OverdueSuspensionService $suspensionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $routerId = $this->option('router') ? (int) $this->option('router') : null;

        $rows = $this->suspensionService->auditAutomaticCuts($routerId);

        if (empty($rows)) {
            $this->info('No hay routers de Corte Automático para auditar.');
            return Command::SUCCESS;
        }

        $this->table(
            ['Router', 'Tenant', 'Día corte', 'Hora', 'Umbral', '¿Toca?', 'Morosos sin cortar', 'Estado'],
            collect($rows)->map(fn ($r) => [
                "#{$r['router_id']} {$r['router_name']}",
                $r['tenant_id'],
                $r['cut_day'] ?? '—',
                $r['cut_time'],
                $r['threshold'],
                $r['due'] ? 'sí' : 'no',
                $r['still_eligible'],
                strtoupper($r['status']),
            ])->all()
        );

        $problems = collect($rows)->where('status', 'cut_failing')->values();

        if ($problems->isEmpty()) {
            $this->info('✓ Cortes automáticos verificados: nada pendiente sin cortar.');
            return Command::SUCCESS;
        }

        $lines = $problems->map(function ($r) {
            return "• Router #{$r['router_id']} {$r['router_name']} (tenant {$r['tenant_id']}): CORTE NO APLICADO"
                 . " — {$r['still_eligible']} cliente(s) con ≥ {$r['threshold']} factura(s) vencida(s) siguen activos"
                 . " pese a que ya pasó el día/hora de corte.";
        });

        Log::error('[CUT-NOSHOW] ' . $problems->count() . ' router(s) con corte sin aplicar tras su día/hora de corte. ' . $lines->implode(' '));
        $this->error($lines->implode("\n"));

        if (!$this->option('no-mail')) {
            $this->sendAlertEmail($lines, $problems->count());
        }

        return Command::FAILURE;
    }

    /**
     * @param \Illuminate\Support\Collection<int,string> $lines
     */
    protected function sendAlertEmail($lines, int $count): void
    {
        $to = config('mail.billing_alert_address') ?: config('mail.from.address');
        if (!$to) {
            $this->warn('Sin destinatario de alerta (mail.billing_alert_address / mail.from.address). Solo se registró en el log.');
            return;
        }

        $body = 'ALERTA DE CORTE AUTOMÁTICO — ' . now()->toDateTimeString() . "\n\n"
              . "Routers de Corte Automático con problema:\n\n"
              . $lines->implode("\n") . "\n\n"
              . "Qué revisar:\n"
              . "  1) Que el scheduler esté corriendo (worker con schedule:work).\n"
              . "  2) Config del router: cut_day / cut_time / overdue_invoices.\n"
              . "  3) suspension_action_logs (fallos de corte en la RB con backoff).\n"
              . "  4) Corte manual: 'php artisan billing:auto-cut --router=ID'.\n";

        try {
            Mail::raw($body, function ($msg) use ($to, $count) {
                $msg->to($to)->subject("⚠️ ISPWatch: corte automático con problemas en {$count} router(s)");
            });
            $this->info("Alerta enviada a {$to}.");
        } catch (\Throwable $e) {
            Log::error('[CUT-NOSHOW] No se pudo enviar el email de alerta: ' . $e->getMessage());
            $this->warn('No se pudo enviar el email de alerta (ver log): ' . $e->getMessage());
        }
    }
}
