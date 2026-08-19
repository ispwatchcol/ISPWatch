<?php

namespace App\Console\Commands;

use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Services\BillingService;
use Illuminate\Console\Command;

/**
 * Repara de una sola pasada a los clientes que pagaron pero se quedaron
 * marcados como suspendidos.
 *
 * Por qué existe: hasta este arreglo, la reconexión automática al pagar solo
 * se disparaba si el último log del router era un SUSPEND/success. Un corte
 * que quedó en failed (el equipo no respondió, pero la BD sí quedó suspendida)
 * dejaba al cliente atrapado: pagaba, seguía en 'suspendido' y el
 * reconciliador —que barre por status=false— lo volvía a cortar en la RB.
 *
 * El arreglo cubre los pagos NUEVOS. Este comando limpia el pasivo: recorre a
 * los suspendidos en la BD y le pasa cada uno por la misma verificación de
 * BillingService::reactivateIfCleared(), así que se aplican exactamente las
 * mismas reglas (sigue debiendo → no se toca; retirado/cancelado → no se toca).
 *
 * Arranca en dry-run: sin --apply solo informa.
 */
class RepairPaidSuspendedCustomers extends Command
{
    protected $signature = 'billing:repair-paid-suspended
                            {--tenant= : Limitar a un tenant}
                            {--apply : Aplicar los cambios (sin este flag solo reporta)}';

    protected $description = 'Reconecta a los clientes que ya pagaron pero se quedaron marcados como suspendidos';

    public function __construct(protected BillingService $billing)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;
        $apply    = (bool) $this->option('apply');

        // service_status NULL entra: reactivateIfCleared trata el vacío como
        // cliente normal, no como baja. Lo que queda fuera es retirado/cancelado.
        //
        // Se exige además **al menos una factura**. Este comando repara a quien
        // saldó su cuenta y se quedó marcado suspendido; un cliente sin ninguna
        // factura nunca estuvo en mora, así que "quedó al día" no significa nada
        // para él — pasaría el filtro de vencidas sin haber pagado jamás. Si está
        // cortado es por una decisión de operación que este barrido no puede
        // interpretar, y se reactiva a mano desde su ficha.
        //
        // Ojo: el filtro NO es `exclude_from_billing`. Un cliente marcado "no
        // facturar" puede tener facturas viejas ya pagadas (se le excluyó
        // después), y ése sí saldó su cuenta y sí entra.
        $query = CustomerProfile::where(function ($q) {
                $q->where('status', false)->orWhere('service_status', 'suspendido');
            })
            ->where(function ($q) {
                $q->whereIn('service_status', CustomerProfile::BILLABLE_SERVICE_STATUSES)
                  ->orWhereNull('service_status');
            })
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('invoices')
                ->whereColumn('invoices.customer_id', 'customer_profile.user_id'));

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $profiles = $query->get();

        $this->info("Revisando {$profiles->count()} cliente(s) suspendido(s)"
            . ($tenantId ? " del tenant #{$tenantId}" : '')
            . ($apply ? '' : ' [dry-run — usa --apply para ejecutar]') . '...');

        $rows       = [];
        $reconnected = 0;
        $stillOwing  = 0;
        $routerFails = 0;

        foreach ($profiles as $profile) {
            $overdue = Invoice::withoutGlobalScopes()
                ->where('customer_id', $profile->user_id)
                ->where('due_date', '<', now())
                ->where('balance_due', '>', 0)
                ->whereNotIn('status', ['void', 'cancelled', 'paid'])
                ->count();

            if ($overdue > 0) {
                $stillOwing++;
                continue; // debe: el corte se queda, no es candidato
            }

            $nombre = trim("{$profile->name} {$profile->last_name}");
            // Se muestra porque cambia la lectura del caso: un "no facturar"
            // reconectado no volverá a cortarse solo, no habrá factura que lo
            // dispare. Que el operador lo vea en vez de decidirlo el comando.
            $noFactura = $profile->exclude_from_billing ? 'sí' : '—';

            if (!$apply) {
                $rows[] = [$profile->user_id, $nombre, $profile->service_status, $noFactura, 'se reconectaría'];
                $reconnected++;
                continue;
            }

            $result = $this->billing->reactivateIfCleared((int) $profile->user_id);

            if ($result['reactivated'] && $result['router_ok']) {
                $reconnected++;
                $rows[] = [$profile->user_id, $nombre, $profile->service_status, $noFactura, 'reconectado'];
            } elseif ($result['reactivated']) {
                $reconnected++;
                $routerFails++;
                $rows[] = [$profile->user_id, $nombre, $profile->service_status, $noFactura, 'activo en BD — router NO confirmó'];
            } else {
                $rows[] = [$profile->user_id, $nombre, $profile->service_status, $noFactura, 'sin cambios: ' . ($result['message'] ?: 'no aplicaba')];
            }
        }

        if ($rows) {
            $this->table(['Cliente', 'Nombre', 'Estado previo', 'No facturar', 'Resultado'], $rows);
        }

        $this->info("Al día y reconectados: {$reconnected}");
        $this->info("Siguen debiendo (no se tocan): {$stillOwing}");

        if ($routerFails > 0) {
            $this->warn("{$routerFails} cliente(s) quedaron activos en la BD pero el router no confirmó la reconexión. "
                . 'Revísalos en Acciones masivas → reconexiones fallidas.');
        }

        return Command::SUCCESS;
    }
}
