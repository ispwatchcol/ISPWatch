<?php

namespace App\Billing;

use RuntimeException;

/**
 * El plan aprobado ya no describe la realidad: algo cambió entre la simulación
 * y la aplicación (un pago, una factura, el importe, el saldo, el día). No se
 * escribió nada; hay que simular y aprobar de nuevo.
 */
final class MissingInvoicePlanChanged extends RuntimeException
{
    /** @param array<int,array<string,mixed>> $currentRows */
    public function __construct(public readonly string $currentHash, public readonly array $currentRows)
    {
        parent::__construct('El plan cambió desde la simulación aprobada. No se escribió nada.');
    }
}
