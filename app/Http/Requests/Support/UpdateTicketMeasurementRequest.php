<?php

namespace App\Http\Requests\Support;

/**
 * Corrección de una medición.
 *
 * Todo opcional: arreglar un dígito mal tecleado no obliga a reenviar la fase ni
 * el origen. Que el ticket siga abierto lo comprueba el controlador, que es
 * quien puede explicar en un 422 por qué ya no se puede.
 */
class UpdateTicketMeasurementRequest extends TicketMeasurementRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->reglasDeMedicion(obligatorias: false);
    }
}
