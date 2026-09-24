<?php

namespace App\Http\Requests\Support;

/**
 * Edición de una intervención EN CURSO.
 *
 * Todo opcional: corregir el hallazgo no obliga a reenviar el técnico. Que la
 * intervención esté abierta lo comprueba el controlador, que es quien puede
 * explicar en un 422 por qué no se puede y qué hacer en su lugar.
 */
class UpdateTicketInterventionRequest extends TicketInterventionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->reglasDeContenido(obligatorias: false);
    }
}
