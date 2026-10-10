<?php

namespace App\Console\Commands;

use App\Services\PaymentMethodLinker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciliación de pagos con su forma de pago del catálogo (KAN-109).
 *
 * Sin `--apply` sólo reporta, y funciona también antes de la migración: es el
 * inventario previo al despliegue que pide la tarjeta (qué tenants y qué
 * nombres quedarían sin enlazar). La migración ya hace el mismo enlace al
 * desplegar; con `--apply` después sirve para recoger pagos que entraron por
 * código viejo durante el despliegue. Correrlo varias veces no cambia nada.
 *
 * Lo que NO hace, a propósito: enlazar un nombre viejo con la forma de pago
 * renombrada. No hay registro de qué nombre anterior corresponde a qué forma de
 * pago actual, y decidirlo cambia la contabilidad de esos pagos: es una
 * decisión del ISP, no del comando. Esos pagos se reportan como
 * «sin coincidencia» y conservan su texto.
 */
class LinkPaymentMethods extends Command
{
    /**
     * php artisan payments:link-methods
     * php artisan payments:link-methods --tenant=3
     * php artisan payments:link-methods --apply
     */
    protected $signature = 'payments:link-methods
                            {--tenant= : Limitar a un tenant}
                            {--apply : Escribir los enlaces (sin esto sólo reporta)}';

    protected $description = 'Enlaza los pagos con su forma de pago del catálogo por nombre exacto y único, y reporta por tenant los que quedan con su texto histórico. Sin --apply no modifica nada.';

    public function handle(PaymentMethodLinker $linker): int
    {
        $apply    = (bool) $this->option('apply');
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;

        if ($apply && !Schema::hasColumn('payments', 'payment_method_id')) {
            $this->error('Falta la columna payments.payment_method_id: corre las migraciones antes de usar --apply.');
            return Command::FAILURE;
        }

        // Que quien lo corre vea contra qué base y esquema va a escribir antes
        // de leer el reporte (public = producción, ispwatch_dev = desarrollo).
        $connection = DB::getDefaultConnection();
        $this->line(sprintf(
            'Base: %s · conexión %s · esquema %s · %s',
            DB::connection()->getDatabaseName(),
            $connection,
            config("database.connections.{$connection}.schema") ?? '—',
            $apply ? 'MODO ESCRITURA' : 'sólo lectura'
        ));

        $plan = $linker->plan($tenantId);

        if ($plan->isEmpty()) {
            $this->info('✓ No hay pagos sin enlazar.');
            return Command::SUCCESS;
        }

        $tenantNames = DB::table('tenant')
            ->whereIn('id', $plan->pluck('tenant_id')->filter()->unique()->all() ?: [0])
            ->pluck('name', 'id');

        $this->table(
            ['Tenant', 'Texto en el pago', 'Pagos', 'Resultado', 'Forma de pago'],
            $plan->map(fn ($row) => [
                $row['tenant_id'] ? ($tenantNames[$row['tenant_id']] ?? '?') . " (#{$row['tenant_id']})" : '(sin tenant)',
                $row['method'],
                $row['payments'],
                $row['result'],
                $row['payment_method_id'] ? "{$row['payment_method_name']} (#{$row['payment_method_id']})" : '—',
            ])->all()
        );

        $this->newLine();
        $this->table(
            ['Tenant', 'Enlazables', 'Sin coincidencia', 'Ambiguos'],
            $plan->groupBy(fn ($row) => $row['tenant_id'] ?? 0)->map(fn ($rows, $id) => [
                $id ? ($tenantNames[$id] ?? '?') . " (#{$id})" : '(sin tenant)',
                $rows->where('result', PaymentMethodLinker::MATCH)->sum('payments'),
                $rows->whereIn('result', [PaymentMethodLinker::NO_MATCH, PaymentMethodLinker::NO_TENANT])->sum('payments'),
                $rows->where('result', PaymentMethodLinker::AMBIGUOUS)->sum('payments'),
            ])->values()->all()
        );

        $pending = $plan->where('result', '!=', PaymentMethodLinker::MATCH)->sum('payments');

        if (!$apply) {
            $this->info(sprintf(
                'Sólo lectura: %d pagos se enlazarían; %d quedarían con su texto histórico. Usa --apply para escribir.',
                $plan->where('result', PaymentMethodLinker::MATCH)->sum('payments'),
                $pending
            ));
            return Command::SUCCESS;
        }

        $linked = $linker->apply($plan);

        $this->info("✓ {$linked} pagos enlazados. {$pending} conservan su texto histórico sin enlace.");

        return Command::SUCCESS;
    }
}
