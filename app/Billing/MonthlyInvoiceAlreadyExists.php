<?php

namespace App\Billing;

use App\Models\Invoice;
use RuntimeException;

/**
 * Otra ejecución ya emitió la mensualidad de este cliente y este mes.
 *
 * La lanza BillingService::createMonthlyInvoiceFor() cuando, con la fila del
 * cliente ya bloqueada, vuelve a mirar y encuentra la factura. No es un error:
 * es la carrera que el bloqueo existe para resolver (la corrida horaria, el
 * reintento, el alta y una reparación pueden llegar al mismo cliente a la vez),
 * y cada llamador la trata como «ya estaba hecha».
 */
class MonthlyInvoiceAlreadyExists extends RuntimeException
{
    public function __construct(public readonly Invoice $invoice)
    {
        parent::__construct(
            "El cliente {$invoice->customer_id} ya tiene la mensualidad del periodo (factura {$invoice->number})."
        );
    }
}
