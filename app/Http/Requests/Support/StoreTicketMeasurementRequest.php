<?php

namespace App\Http\Requests\Support;

/** Alta de una medición: las seis del § 12 son obligatorias. */
class StoreTicketMeasurementRequest extends TicketMeasurementRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->reglasDeMedicion(obligatorias: true);
    }
}
