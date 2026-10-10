<?php

namespace App\Http\Requests\Support;

/** Alta de una intervención: tipo, técnico e inicio son obligatorios (§ 14). */
class StoreTicketInterventionRequest extends TicketInterventionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->reglasDeContenido(obligatorias: true);
    }
}
