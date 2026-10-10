<?php

namespace App\Services;

use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Extracto conciliable de un mes: las mismas cifras calculadas bajo TODOS los
 * criterios razonables, y el valor de cada diferencia entre ellos.
 *
 * ── Para qué sirve ──────────────────────────────────────────────────────────
 *
 * Cuando un cliente dice «me faltan tres millones», la pregunta real no es
 * cuánto facturamos: es QUÉ ESTÁ CONTANDO ÉL. Casi siempre las dos cifras están
 * bien y miden cosas distintas — él cuenta por periodo y el sistema por fecha de
 * emisión, él anota el día que recibió la plata y el sistema el día que se
 * digitó, él no descuenta las anuladas, él lleva sólo las mensualidades.
 *
 * Así que en vez de dar UNA cifra y discutirla, este extracto da todas las
 * cifras defendibles y pone precio a cada diferencia. Si una de esas
 * diferencias vale exactamente lo que el cliente reclama, la discusión se acabó
 * en un minuto y sin tocar la base de datos: es un criterio distinto, no un
 * error. Si NINGUNA la explica, entonces el descuadre es nuestro y lo que hay
 * que correr es `billing:audit-books`.
 *
 * NO ESCRIBE NADA.
 */
class BooksStatementService
{
    /**
     * @return array{period:array,facturado:array,recaudado:array,cartera:array,puentes:array}
     */
    public function forMonth(int $tenantId, Carbon $start): array
    {
        $end = $start->copy()->endOfMonth();
        $d   = [$start->toDateString(), $end->toDateString()];
        $t   = [$start->copy()->startOfDay(), $end->copy()->endOfDay()];

        $facturado = $this->facturado($tenantId, $d);
        $recaudado = $this->recaudado($tenantId, $d, $t);
        $cartera   = $this->cartera($tenantId, $end);

        return [
            'period' => [
                'month' => $start->format('Y-m'),
                'start' => $start->toDateString(),
                'end'   => $end->toDateString(),
            ],
            'facturado' => $facturado,
            'recaudado' => $recaudado,
            'cartera'   => $cartera,
            'puentes'   => $this->puentes($facturado, $recaudado),
        ];
    }

    // ─── Facturado ───────────────────────────────────────────────────────────

    protected function facturado(int $tenantId, array $d): array
    {
        $base = fn () => DB::table('invoices')->where('tenant_id', $tenantId);

        $vivas = fn () => $base()->whereNotIn('status', Invoice::ESTADOS_ANULADOS);

        return [
            'panel' => [
                'label' => 'Emitidas en el mes, sin anuladas',
                'note'  => 'Es la cifra del panel de Finanzas.',
                'value' => $this->sum($vivas()->whereBetween('issue_date', $d), 'total'),
            ],
            'con_anuladas' => [
                'label' => 'Emitidas en el mes, INCLUYENDO anuladas',
                'note'  => 'Una planilla llevada a mano rara vez descuenta lo anulado.',
                'value' => $this->sum($base()->whereBetween('issue_date', $d), 'total'),
            ],
            'sin_borradores' => [
                'label' => 'Emitidas en el mes, sin anuladas ni borradores',
                'note'  => 'Un borrador todavía no salió al mundo.',
                'value' => $this->sum(
                    $vivas()->where('status', '!=', Invoice::STATUS_DRAFT)->whereBetween('issue_date', $d),
                    'total'
                ),
            ],
            'por_periodo' => [
                'label' => 'Del PERIODO del mes (no por fecha de emisión)',
                'note'  => 'La diferencia más habitual: el cliente cuenta el mes que se le prestó '
                         . 'el servicio, el sistema el día en que se emitió el documento.',
                'value' => $this->sum($vivas()->whereBetween('period_start', $d), 'total'),
            ],
            'solo_mensualidades' => [
                'label' => 'Sólo mensualidades (sin instalaciones, cargos ni tickets)',
                'note'  => 'Muchas planillas llevan sólo el servicio recurrente.',
                'value' => $this->sum(
                    $vivas()->whereBetween('issue_date', $d)->where('invoice_type', Invoice::TYPE_MONTHLY),
                    'total'
                ),
            ],
            'arrastre_incluido' => [
                'label' => 'De lo emitido, cuánto es saldo arrastrado de meses anteriores',
                'note'  => 'No es servicio de este mes: es deuda vieja recobrada dentro de la '
                         . 'factura del mes. Una planilla por servicio no lo tiene.',
                'value' => $this->sum($vivas()->whereBetween('issue_date', $d), 'carried_in'),
            ],
        ];
    }

    // ─── Recaudado ───────────────────────────────────────────────────────────

    protected function recaudado(int $tenantId, array $d, array $t): array
    {
        $base = fn () => DB::table('payments')->where('tenant_id', $tenantId);

        return [
            'panel' => [
                'label' => "Pagos del mes con estado 'completed'",
                'note'  => 'Es la cifra del panel de Finanzas.',
                'value' => $this->sum($base()->where('status', 'completed')->whereBetween('payment_date', $d), 'amount'),
            ],
            'listado' => [
                'label' => 'TODOS los pagos del mes, sin mirar el estado',
                'note'  => 'Es la cifra del listado de Recaudos, que a propósito no excluye ningún '
                         . 'estado. Si no coincide con la de arriba, dos pantallas del producto '
                         . 'muestran recaudos distintos del mismo mes.',
                'value' => $this->sum($base()->whereBetween('payment_date', $d), 'amount'),
            ],
            'por_digitacion' => [
                'label' => 'Pagos DIGITADOS en el mes (fecha de registro, no de pago)',
                'note'  => 'Un pago de fin de mes digitado al mes siguiente cae en meses distintos '
                         . 'según se mire. Si el cliente cuadra su caja por el día que la registró, '
                         . 'ésta es su cifra.',
                'value' => $this->sum($base()->whereBetween('created_at', $t), 'amount'),
            ],
            'sin_titular' => [
                'label' => 'De lo recaudado, cuánto es de clientes dados de baja',
                'note'  => 'Cuenta en el total de la empresa pero no aparece en ningún informe por '
                         . 'cliente: una planilla llevada cliente por cliente no lo tiene.',
                'value' => $this->sum($base()->whereBetween('payment_date', $d)->whereNull('customer_id'), 'amount'),
            ],
            'aplicado_a_facturas' => [
                'label' => 'De lo recaudado, cuánto quedó aplicado a alguna factura',
                'note'  => 'La diferencia contra el total recaudado es dinero que quedó como saldo '
                         . 'a favor… o que no está en ninguna parte (eso lo dice billing:audit-books).',
                'value' => round((float) DB::table('payment_allocations as pa')
                    ->join('payments as p', 'p.id', '=', 'pa.payment_id')
                    ->where('p.tenant_id', $tenantId)
                    ->whereBetween('p.payment_date', $d)
                    ->sum('pa.amount'), 2),
            ],
        ];
    }

    // ─── Cartera ─────────────────────────────────────────────────────────────

    protected function cartera(int $tenantId, Carbon $end): array
    {
        $vivas = DB::table('invoices')
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', Invoice::ESTADOS_ANULADOS);

        return [
            'pendiente_hoy' => [
                'label' => 'Cartera total pendiente (acumulada, a hoy)',
                'note'  => 'Es la cifra del panel. No es del mes: lo que te deben es todo lo que te deben.',
                'value' => $this->sum(clone $vivas, 'balance_due'),
            ],
            'pendiente_al_cierre' => [
                'label' => 'Cartera de facturas emitidas hasta el cierre del mes',
                'note'  => 'El saldo es el de HOY, no el que había ese día: una factura de marzo '
                         . 'pagada en junio figura aquí ya saldada. No es una foto histórica.',
                'value' => $this->sum((clone $vivas)->where('issue_date', '<=', $end->toDateString()), 'balance_due'),
            ],
            'saldo_a_favor' => [
                'label' => 'Saldo a favor de los clientes (plata recibida por adelantado)',
                'note'  => 'Ya entró a caja pero todavía no pagó ninguna factura. Resta de la deuda '
                         . 'real del cliente y una planilla de cartera no suele tenerlo.',
                'value' => round((float) DB::table('customer_profile')
                    ->where('tenant_id', $tenantId)->sum('credit_balance'), 2),
            ],
            'arrastre_pendiente' => [
                'label' => 'Saldo arrastrado aún no cobrado en ninguna factura',
                'note'  => 'El cliente lo debe, pero todavía no lo ha visto en ningún documento: '
                         . 'no está en la cartera y sin embargo es deuda.',
                'value' => round((float) DB::table('invoice_carryovers')
                    ->where('tenant_id', $tenantId)->where('status', 'pending')->sum('amount'), 2),
            ],
        ];
    }

    /**
     * Cada diferencia entre dos criterios, con su importe. Es la tabla que se
     * busca con el número del cliente en la mano.
     */
    protected function puentes(array $facturado, array $recaudado): array
    {
        $p = [];

        $add = function (string $grupo, string $a, string $b, array $set, string $porque) use (&$p) {
            $delta = round($set[$a]['value'] - $set[$b]['value'], 2);

            if (abs($delta) < 0.01) {
                return;
            }

            $p[] = [
                'grupo'  => $grupo,
                'contra' => "{$set[$a]['label']}  vs  {$set[$b]['label']}",
                'delta'  => $delta,
                'porque' => $porque,
            ];
        };

        $add('Facturado', 'con_anuladas', 'panel', $facturado,
            'Facturas anuladas. Si el cliente no las descuenta de su planilla, le sobra esto.');
        $add('Facturado', 'por_periodo', 'panel', $facturado,
            'Contar por periodo de servicio en vez de por fecha de emisión.');
        $add('Facturado', 'panel', 'solo_mensualidades', $facturado,
            'Instalaciones, cargos adicionales y tickets. Una planilla de mensualidades no los tiene.');
        $add('Facturado', 'panel', 'sin_borradores', $facturado,
            'Facturas en borrador contadas como emitidas.');

        if (($facturado['arrastre_incluido']['value'] ?? 0) > 0) {
            $p[] = [
                'grupo'  => 'Facturado',
                'contra' => 'Saldo arrastrado incluido en las facturas del mes',
                'delta'  => $facturado['arrastre_incluido']['value'],
                'porque' => 'Deuda vieja recobrada dentro de la factura del mes: infla el facturado '
                          . 'del mes frente a una planilla que lleve sólo el servicio prestado.',
            ];
        }

        $add('Recaudado', 'listado', 'panel', $recaudado,
            "Pagos con estado distinto de 'completed': el listado de Recaudos los suma y el panel de Finanzas no.");
        $add('Recaudado', 'por_digitacion', 'panel', $recaudado,
            'Pagos digitados en un mes distinto del que llevan como fecha de pago.');
        $add('Recaudado', 'panel', 'aplicado_a_facturas', $recaudado,
            'Dinero recibido que no quedó aplicado a ninguna factura: o es saldo a favor, o es un '
          . 'descuadre real (lo distingue billing:audit-books).');

        if (($recaudado['sin_titular']['value'] ?? 0) > 0) {
            $p[] = [
                'grupo'  => 'Recaudado',
                'contra' => 'Recaudos de clientes dados de baja',
                'delta'  => $recaudado['sin_titular']['value'],
                'porque' => 'No salen en ningún informe por cliente: si el cliente cuadra sumando '
                          . 'cliente por cliente, esto le falta.',
            ];
        }

        usort($p, fn ($a, $b) => abs($b['delta']) <=> abs($a['delta']));

        return $p;
    }

    protected function sum($query, string $column): float
    {
        return round((float) $query->sum($column), 2);
    }
}
