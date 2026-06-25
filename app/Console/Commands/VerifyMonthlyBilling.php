<?php

namespace App\Console\Commands;

use App\Services\BillingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class VerifyMonthlyBilling extends Command
{
    /**
     * php artisan billing:verify-monthly
     * php artisan billing:verify-monthly 2026-06
     * php artisan billing:verify-monthly --router=45
     * php artisan billing:verify-monthly --no-mail
     */
    protected $signature = 'billing:verify-monthly
                            {period? : YYYY-MM (por defecto, el periodo que toca hoy según cada router)}
                            {--router= : Limitar la auditoría a un router}
                            {--no-mail : No enviar el email de alerta (solo log/consola)}';

    protected $description = 'Audita que la facturación mensual realmente ocurrió y alerta si un router que debía facturar generó 0 (no-show) o quedó incompleto (parcial). No genera facturas.';

    public function __construct(protected BillingService $billingService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $period   = $this->argument('period');
        $routerId = $this->option('router') ? (int) $this->option('router') : null;

        $rows = $this->billingService->auditMonthlyBilling($period, $routerId);

        if (empty($rows)) {
            $this->info('No hay routers con configuración de facturación para auditar.');
            return Command::SUCCESS;
        }

        $this->table(
            ['Router', 'Tenant', 'Periodo', 'Día', 'Toca hoy', 'Esperadas', 'Generadas', 'Logs fallidos', 'Estado'],
            collect($rows)->map(fn ($r) => [
                "#{$r['router_id']} {$r['router_name']}",
                $r['tenant_id'],
                $r['period'],
                $r['create_day'],
                $r['due'] ? 'sí' : 'no',
                $r['expected'],
                $r['actual'],
                $r['failed_logs'],
                strtoupper($r['status']),
            ])->all()
        );

        $problems = collect($rows)->whereIn('status', ['no_show', 'partial'])->values();

        if ($problems->isEmpty()) {
            $this->info('✓ Facturación verificada: todos los routers que debían facturar lo hicieron.');
            return Command::SUCCESS;
        }

        $lines = $problems->map(function ($r) {
            $tag = $r['status'] === 'no_show' ? 'NO-SHOW (0 facturas)' : 'PARCIAL';
            return "• Router #{$r['router_id']} {$r['router_name']} (tenant {$r['tenant_id']}, {$r['period']}): {$tag}"
                 . " — esperadas {$r['expected']}, generadas {$r['actual']}, logs fallidos {$r['failed_logs']}.";
        });

        // Log siempre (greppable). El exit-code != 0 deja que un monitor externo también lo capte.
        Log::error('[BILLING-NOSHOW] ' . $problems->count() . ' router(s) sin facturar o incompletos. ' . $lines->implode(' '));
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

        $body = 'ALERTA DE FACTURACIÓN — ' . now()->toDateTimeString() . "\n\n"
              . "Routers que debían facturar y NO lo hicieron (o quedaron incompletos):\n\n"
              . $lines->implode("\n") . "\n\n"
              . "Qué revisar:\n"
              . "  1) Que el scheduler esté corriendo en el servidor (cron a 'php artisan schedule:run').\n"
              . "  2) El laravel.log alrededor de las 00:00 (hora de la app).\n"
              . "  3) La tabla billing_action_logs (fallos por cliente con backoff).\n"
              . "  4) Reintento manual: 'php artisan billing:retry-failed' o 'php artisan billing:generate-monthly'.\n";

        try {
            Mail::raw($body, function ($m) use ($to, $count) {
                $m->to($to)->subject("⚠️ ISPWatch: facturación no generada en {$count} router(s)");
            });
            $this->info("Alerta enviada a {$to}.");
        } catch (\Throwable $e) {
            Log::error('[BILLING-NOSHOW] No se pudo enviar el email de alerta: ' . $e->getMessage());
            $this->warn('No se pudo enviar el email de alerta (ver log): ' . $e->getMessage());
        }
    }
}
