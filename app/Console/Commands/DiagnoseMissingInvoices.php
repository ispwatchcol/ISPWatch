<?php

namespace App\Console\Commands;

use App\Models\CustomerProfile;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingService;
use App\Support\AuditContext;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Mensualidades que le corresponden a un cliente y no existen.
 *
 * Nace del incidente del 2026-10-01 (clientes sin la factura del periodo,
 * algunos con el pago ya como saldo a favor). Dos modos, y el primero es el
 * que corre por defecto:
 *
 *   SIMULACIÓN (sin --apply): NO escribe nada. Recorre a cada cliente del
 *   tenant con las mismas reglas que la corrida mensual y lista qué falta, por
 *   qué no le corresponde a los demás, y cuánto saldría cada factura con el
 *   saldo a favor que el cliente ya tiene. Imprime un HASH del plan.
 *
 *   REPARACIÓN (--apply): exige el tenant, el periodo, la lista EXPLÍCITA de
 *   clientes, un motivo y el hash que dio la simulación aprobada. Si entre la
 *   aprobación y la ejecución algo cambió (otro pago, otra factura, otro
 *   importe), el hash no coincide y no se toca nada.
 *
 * No hay modo «todos»: una reparación siempre nombra a sus clientes.
 */
class DiagnoseMissingInvoices extends Command
{
    protected $signature = 'billing:missing-invoices
                            {--tenant= : ID del tenant (obligatorio)}
                            {--period= : Periodo YYYY-MM (obligatorio)}
                            {--customer=* : IDs de cliente (user_id) a evaluar; obligatorio con --apply}
                            {--all : Listar también a los clientes a los que no les corresponde}
                            {--json : Salida en JSON}
                            {--apply : Emitir las facturas faltantes (requiere --plan-hash y --reason)}
                            {--plan-hash= : Hash de la simulación aprobada}
                            {--reason= : Motivo que queda en la nota de cada factura emitida}';

    protected $description = 'Diagnostica (y, con aprobación, repara) mensualidades faltantes de un tenant y periodo. Por defecto NO escribe nada.';

    public function handle(BillingService $billing): int
    {
        $tenantId = (int) $this->option('tenant');
        $period   = (string) $this->option('period');

        if (!$tenantId || !Tenant::whereKey($tenantId)->exists()) {
            $this->error('Indica un --tenant existente.');
            return self::INVALID;
        }

        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            $this->error('Indica --period en formato YYYY-MM.');
            return self::INVALID;
        }

        $periodMonth = Carbon::parse("{$period}-01")->startOfDay();
        $customerIds = array_values(array_unique(array_map('intval', (array) $this->option('customer'))));
        $apply       = (bool) $this->option('apply');

        if ($apply && (empty($customerIds) || !$this->option('plan-hash') || !trim((string) $this->option('reason')))) {
            $this->error('--apply exige --customer (uno o más), --plan-hash de la simulación aprobada y --reason.');
            return self::INVALID;
        }

        $profiles = $this->profilesOf($tenantId, $customerIds);

        if ($profiles === null) {
            return self::INVALID;
        }

        $routers = Router::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->with('billingConfig')
            ->get()
            ->keyBy('id');

        $rows = $profiles->map(function (CustomerProfile $profile) use ($billing, $routers, $periodMonth, $tenantId) {
            $router = $routers->get($profile->router_id);

            if (!$router) {
                return [
                    'tenant_id'     => $tenantId,
                    'customer_id'   => (int) $profile->user_id,
                    'customer_name' => trim("{$profile->name} {$profile->last_name}"),
                    'router_id'     => $profile->router_id,
                    'period'        => $periodMonth->format('Y-m'),
                    'decision'      => 'not_applicable',
                    'reason'        => 'no_router',
                    'detail'        => 'Sin router de este tenant: la corrida mensual no lo recorre.',
                    'preview'       => null,
                ];
            }

            return $billing->explainMonthlyInvoice($profile, $router, $periodMonth);
        })->values()->all();

        $missing = array_values(array_filter($rows, fn ($r) => $r['decision'] === 'missing'));
        $hash    = $this->planHash($missing);

        if (!$apply) {
            $this->report($rows, $missing, $hash);
            return self::SUCCESS;
        }

        if (!hash_equals($hash, (string) $this->option('plan-hash'))) {
            $this->error('El plan cambió desde la simulación aprobada (hash distinto). No se escribió nada.');
            $this->line("Hash actual: {$hash}");
            $this->report($rows, $missing, $hash);
            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason'));
        $notes  = 'Emitida el ' . now()->format('Y-m-d') . " por reparación de mensualidades faltantes ({$period}): {$reason}";

        $results = AuditContext::as(AuditContext::SOURCE_CONSOLE, function () use ($missing, $profiles, $routers, $billing, $periodMonth, $notes) {
            $out = [];

            foreach ($missing as $row) {
                $profile = $profiles->firstWhere('user_id', $row['customer_id'])->fresh();
                $out[]   = $billing->repairMissingMonthlyInvoice($profile, $routers->get($profile->router_id), $periodMonth, $notes);
            }

            return $out;
        });

        foreach ($results as $r) {
            $this->line(sprintf(
                '%s cliente %d: %s',
                $r['applied'] ? 'EMITIDA' : 'OMITIDA',
                $r['customer_id'],
                $r['applied'] ? "factura #{$r['invoice_id']}" : $r['detail'],
            ));
        }

        // Verificación posterior: lo emitido ya no puede salir como faltante.
        $after = $profiles->filter(fn ($p) => in_array((int) $p->user_id, array_column($missing, 'customer_id'), true))
            ->map(fn ($p) => $billing->explainMonthlyInvoice($p->fresh(), $routers->get($p->router_id), $periodMonth))
            ->values()->all();

        $stillMissing = array_filter($after, fn ($r) => $r['decision'] === 'missing');

        $this->newLine();
        $this->info('Verificación posterior: ' . count($after) . ' cliente(s) revisados, '
            . count($stillMissing) . ' siguen sin mensualidad.');

        return empty($stillMissing) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Perfiles del tenant. Con --customer, exactamente esos y todos del tenant:
     * un id ajeno o inexistente es un error, nunca se ignora en silencio.
     */
    private function profilesOf(int $tenantId, array $customerIds)
    {
        // El tenant del perfil, con el del usuario como respaldo para filas
        // viejas sin la columna (el mismo criterio que la primera factura).
        $query = CustomerProfile::query()
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)
                    ->orWhere(fn ($q) => $q->whereNull('tenant_id')
                        ->whereIn('user_id', User::withoutGlobalScopes()->where('tenant_id', $tenantId)->select('id')));
            })
            ->with('user:id,created_at')
            ->orderBy('user_id');

        if (!empty($customerIds)) {
            $query->whereIn('user_id', $customerIds);
        }

        $profiles = $query->get();

        $faltan = array_diff($customerIds, $profiles->pluck('user_id')->map(fn ($id) => (int) $id)->all());

        if (!empty($faltan)) {
            $this->error('Estos clientes no existen en el tenant ' . $tenantId . ': ' . implode(', ', $faltan) . '. No se evaluó nada.');
            return null;
        }

        return $profiles;
    }

    /** Hash estable de lo que se va a emitir: cliente, periodo, importes y fechas. */
    private function planHash(array $missing): string
    {
        $plan = array_map(fn ($r) => [
            $r['tenant_id'], $r['customer_id'], $r['period'],
            $r['preview']['period_start'], $r['preview']['period_end'], $r['preview']['due_date'],
            $r['preview']['total'], $r['preview']['credit_before'], $r['preview']['credit_to_apply'],
            $r['preview']['balance_due'],
        ], $missing);

        usort($plan, fn ($a, $b) => $a[1] <=> $b[1]);

        return hash('sha256', json_encode($plan));
    }

    private function report(array $rows, array $missing, string $hash): void
    {
        if ($this->option('json')) {
            $this->line(json_encode(['plan_hash' => $hash, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return;
        }

        $this->table(
            ['Cliente', 'Nombre', 'Periodo', 'Total', 'Crédito antes', 'Crédito a aplicar', 'Saldo factura', 'Crédito después', 'Aviso', 'Vence'],
            array_map(fn ($r) => [
                $r['customer_id'], $r['customer_name'], $r['period'],
                $r['preview']['total'], $r['preview']['credit_before'], $r['preview']['credit_to_apply'],
                $r['preview']['balance_due'], $r['preview']['credit_after'],
                $r['preview']['will_notify'] ? 'sí' : 'no', $r['preview']['due_date'],
            ], $missing)
        );

        $others = array_filter($rows, fn ($r) => $r['decision'] !== 'missing'
            && ($this->option('all') || !empty($this->option('customer')) || $r['decision'] === 'present' && $r['reason'] === 'invoice_voided'));

        if (!empty($others)) {
            $this->table(
                ['Cliente', 'Nombre', 'Decisión', 'Motivo', 'Detalle'],
                array_map(fn ($r) => [$r['customer_id'], $r['customer_name'], $r['decision'], $r['reason'], $r['detail']], $others)
            );
        }

        $this->info(count($missing) . ' mensualidad(es) faltante(s) de ' . count($rows) . ' cliente(s) evaluados.');
        $this->line("plan-hash: {$hash}");
        $this->comment('Simulación: no se escribió nada.');
    }
}
