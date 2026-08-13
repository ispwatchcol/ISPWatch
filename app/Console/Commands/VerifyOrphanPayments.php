<?php

namespace App\Console\Commands;

use App\Services\BillingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Audita que todo el dinero recibido siga respaldando algo. No escribe nada.
 *
 * Nace de un caso real: se borró la mensualidad de julio de un cliente —que ya
 * estaba pagada— para reemplazarla por otra con otro precio. El borrado devolvió
 * los $52.500 como saldo a favor, unos ajustes manuales posteriores dejaron ese
 * saldo en cero, y el pago quedó sin respaldar nada. Nadie se enteró: el recaudo
 * seguía en la lista de Recaudos y el cliente pasó a figurar debiendo.
 *
 * Ninguna de las auditorías que ya existían lo habría visto: `billing:verify-monthly`
 * mira si se generaron facturas y `billing:verify-cuts` si se cortó a quien debía.
 * Que el dinero recibido siga cuadrando no lo miraba nadie.
 */
class VerifyOrphanPayments extends Command
{
    /**
     * php artisan billing:verify-orphan-payments
     * php artisan billing:verify-orphan-payments --tenant=3
     * php artisan billing:verify-orphan-payments --min=10000 --no-mail
     */
    protected $signature = 'billing:verify-orphan-payments
                            {--tenant= : Limitar la auditoría a un tenant}
                            {--min=0 : Ignorar descuadres menores a este importe}
                            {--limit=40 : Cuántos clientes listar en consola}
                            {--no-mail : No enviar el email de alerta (solo log/consola)}';

    protected $description = 'Audita que todo el dinero recibido siga respaldando una factura o un saldo a favor, y alerta de los clientes descuadrados. No modifica nada.';

    public function __construct(protected BillingService $billingService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;
        $minimo   = (float) $this->option('min');

        $filas = collect($this->billingService->auditOrphanPayments($tenantId))
            ->filter(fn ($f) => $f['suelto'] >= $minimo)
            ->values();

        if ($filas->isEmpty()) {
            $this->info('✓ Todo el dinero recibido está aplicado a facturas o figura como saldo a favor.');
            return Command::SUCCESS;
        }

        $limite = (int) $this->option('limit');

        $this->table(
            ['Cliente', 'Pagos', 'Recibido', 'En facturas', 'En saldo a favor', 'SIN RESPALDO'],
            $filas->take($limite)->map(fn ($f) => [
                $f['cliente'],
                $f['pagos'],
                $this->money($f['recibido']),
                $this->money($f['en_facturas']),
                $this->money($f['en_saldo']),
                $this->money($f['suelto']),
            ])->all()
        );

        if ($filas->count() > $limite) {
            $this->line("… y {$this->plural($filas->count() - $limite, 'cliente más', 'clientes más')} (usa --limit para ver más).");
        }

        $total = $filas->sum('suelto');

        $resumen = sprintf(
            '%s con dinero recibido que no respalda ninguna factura ni figura como saldo a favor. Total: $%s.',
            $this->plural($filas->count(), 'cliente', 'clientes'),
            $this->money($total)
        );

        // Log siempre (greppable). El exit-code != 0 deja que un monitor externo
        // también lo capte, igual que en billing:verify-monthly.
        Log::error('[BILLING-ORPHAN-MONEY] ' . $resumen);
        $this->error($resumen);

        if (!$this->option('no-mail')) {
            $this->sendAlertEmail($filas, $total);
        }

        return Command::FAILURE;
    }

    protected function sendAlertEmail($filas, float $total): void
    {
        $to = config('mail.billing_alert_address') ?: config('mail.from.address');
        if (!$to) {
            $this->warn('Sin destinatario de alerta (mail.billing_alert_address / mail.from.address). Solo se registró en el log.');
            return;
        }

        $lineas = $filas->take(30)->map(fn ($f) => sprintf(
            '  • %s — recibido $%s, en facturas $%s, en saldo $%s → sin respaldo $%s',
            $f['cliente'],
            $this->money($f['recibido']),
            $this->money($f['en_facturas']),
            $this->money($f['en_saldo']),
            $this->money($f['suelto'])
        ))->implode("\n");

        $body = 'ALERTA DE CAJA — ' . now()->toDateTimeString() . "\n\n"
              . 'Dinero recibido que hoy no respalda ninguna factura ni figura como saldo a favor.' . "\n"
              . 'Total descuadrado: $' . $this->money($total) . ' en ' . $this->plural($filas->count(), 'cliente', 'clientes') . ".\n\n"
              . $lineas . "\n\n"
              . "Causa más habitual:\n"
              . "  Se eliminó una factura YA PAGADA. El borrado devuelve el dinero como saldo a favor,\n"
              . "  pero si después se ajusta ese saldo a mano, o se crea la factura de reemplazo sin\n"
              . "  aplicarlo, el pago queda sin respaldar nada.\n\n"
              . "Qué revisar por cada cliente:\n"
              . "  1) Recaudos filtrando por ese cliente: el pago sigue ahí, mira a qué factura apunta.\n"
              . "  2) audit_logs con action='invoice.deleted' por si le borraron una factura pagada.\n"
              . "  3) customer_credits del cliente: si hay 'adjusted' negativos, alguien bajó el saldo a mano.\n\n"
              . "Cómo se corrige (a mano, con criterio — este comando NO toca nada):\n"
              . "  Devolver el importe al saldo a favor del cliente y dejar que cubra su factura pendiente,\n"
              . "  o reasignar el pago a la factura que corresponda.\n";

        try {
            Mail::raw($body, function ($m) use ($to, $filas) {
                $m->to($to)->subject('⚠️ ISPWatch: dinero recibido sin respaldo en ' . $this->plural($filas->count(), 'cliente', 'clientes'));
            });
            $this->info("Alerta enviada a {$to}.");
        } catch (\Throwable $e) {
            Log::error('[BILLING-ORPHAN-MONEY] No se pudo enviar el email de alerta: ' . $e->getMessage());
            $this->warn('No se pudo enviar el email de alerta (ver log): ' . $e->getMessage());
        }
    }

    private function money(float $n): string
    {
        return number_format($n, 0, ',', '.');
    }

    private function plural(int $n, string $singular, string $plural): string
    {
        return $n . ' ' . ($n === 1 ? $singular : $plural);
    }
}
