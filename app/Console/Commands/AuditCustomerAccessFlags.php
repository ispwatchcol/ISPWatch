<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cuenta las fichas donde `status` (is_enabled) y `service_status` dicen cosas
 * distintas sobre si el cliente tiene servicio (KAN-117). No escribe nada.
 *
 * ISPWatch considera cortado a un cliente si `status = false` O si
 * `service_status = 'suspendido'`. Todos los caminos actuales mueven los dos
 * juntos, pero hay datos anteriores a la columna `service_status` donde no
 * coinciden, y un AAA externo que decida sólo con `service_status` le daría
 * acceso a quien ISPWatch tiene por cortado.
 *
 * Usa COUNT(*) real: las estimaciones de pg_stat ya dieron falsos positivos.
 * Qué valor manda en cada caso es decisión del ISP; por eso no hay --fix.
 */
class AuditCustomerAccessFlags extends Command
{
    /**
     * php artisan customers:audit-access-flags
     * php artisan customers:audit-access-flags --tenant=19 --list
     */
    protected $signature = 'customers:audit-access-flags
                            {--tenant= : Limitar la auditoría a un tenant}
                            {--list : Listar los clientes de cada caso}';

    protected $description = 'Cuenta los clientes con is_enabled y service_status desalineados. No modifica nada.';

    /**
     * Caso => [condición, qué significa]. `service_status` vacío o nulo se trata
     * como 'activo', igual que CustomerProfile::hasBillableServiceStatus().
     */
    private const CASES = [
        'deshabilitado_pero_activo' => [
            "customer_profile.status = false AND COALESCE(NULLIF(customer_profile.service_status, ''), 'activo') IN ('activo', 'gratis')",
            'ISPWatch lo tiene por cortado; un AAA que sólo mire service_status le da acceso',
        ],
        'habilitado_pero_suspendido' => [
            "customer_profile.status = true AND customer_profile.service_status = 'suspendido'",
            'El panel lo muestra suspendido; el auto-corte lo vuelve a tomar',
        ],
        'habilitado_pero_de_baja' => [
            "customer_profile.status = true AND customer_profile.service_status IN ('retirado', 'cancelado')",
            'Baja definitiva con is_enabled = true',
        ],
    ];

    public function handle(): int
    {
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;
        $total    = 0;
        $rows     = [];

        foreach (self::CASES as $case => [$condition, $meaning]) {
            $query = DB::table('customer_profile')
                ->join('users', 'users.id', '=', 'customer_profile.user_id')
                ->whereRaw($condition)
                ->when($tenantId, fn ($q) => $q->where('users.tenant_id', $tenantId));

            $count  = (clone $query)->count();
            $total += $count;
            $rows[] = [$case, $count, $meaning];

            if ($this->option('list') && $count > 0) {
                $this->line("<comment>{$case}</comment>");
                $this->table(
                    ['Tenant', 'Cliente', 'Nombre', 'status', 'service_status', 'router_id'],
                    $query->orderBy('users.tenant_id')->orderBy('customer_profile.user_id')
                        ->get([
                            'users.tenant_id', 'customer_profile.user_id', 'customer_profile.name',
                            'customer_profile.last_name', 'customer_profile.status',
                            'customer_profile.service_status', 'customer_profile.router_id',
                        ])
                        ->map(fn ($r) => [
                            $r->tenant_id, $r->user_id, trim("{$r->name} {$r->last_name}"),
                            $r->status ? 'true' : 'false', $r->service_status ?? '(null)', $r->router_id,
                        ])
                        ->all()
                );
            }
        }

        $this->table(['Caso', 'Clientes', 'Qué significa'], $rows);

        if ($total === 0) {
            $this->info('✓ is_enabled y service_status coinciden en todas las fichas.');
            return Command::SUCCESS;
        }

        $this->warn("{$total} ficha(s) desalineadas. La corrección es decisión del ISP: no se aplica nada automáticamente.");

        return Command::FAILURE;
    }
}
