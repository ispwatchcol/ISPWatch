<?php

namespace App\Console\Commands;

use App\Models\CustomerCredit;
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
 *
 * ── Por qué todo se carga en bloque ─────────────────────────────────────────
 * La primera versión reutilizaba los métodos del modelo (earn/applyToInvoice)
 * cliente por cliente. Es más elegante, pero cada movimiento eran 4-5 viajes a
 * la base: contra Supabase se traducía en más de 10 minutos con la conexión
 * `idle in transaction`, que sobre un pooler es una forma excelente de que te
 * maten la conexión a medio camino. Ahora se leen los tres conjuntos completos
 * en tres consultas, se arma todo en memoria y se inserta por lotes.
 *
 * La lógica FIFO de `consumed` está replicada aquí a mano y es la misma que la
 * de CustomerCredit; los tests de tests/Feature/Audit/ la fijan por ambos lados.
 */
class BackfillMoneyAudit extends Command
{
    protected $signature = 'audit:backfill-money
                            {--dry-run : Calcula y reporta sin escribir nada}
                            {--tenant= : Limitar a un tenant}
                            {--force : Rehacer clientes que ya tienen movimientos}';

    protected $description = 'Reconstruye customer_credits desde las facturas y pagos existentes';

    /** Filas a insertar, por lotes para no armar un INSERT gigante. */
    private const CHUNK = 500;

    public function handle(): int
    {
        $dryRun   = (bool) $this->option('dry-run');
        $tenantId = $this->option('tenant');
        $force    = (bool) $this->option('force');

        if ($dryRun) {
            $this->warn('DRY-RUN: se calcula y se reporta. No se escribe nada.');
        }

        $this->info('Leyendo pagos, facturas y saldos…');

        $saldos   = $this->saldosPorCliente($tenantId);
        $pagos    = $this->excedentesPorCliente($tenantId);
        $creditos = $this->creditosAplicadosPorCliente($tenantId);
        $yaTienen = $this->clientesConMovimientos();

        // Los clientes con saldo vivo entran aunque no tengan ningún pago ni
        // factura que lo explique: son precisamente los que hay que reportar
        // como descuadre, y dejarlos fuera los volvería invisibles.
        $conSaldo = $saldos->filter(fn ($p) => abs($p['saldo']) >= 0.01)->keys();

        $candidatos = collect($pagos->keys())
            ->merge($creditos->keys())
            ->merge($conSaldo)
            ->unique()
            ->reject(fn ($id) => !$force && isset($yaTienen[$id]))
            ->values();

        $this->info("Clientes a reconstruir: {$candidatos->count()}");

        $filas      = [];
        $descuadres = [];
        $procesados = 0;

        $bar = $this->output->createProgressBar($candidatos->count());
        $bar->start();

        foreach ($candidatos as $customerId) {
            $bar->advance();

            if (!isset($saldos[$customerId])) {
                continue; // pago o factura de un usuario sin ficha de cliente
            }

            $resultado = $this->reconstruirCliente(
                (int) $customerId,
                $saldos[$customerId],
                $pagos->get($customerId, collect()),
                $creditos->get($customerId, collect()),
            );

            $filas = array_merge($filas, $resultado['filas']);
            $procesados++;

            if (abs($resultado['descuadre']) >= 0.01) {
                $descuadres[] = $resultado;
            }
        }

        $bar->finish();
        $this->newLine(2);

        if (!$dryRun) {
            $this->escribir($filas, $force, $candidatos->all());
        }

        $this->report($procesados, count($filas), $descuadres, $dryRun);

        return self::SUCCESS;
    }

    // ─── Lectura en bloque ──────────────────────────────────────────────────

    /** user_id => ['saldo' => float, 'tenant_id' => int|null] */
    private function saldosPorCliente(?string $tenantId)
    {
        return DB::table('customer_profile as cp')
            ->join('users as u', 'u.id', '=', 'cp.user_id')
            ->when($tenantId, fn ($q) => $q->where('u.tenant_id', $tenantId))
            ->select('cp.user_id', 'cp.credit_balance', 'u.tenant_id')
            ->get()
            ->keyBy('user_id')
            ->map(fn ($r) => [
                'saldo'     => round((float) $r->credit_balance, 2),
                'tenant_id' => $r->tenant_id,
            ]);
    }

    /**
     * Excedente de cada pago: lo que trajo de más sobre lo que asignó a facturas.
     */
    private function excedentesPorCliente(?string $tenantId)
    {
        return DB::table('payments as p')
            ->leftJoin('payment_allocations as pa', 'pa.payment_id', '=', 'p.id')
            ->where('p.status', 'completed')
            ->when($tenantId, fn ($q) => $q->where('p.tenant_id', $tenantId))
            ->groupBy('p.id', 'p.customer_id', 'p.amount', 'p.created_at', 'p.payment_date')
            ->havingRaw('p.amount - coalesce(sum(pa.amount), 0) > 0')
            ->select([
                'p.id',
                'p.customer_id',
                'p.created_at',
                'p.payment_date',
                DB::raw('p.amount - coalesce(sum(pa.amount), 0) as excedente'),
            ])
            ->get()
            ->groupBy('customer_id');
    }

    /**
     * Parte de cada factura que dejó de deberse sin que ningún pago la cubriera:
     * total - balance_due - asignado. Eso solo puede haberlo pagado el saldo.
     */
    private function creditosAplicadosPorCliente(?string $tenantId)
    {
        return DB::table('invoices as i')
            ->leftJoin('payment_allocations as pa', 'pa.invoice_id', '=', 'i.id')
            ->when($tenantId, fn ($q) => $q->where('i.tenant_id', $tenantId))
            ->groupBy('i.id', 'i.customer_id', 'i.number', 'i.total', 'i.balance_due', 'i.created_at', 'i.issue_date')
            ->havingRaw('i.total - i.balance_due - coalesce(sum(pa.amount), 0) > 0')
            ->select([
                'i.id',
                'i.customer_id',
                'i.number',
                'i.created_at',
                'i.issue_date',
                DB::raw('i.total - i.balance_due - coalesce(sum(pa.amount), 0) as credito'),
            ])
            ->get()
            ->groupBy('customer_id');
    }

    /** customer_id => true, para saltar a quien ya tiene libro. */
    private function clientesConMovimientos(): array
    {
        return DB::table('customer_credits')
            ->distinct()
            ->pluck('customer_id')
            ->flip()
            ->all();
    }

    // ─── Replay en memoria ──────────────────────────────────────────────────

    /**
     * @return array{filas: array, descuadre: float, reconstruido: float, real: float, customer_id: int}
     */
    private function reconstruirCliente(int $customerId, array $perfil, $pagos, $creditos): array
    {
        $eventos = [];

        foreach ($pagos as $p) {
            $eventos[] = [
                'tipo'    => CustomerCredit::TYPE_EARNED,
                'monto'   => round((float) $p->excedente, 2),
                'fecha'   => $p->created_at ?: $p->payment_date,
                'pago_id' => (int) $p->id,
            ];
        }

        foreach ($creditos as $c) {
            $eventos[] = [
                'tipo'       => CustomerCredit::TYPE_APPLIED,
                'monto'      => round((float) $c->credito, 2),
                'fecha'      => $c->created_at ?: $c->issue_date,
                'factura_id' => (int) $c->id,
                'numero'     => $c->number,
            ];
        }

        usort($eventos, fn ($a, $b) => ($a['fecha'] <=> $b['fecha']));

        $ahora   = now()->toDateTimeString();
        $filas   = [];
        $saldo   = 0.0;
        $pool    = [];   // índices de filas `earned` con saldo sin consumir

        foreach ($eventos as $evento) {
            if ($evento['tipo'] === CustomerCredit::TYPE_EARNED) {
                $saldo = round($saldo + $evento['monto'], 2);

                $filas[] = [
                    'tenant_id'       => $perfil['tenant_id'],
                    'customer_id'     => $customerId,
                    'type'            => CustomerCredit::TYPE_EARNED,
                    'from_payment_id' => $evento['pago_id'],
                    'to_invoice_id'   => null,
                    'amount'          => $evento['monto'],
                    'balance_after'   => $saldo,
                    'consumed'        => 0,
                    'reason'          => "Excedente del pago #{$evento['pago_id']} (reconstruido)",
                    'created_by'      => null,
                    'source'          => AuditContext::SOURCE_CONSOLE,
                    'created_at'      => $evento['fecha'],
                    'updated_at'      => $ahora,
                ];

                $pool[] = ['idx' => count($filas) - 1, 'disponible' => $evento['monto']];

                continue;
            }

            // Una factura puede figurar como cubierta por saldo aunque en ese
            // instante no hubiera saldo: pasa cuando la fecha de un pago viejo
            // quedó después de la factura que lo consumió. Aplicar más de lo
            // disponible dejaría el saldo en negativo, que en un libro de saldo
            // a favor no significa nada. Se acota y el resto cae en el descuadre.
            $aplicable = min($evento['monto'], $saldo);

            if ($aplicable <= 0) {
                continue;
            }

            $saldo = round($saldo - $aplicable, 2);

            $filas[] = [
                'tenant_id'       => $perfil['tenant_id'],
                'customer_id'     => $customerId,
                'type'            => CustomerCredit::TYPE_APPLIED,
                'from_payment_id' => null,
                'to_invoice_id'   => $evento['factura_id'],
                'amount'          => -$aplicable,
                'balance_after'   => $saldo,
                'consumed'        => 0,
                'reason'          => "Saldo a favor aplicado a la factura {$evento['numero']} (reconstruido)",
                'created_by'      => null,
                'source'          => AuditContext::SOURCE_CONSOLE,
                'created_at'      => $evento['fecha'],
                'updated_at'      => $ahora,
            ];

            // Marca FIFO: es lo que permite después anular un pago devolviendo
            // solo lo que ninguna factura llegó a gastar.
            $pendiente = $aplicable;

            foreach ($pool as &$entrada) {
                if ($pendiente <= 0) {
                    break;
                }

                $toma = min($entrada['disponible'], $pendiente);
                if ($toma <= 0) {
                    continue;
                }

                $entrada['disponible'] = round($entrada['disponible'] - $toma, 2);
                $filas[$entrada['idx']]['consumed'] = round($filas[$entrada['idx']]['consumed'] + $toma, 2);
                $pendiente = round($pendiente - $toma, 2);
            }
            unset($entrada);
        }

        // El saldo real manda. Si el replay no llegó al mismo número, se registra
        // la diferencia en vez de cambiarle el saldo al cliente.
        $descuadre = round($perfil['saldo'] - $saldo, 2);

        if (abs($descuadre) >= 0.01) {
            $filas[] = [
                'tenant_id'       => $perfil['tenant_id'],
                'customer_id'     => $customerId,
                'type'            => CustomerCredit::TYPE_ADJUSTED,
                'from_payment_id' => null,
                'to_invoice_id'   => null,
                'amount'          => $descuadre,
                'balance_after'   => $perfil['saldo'],
                'consumed'        => 0,
                'reason'          => 'Descuadre detectado al reconstruir el histórico: la diferencia no se explica con los pagos y facturas registrados',
                'created_by'      => null,
                'source'          => AuditContext::SOURCE_CONSOLE,
                'created_at'      => $ahora,
                'updated_at'      => $ahora,
            ];
        }

        return [
            'customer_id'  => $customerId,
            'filas'        => $filas,
            'reconstruido' => $saldo,
            'real'         => $perfil['saldo'],
            'descuadre'    => $descuadre,
        ];
    }

    // ─── Escritura ──────────────────────────────────────────────────────────

    /**
     * Inserta por lotes. Cada lote va en su propia transacción corta: una sola
     * transacción para todo dejaría la conexión abierta varios minutos, que es
     * justo lo que hacía fallar la primera versión.
     */
    private function escribir(array $filas, bool $force, array $customerIds): void
    {
        if ($force && $customerIds) {
            foreach (array_chunk($customerIds, self::CHUNK) as $lote) {
                DB::table('customer_credits')->whereIn('customer_id', $lote)->delete();
            }
        }

        if (!$filas) {
            return;
        }

        $lotes = array_chunk($filas, self::CHUNK);
        $bar   = $this->output->createProgressBar(count($lotes));

        $this->info('Escribiendo movimientos…');
        $bar->start();

        foreach ($lotes as $lote) {
            DB::transaction(fn () => DB::table('customer_credits')->insert($lote));
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
    }

    private function report(int $procesados, int $movimientos, array $descuadres, bool $dryRun): void
    {
        $this->info("Clientes procesados: {$procesados}");
        $this->info(($dryRun ? 'Movimientos que se crearían: ' : 'Movimientos creados: ') . $movimientos);

        if (!$descuadres) {
            $this->info('Sin descuadres: el libro reconstruido coincide con todos los saldos.');

            return;
        }

        $this->newLine();
        $this->warn('Descuadres (el saldo real no se explica con los pagos y facturas registrados):');

        $total = 0;
        foreach ($descuadres as $d) {
            $total += $d['descuadre'];
        }

        $this->table(
            ['Cliente', 'Reconstruido', 'Real', 'Diferencia'],
            collect($descuadres)
                ->sortByDesc(fn ($d) => abs($d['descuadre']))
                ->take(40)
                ->map(fn ($d) => [
                    $d['customer_id'],
                    number_format($d['reconstruido'], 2),
                    number_format($d['real'], 2),
                    number_format($d['descuadre'], 2),
                ])->all()
        );

        $this->warn(count($descuadres) . ' clientes con descuadre, suma neta: ' . number_format($total, 2));
    }
}
