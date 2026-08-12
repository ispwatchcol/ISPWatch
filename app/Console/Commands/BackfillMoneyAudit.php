<?php

namespace App\Console\Commands;

use App\Models\CustomerCredit;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\AuditContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reconstruye el libro de saldo a favor a partir de los datos que ya existen.
 *
 * Sin esto la bitácora arrancaría vacía y no habría forma de explicar los saldos
 * que hoy están vivos —los $4.000 atascados de 20 clientes de Tocaima, los
 * prepagos de varios meses de Chaguaní— más que de memoria.
 *
 * El comando NO mueve plata. Reconstruye los movimientos, y si el saldo
 * reconstruido no coincide con el credit_balance real del cliente, deja el
 * saldo real intacto y escribe un movimiento de descuadre explícito. Es
 * preferible un libro que diga "aquí faltan $X sin explicar" a uno que cuadre
 * porque le cambió el saldo a alguien.
 */
class BackfillMoneyAudit extends Command
{
    protected $signature = 'audit:backfill-money
                            {--dry-run : Calcula y reporta sin escribir nada}
                            {--tenant= : Limitar a un tenant}
                            {--force : Rehacer clientes que ya tienen movimientos}';

    protected $description = 'Reconstruye customer_credits desde las facturas y pagos existentes';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY-RUN: se calcula todo y se revierte al final. No se escribe nada.');
        }

        DB::beginTransaction();

        try {
            $summary = AuditContext::as(AuditContext::SOURCE_CONSOLE, fn () => $this->rebuild());

            if ($dryRun) {
                DB::rollBack();
                $this->warn('DRY-RUN: cambios revertidos.');
            } else {
                DB::commit();
                $this->info('Backfill aplicado.');
            }

            $this->report($summary);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Falló el backfill, no se escribió nada: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array{procesados:int, movimientos:int, descuadres:array}
     */
    protected function rebuild(): array
    {
        $tenantFilter = $this->option('tenant');
        $force        = (bool) $this->option('force');

        $customerIds = $this->customersWithMoneyHistory($tenantFilter);

        $procesados  = 0;
        $movimientos = 0;
        $descuadres  = [];

        $bar = $this->output->createProgressBar(count($customerIds));
        $bar->start();

        foreach ($customerIds as $customerId) {
            $bar->advance();

            $yaTiene = CustomerCredit::withoutGlobalScope('tenant')
                ->where('customer_id', $customerId)
                ->exists();

            if ($yaTiene && !$force) {
                continue;
            }

            if ($yaTiene && $force) {
                CustomerCredit::withoutGlobalScope('tenant')->where('customer_id', $customerId)->delete();
            }

            $resultado = $this->rebuildCustomer($customerId);

            $procesados++;
            $movimientos += $resultado['movimientos'];

            if (abs($resultado['descuadre']) >= 0.01) {
                $descuadres[] = $resultado;
            }
        }

        $bar->finish();
        $this->newLine(2);

        return compact('procesados', 'movimientos', 'descuadres');
    }

    /**
     * Clientes que tienen al menos un pago o una factura: los únicos que pueden
     * haber generado saldo.
     */
    protected function customersWithMoneyHistory(?string $tenantId): array
    {
        $payments = Payment::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->distinct()
            ->pluck('customer_id');

        $invoices = Invoice::withoutGlobalScope('tenant')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->distinct()
            ->pluck('customer_id');

        return $payments->merge($invoices)->unique()->sort()->values()->all();
    }

    /**
     * Replay cronológico de un cliente.
     *
     * Se reutilizan los métodos reales del libro (earn / applyToInvoice) en vez
     * de insertar filas a mano: así el backfill ejercita el mismo código que
     * usa la aplicación, incluido el marcaje FIFO de `consumed`, y no puede
     * divergir de él.
     *
     * @return array{customer_id:int, movimientos:int, reconstruido:float, real:float, descuadre:float}
     */
    protected function rebuildCustomer(int $customerId): array
    {
        $profile = CustomerProfile::where('user_id', $customerId)->first();

        if (!$profile) {
            return ['customer_id' => $customerId, 'movimientos' => 0, 'reconstruido' => 0.0, 'real' => 0.0, 'descuadre' => 0.0];
        }

        $saldoReal = round((float) $profile->credit_balance, 2);

        // Se parte de cero y se deja que el replay reconstruya el saldo; al
        // final se compara contra el real.
        $profile->credit_balance = 0;
        $profile->save();

        $eventos = $this->timeline($customerId);
        $movimientos = 0;
        $disponible  = 0.0;

        foreach ($eventos as $evento) {
            if ($evento['tipo'] === 'earned') {
                $creado = CustomerCredit::earn(
                    $evento['payment'],
                    $evento['monto'],
                    "Excedente del pago #{$evento['payment']->id} (reconstruido)"
                );
                $disponible += $evento['monto'];
            } else {
                // Una factura puede aparecer como cubierta por saldo aunque en
                // ese instante no hubiera saldo: pasa cuando la fecha de un pago
                // viejo quedó registrada después de la factura que lo consumió.
                // Aplicar más de lo disponible dejaría el saldo en negativo, que
                // en un libro de saldo a favor no significa nada. Se acota, y la
                // parte que no se pudo explicar cae en el descuadre final.
                $aplicable = min($evento['monto'], $disponible);

                if ($aplicable <= 0) {
                    continue;
                }

                $creado = CustomerCredit::applyToInvoice(
                    $evento['invoice'],
                    $customerId,
                    $aplicable
                );
                $disponible -= $aplicable;
            }

            if ($creado) {
                // Las fechas reales importan: un extracto con todo fechado el
                // día del despliegue no serviría para explicar nada.
                $creado->created_at = $evento['fecha'];
                $creado->updated_at = $evento['fecha'];
                $creado->save();
                $movimientos++;
            }
        }

        $profile->refresh();
        $reconstruido = round((float) $profile->credit_balance, 2);
        $descuadre    = round($saldoReal - $reconstruido, 2);

        // El saldo real manda. Si el replay no llegó al mismo número, se
        // registra la diferencia como movimiento explícito en vez de cambiarle
        // el saldo al cliente.
        if (abs($descuadre) >= 0.01) {
            CustomerCredit::adjust(
                $customerId,
                $saldoReal,
                $reconstruido,
                'Descuadre detectado al reconstruir el histórico: la diferencia no se explica con los pagos y facturas registrados'
            );
            $movimientos++;
        }

        return [
            'customer_id'  => $customerId,
            'movimientos'  => $movimientos,
            'reconstruido' => $reconstruido,
            'real'         => $saldoReal,
            'descuadre'    => $descuadre,
        ];
    }

    /**
     * Línea de tiempo de un cliente: excedentes de pago y créditos aplicados a
     * facturas, en el orden en que ocurrieron.
     *
     * Un excedente es lo que el pago trajo de más sobre lo que asignó. Un
     * crédito aplicado es la parte de una factura que no cubrió ningún pago
     * pero que sin embargo dejó de deberse: total - balance_due - asignado.
     */
    protected function timeline(int $customerId): array
    {
        $eventos = [];

        $pagos = Payment::where('customer_id', $customerId)
            ->where('status', 'completed')
            ->withSum('allocations as asignado', 'amount')
            ->orderBy('created_at')
            ->get();

        foreach ($pagos as $pago) {
            $excedente = round((float) $pago->amount - (float) ($pago->asignado ?? 0), 2);

            if ($excedente > 0) {
                $eventos[] = [
                    'tipo'    => 'earned',
                    'monto'   => $excedente,
                    'fecha'   => $pago->created_at ?: $pago->payment_date,
                    'payment' => $pago,
                ];
            }
        }

        $facturas = Invoice::withoutGlobalScope('tenant')
            ->where('customer_id', $customerId)
            ->withSum('allocations as asignado', 'amount')
            ->orderBy('created_at')
            ->get();

        foreach ($facturas as $factura) {
            $credito = round(
                (float) $factura->total - (float) $factura->balance_due - (float) ($factura->asignado ?? 0),
                2
            );

            if ($credito > 0) {
                $eventos[] = [
                    'tipo'    => 'applied',
                    'monto'   => $credito,
                    'fecha'   => $factura->created_at ?: $factura->issue_date,
                    'invoice' => $factura,
                ];
            }
        }

        usort($eventos, fn ($a, $b) => ($a['fecha'] <=> $b['fecha']));

        return $eventos;
    }

    protected function report(array $summary): void
    {
        $this->info("Clientes procesados: {$summary['procesados']}");
        $this->info("Movimientos creados: {$summary['movimientos']}");

        if (!$summary['descuadres']) {
            $this->info('Sin descuadres: el libro reconstruido coincide con todos los saldos.');

            return;
        }

        $this->newLine();
        $this->warn('Descuadres (el saldo real no se explica con los pagos y facturas registrados):');

        $this->table(
            ['Cliente', 'Reconstruido', 'Real', 'Diferencia'],
            collect($summary['descuadres'])->map(fn ($d) => [
                $d['customer_id'],
                number_format($d['reconstruido'], 2),
                number_format($d['real'], 2),
                number_format($d['descuadre'], 2),
            ])->all()
        );
    }
}
