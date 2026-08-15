<?php

namespace App\Exceptions;

use App\Models\CustomerDocument;
use RuntimeException;

/**
 * Un cliente = UN contrato firmado vigente.
 *
 * Es una excepción y no un `return null` porque los dos caminos de firma (el
 * interno del panel y el público del link) tienen que rechazar por igual, y el
 * rechazo debe ocurrir ANTES de reservar el consecutivo: devolver un número de
 * la secuencia a un contrato que nunca se genera deja un hueco permanente en
 * la numeración del ISP.
 */
class ContractAlreadySignedException extends RuntimeException
{
    public function __construct(public readonly CustomerDocument $existing)
    {
        $label = $existing->contract_number ?: $existing->file_name;

        parent::__construct(
            "Este cliente ya tiene un contrato firmado ({$label}). "
            . 'Elimínalo en «Documentos del cliente» antes de generar uno nuevo.'
        );
    }
}
