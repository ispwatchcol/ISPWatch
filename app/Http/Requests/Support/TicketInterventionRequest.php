<?php

namespace App\Http\Requests\Support;

use App\Models\TicketIntervention;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reglas comunes al alta y a la edición de una intervención (§ 14).
 *
 * EL TÉCNICO SE VALIDA CONTRA EL TENANT, NO CONTRA `users` ENTERO
 *
 * Un `exists:users,id` a secas aceptaría el id de un empleado de otro ISP, y el
 * expediente quedaría firmado por alguien que su dueño no puede ni ver. Ya pasó
 * algo parecido con las notas: la interfaz mandaba `user_id` y un `exists`
 * global devolvía 422 en producción porque el id no correspondía a nadie
 * (bitácora § 52). La lección es la misma — el ámbito del `exists` importa.
 *
 * `Rule::exists(...)->where('tenant_id', …)` lo acota en la misma consulta, sin
 * un segundo viaje a la base y sin poder olvidarse.
 */
abstract class TicketInterventionRequest extends FormRequest
{
    /**
     * La autorización vive en el middleware `permission:ticket_intervene` y en
     * el controlador, que además comprueba el ticket y su tenant. Repetirlo aquí
     * daría dos sitios donde mantener la misma regla.
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    protected function reglasDeContenido(bool $obligatorias): array
    {
        $presencia = $obligatorias ? 'required' : 'sometimes';

        return [
            'kind' => [$presencia, Rule::in(TicketIntervention::KINDS)],

            'technician_id' => [$presencia, 'integer', $this->existeEnMiTenant()],
            // El acompañante es opcional siempre: la § 14 lo lista, pero una
            // atención remota no lleva acompañante.
            'assistant_id'  => ['sometimes', 'nullable', 'integer', $this->existeEnMiTenant()],

            'started_at'  => [$presencia, 'date'],
            // `after_or_equal` y no `after`: una atención remota de un minuto
            // puede registrarse con el mismo sello en los dos extremos.
            'finished_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:started_at'],

            'finding'      => ['sometimes', 'nullable', 'string', 'max:5000'],
            'action_taken' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'outcome'      => ['sometimes', 'nullable', 'string', 'max:5000'],
            'next_step'    => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /** El usuario tiene que existir Y ser del mismo ISP que quien registra. */
    private function existeEnMiTenant(): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('users', 'id')
            ->where('tenant_id', $this->user()?->tenant_id);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'kind.required'          => 'Indica si la intervención fue remota o presencial.',
            'kind.in'                => 'El tipo de intervención sólo puede ser remota o presencial.',
            'technician_id.required' => 'La intervención necesita un técnico responsable.',
            'technician_id.exists'   => 'El técnico seleccionado no pertenece a este operador.',
            'assistant_id.exists'    => 'El acompañante seleccionado no pertenece a este operador.',
            'started_at.required'    => 'Indica cuándo empezó la intervención.',
            'finished_at.after_or_equal' => 'La intervención no puede terminar antes de empezar.',
        ];
    }
}
