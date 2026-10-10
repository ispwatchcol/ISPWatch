<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\BooksAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Cierre de libros: corre TODAS las invariantes contables y dice si la
 * contabilidad del sistema cierra.
 *
 * No escribe nada en la base. Se puede correr contra producción en cualquier
 * momento, y está pensado para correrse solo (scheduler) para que un descuadre
 * lo descubra el sistema y no el cliente con un Excel en la mano.
 *
 * Código de salida:
 *   0 → los libros cierran (o sólo hay avisos, con --warnings-ok)
 *   1 → hay al menos un hallazgo crítico
 */
class AuditBooks extends Command
{
    /**
     * php artisan billing:audit-books
     * php artisan billing:audit-books --tenant=19
     * php artisan billing:audit-books --tenant=19 --detail=C1 --limit=50
     * php artisan billing:audit-books --json > cierre.json
     */
    protected $signature = 'billing:audit-books
                            {--tenant= : Limitar la auditoría a una empresa}
                            {--detail= : Ver el detalle de un hallazgo (C1, C6, …). Varios separados por coma}
                            {--limit=25 : Filas de detalle a mostrar}
                            {--warnings-ok : Salir con 0 aunque haya avisos (sólo fallan los críticos)}
                            {--json : Volcar el informe completo en JSON}
                            {--mail : Enviar el informe por correo si hay hallazgos}';

    protected $description = 'Audita todas las invariantes contables (facturas, pagos, saldo a favor, arrastre) y reporta cualquier descuadre. No modifica nada.';

    public function __construct(protected BooksAuditService $auditor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;

        if ($tenantId && !Tenant::query()->whereKey($tenantId)->exists()) {
            $this->error("No existe la empresa #{$tenantId}.");
            return Command::FAILURE;
        }

        $hallazgos = $this->auditor->run($tenantId);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'tenant_id'  => $tenantId,
                'ran_at'     => now()->toIso8601String(),
                'findings'   => $hallazgos,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $this->exitCode($hallazgos);
        }

        $ambito = $tenantId ? "empresa #{$tenantId}" : 'todas las empresas';
        $this->line('');
        $this->line("  Cierre de libros — {$ambito} — " . now()->toDateTimeString());
        $this->line('');

        if (empty($hallazgos)) {
            $this->info('  ✓ Los libros cierran. Ninguna invariante contable está rota.');
            $this->line('');
            return Command::SUCCESS;
        }

        $this->table(
            ['', 'Comprobación', 'Casos', 'Importe expuesto'],
            collect($hallazgos)->map(fn ($h) => [
                $this->icono($h['severity']),
                $h['code'] . ' · ' . $h['title'],
                number_format($h['count']),
                '$' . $this->money($h['amount']),
            ])->all()
        );

        foreach ($hallazgos as $h) {
            $this->line('');
            $this->line("  <options=bold>{$h['code']} · {$h['title']}</>");
            $this->line('  ' . wordwrap($h['explain'], 100, "\n  "));
        }

        $this->mostrarDetalle($hallazgos);

        $criticos = collect($hallazgos)->where('severity', 'critical');

        $resumen = sprintf(
            '%d comprobación(es) con hallazgos (%d crítica(s)). Importe expuesto en críticos: $%s.',
            count($hallazgos),
            $criticos->count(),
            $this->money($criticos->sum('amount'))
        );

        $this->line('');
        $criticos->isEmpty() ? $this->warn('  ' . $resumen) : $this->error('  ' . $resumen);
        $this->line('  Detalle de un hallazgo:  php artisan billing:audit-books'
            . ($tenantId ? " --tenant={$tenantId}" : '') . ' --detail=C1');
        $this->line('');

        Log::log($criticos->isEmpty() ? 'warning' : 'error', '[BOOKS-AUDIT] ' . $resumen, [
            'tenant_id' => $tenantId,
            'findings'  => collect($hallazgos)->map(fn ($h) => [
                'code' => $h['code'], 'count' => $h['count'], 'amount' => $h['amount'],
            ])->all(),
        ]);

        if ($this->option('mail')) {
            $this->enviarCorreo($hallazgos, $tenantId, $resumen);
        }

        return $this->exitCode($hallazgos);
    }

    /**
     * Sólo los críticos hacen fallar por defecto. Un aviso (dos pantallas que no
     * coinciden) no debería teñir de rojo un tablero de monitoreo todas las
     * noches: si lo hiciera, se acabaría silenciando el comando entero y con él
     * los críticos.
     */
    protected function exitCode(array $hallazgos): int
    {
        $hayCriticos = collect($hallazgos)->contains('severity', 'critical');

        if ($hayCriticos) {
            return Command::FAILURE;
        }

        return ($hallazgos && !$this->option('warnings-ok')) ? Command::FAILURE : Command::SUCCESS;
    }

    protected function mostrarDetalle(array $hallazgos): void
    {
        $pedidos = array_filter(array_map(
            'trim',
            explode(',', strtoupper((string) $this->option('detail')))
        ));

        if (!$pedidos) {
            return;
        }

        $limite = (int) $this->option('limit');

        foreach ($hallazgos as $h) {
            if (!in_array($h['code'], $pedidos, true) || empty($h['rows'])) {
                continue;
            }

            $this->line('');
            $this->line("  <options=bold>Detalle de {$h['code']} · {$h['title']}</>");

            $filas = array_slice($h['rows'], 0, $limite);

            $this->table(
                array_keys($filas[0]),
                array_map(fn ($f) => array_map(
                    fn ($v) => is_null($v) ? '—' : (is_numeric($v) && !is_int($v) ? $this->money((float) $v) : (string) $v),
                    $f
                ), $filas)
            );

            if (count($h['rows']) > $limite) {
                $this->line('  … ' . (count($h['rows']) - $limite) . ' fila(s) más (sube --limit).');
            }

            if ($h['count'] > count($h['rows'])) {
                $this->line('  Nota: el detalle está recortado a ' . BooksAuditService::MAX_DETALLE
                    . " filas; el conteo ({$h['count']}) y el importe sí son sobre el total.");
            }
        }
    }

    protected function enviarCorreo(array $hallazgos, ?int $tenantId, string $resumen): void
    {
        $to = config('mail.billing_alert_address') ?: config('mail.from.address');

        if (!$to) {
            $this->warn('  Sin destinatario de alerta configurado; sólo quedó en el log.');
            return;
        }

        $cuerpo = 'CIERRE DE LIBROS — ' . now()->toDateTimeString() . "\n"
                . 'Ámbito: ' . ($tenantId ? "empresa #{$tenantId}" : 'todas las empresas') . "\n\n"
                . $resumen . "\n\n"
                . collect($hallazgos)->map(fn ($h) => sprintf(
                    "[%s] %s · %s\n    %d caso(s), $%s expuestos\n    %s",
                    strtoupper($h['severity']),
                    $h['code'],
                    $h['title'],
                    $h['count'],
                    $this->money($h['amount']),
                    $h['explain']
                ))->implode("\n\n") . "\n\n"
                . "Para ver el detalle:\n"
                . '    php artisan billing:audit-books'
                . ($tenantId ? " --tenant={$tenantId}" : '') . " --detail=C1,C6\n";

        try {
            Mail::raw($cuerpo, function ($m) use ($to, $hallazgos) {
                $criticos = collect($hallazgos)->where('severity', 'critical')->count();
                $m->to($to)->subject(
                    ($criticos ? "[CRÍTICO] {$criticos} descuadre(s) contable(s)" : '[Aviso] Cierre de libros')
                    . ' — ' . config('app.name')
                );
            });
            $this->line("  Informe enviado a {$to}.");
        } catch (\Throwable $e) {
            // Que no se caiga la auditoría por no poder avisar: el hallazgo ya
            // quedó en el log y en el código de salida.
            $this->warn('  No se pudo enviar el correo: ' . $e->getMessage());
            Log::warning('[BOOKS-AUDIT] fallo al enviar el informe', ['error' => $e->getMessage()]);
        }
    }

    protected function icono(string $severidad): string
    {
        return match ($severidad) {
            'critical' => '<fg=red>✗</>',
            'warning'  => '<fg=yellow>!</>',
            default    => '<fg=blue>i</>',
        };
    }

    protected function money(float $n): string
    {
        return number_format($n, 2, ',', '.');
    }
}
