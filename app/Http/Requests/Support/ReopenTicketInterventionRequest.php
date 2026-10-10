<?php

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reapertura de una intervención finalizada.
 *
 * El motivo es OBLIGATORIO y ésa es toda la razón de ser de este request. Como
 * una intervención no se borra ni se edita una vez cerrada, reabrir es el único
 * camino para corregirla — y si ese camino no exigiera explicación, sería un
 * borrado con otro nombre. El mínimo de 10 caracteres existe para que «ok» o
 * «.» no cuenten como justificación.
 */
class ReopenTicketInterventionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Explica por qué se reabre la intervención.',
            'reason.min'      => 'El motivo debe tener al menos 10 caracteres.',
            'reason.max'      => 'El motivo no puede superar los 500 caracteres.',
        ];
    }
}
