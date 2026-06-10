<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'speed_down' => 'required|string',
            'speed_up' => 'required|string',
            'cost_product' => 'required|numeric',
            'commit' => 'nullable|string',
            'type' => 'required|string',
            'type_plan_id' => 'required|exists:type_plans,id',
            'tenant_id' => 'required|integer',
            'priority' => 'nullable|integer|min:1|max:8',
            'burst_download' => 'nullable|string',
            'burst_upload' => 'nullable|string',
            'pppoe_pool' => 'nullable|string',
            'local_address' => 'nullable|string',
            'shared_users' => 'nullable|integer|min:1',
            'session_timeout' => 'nullable|string',
            'idle_timeout' => 'nullable|string',
            'pcq_rate' => 'nullable|string',
            'address_mask' => 'nullable|string',
            // Plan de cortesía: el cliente queda en 'gratis' y nunca se factura.
            'is_courtesy' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del plan es obligatorio.',
            'speed_down.required' => 'La velocidad de descarga es obligatoria.',
            'speed_up.required' => 'La velocidad de carga es obligatoria.',
            'cost_product.required' => 'El costo del plan es obligatorio.',
            'type.required' => 'El tipo de plan es obligatorio.',
            'type_plan_id.required' => 'El tipo de plan es obligatorio.',
            'tenant_id.required' => 'El tenant es obligatorio.',
        ];
    }
}
