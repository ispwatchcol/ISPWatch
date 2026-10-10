<?php

namespace App\Billing;

/**
 * El plan de reparación de mensualidades faltantes y su huella.
 *
 * Lo que se aprueba es la HUELLA, no una lista de nombres: cliente, periodo,
 * fechas, cada componente del importe, el saldo a favor antes y el que se
 * aplicaría, el saldo de la factura y si se avisa. Si al aplicar —ya con los
 * clientes bloqueados— cualquiera de esos datos difiere, la huella no coincide
 * y no se escribe nada. Incluye la fecha de emisión a propósito: un plan
 * aprobado ayer no se aplica hoy sin volver a simular.
 */
final class MissingInvoicePlan
{
    /** Campos de la vista previa que entran en la huella, en este orden. */
    public const FINANCIAL_FIELDS = [
        'period_start', 'period_end', 'issue_date', 'due_date',
        'plan_amount', 'carryover', 'additional', 'total',
        'credit_before', 'credit_to_apply', 'balance_due', 'credit_after',
        'will_notify',
    ];

    /** @param array<int,array<string,mixed>> $missingRows filas con decision = missing */
    public static function hash(array $missingRows): string
    {
        $plan = array_map(function (array $r) {
            $linea = [(int) $r['tenant_id'], (int) $r['customer_id'], (int) $r['router_id'], (string) $r['period']];

            foreach (self::FINANCIAL_FIELDS as $campo) {
                $valor    = $r['preview'][$campo] ?? null;
                $linea[]  = is_float($valor) || is_int($valor) ? number_format((float) $valor, 2, '.', '') : $valor;
            }

            return $linea;
        }, $missingRows);

        usort($plan, fn ($a, $b) => $a[1] <=> $b[1]);

        return hash('sha256', json_encode($plan));
    }
}
