<?php

namespace App\Http\Requests\Support;

use App\Support\TicketMeasurements;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reglas comunes al alta y a la corrección de una medición (§ 12).
 *
 * Las seis del documento —tipo, resultado, unidad, fecha/hora, origen y fase—
 * más la intervención opcional de la que salió.
 *
 * `test_type` NO tiene `Rule::in`, y es deliberado: el § 12 enumera las
 * mediciones en prosa sin asignarles código, y el cliente cerró en **D-06** que
 * esa clase de listas se mantiene como referencia. Las sugerencias viajan al
 * frontend por el endpoint de catálogos; el campo acepta lo que el técnico
 * escriba.
 */
abstract class TicketMeasurementRequest extends FormRequest
{
    /**
     * La autorización la ponen el middleware `permission:ticket_intervene` y el
     * controlador, que comprueba además el ticket, su tenant y que no esté
     * archivado. Repetirlo aquí daría dos sitios donde mantener la misma regla.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    protected function reglasDeMedicion(bool $obligatorias): array
    {
        $presencia = $obligatorias ? 'required' : 'sometimes';

        return [
            'test_type' => [$presencia, 'string', 'max:80'],

            // Texto y no número: los ejemplos del § 13 mezclan «PPPoE conectado»
            // con «RSSI –76 dBm» en la misma frase.
            'value'     => [$presencia, 'string', 'max:255'],
            'unit'      => ['sometimes', 'nullable', 'string', 'max:30'],

            'measured_at' => [$presencia, 'date'],
            'source'      => [$presencia, 'string', 'max:60'],

            'phase' => [$presencia, Rule::in(TicketMeasurements::FASES)],

            // De qué visita salió. NULL es normal: el diagnóstico remoto inicial
            // se toma antes de que exista ninguna intervención. Que pertenezca a
            // ESTE ticket lo comprueba el controlador, y en la base lo garantiza
            // una clave foránea compuesta.
            'intervention_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'test_type.required'   => 'Indica qué prueba se hizo.',
            'value.required'       => 'Indica el resultado de la prueba.',
            'measured_at.required' => 'Indica cuándo se tomó la medición.',
            'source.required'      => 'Indica de dónde salió la medición.',
            'phase.required'       => 'Indica si la medición es inicial, de seguimiento o final.',
            'phase.in'             => 'La fase sólo puede ser inicial, seguimiento o final.',
        ];
    }
}
