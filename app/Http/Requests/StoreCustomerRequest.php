<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Access data
            'email'          => 'required|email|unique:users,email',
            'password'       => 'required|string|min:6',
            'tel'            => 'nullable|string|max:20',

            // Client profile
            'name'           => 'required|string|max:255',
            'last_name'      => 'required|string|max:255',
            'cedula'         => 'required|string|max:20',
            'city'           => 'nullable|string|max:255',
            'state'          => 'nullable|string|max:255',

            // Service configuration
            'ip_user'        => 'nullable|string|max:45',
            'service_id'     => 'nullable|integer|exists:service_plan,id',
            'sectorial_id'   => 'nullable|integer|exists:sectorial,id',
            'router_id'      => 'nullable|integer|exists:router,id',
            'tenant_id'      => 'nullable|integer|exists:tenant,id',

            // PPPoE secret (optional, only when plan is PPPoE)
            'create_pppoe_secret' => 'nullable|boolean',
            'pppoe_username'      => 'nullable|string|max:255',
            'pppoe_password'      => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'email.required'     => 'El correo electrónico es obligatorio.',
            'email.email'        => 'El correo electrónico no es válido.',
            'email.unique'       => 'Este correo electrónico ya está registrado.',
            'password.required'  => 'La contraseña es obligatoria.',
            'password.min'       => 'La contraseña debe tener al menos 6 caracteres.',
            'name.required'      => 'El nombre es obligatorio.',
            'last_name.required' => 'El apellido es obligatorio.',
            'cedula.required'    => 'La cédula es obligatoria.',
        ];
    }
}
