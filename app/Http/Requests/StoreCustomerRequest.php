<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // email: correo PERSONAL/de contacto del cliente (no se usa para login).
            'email'          => 'required|email|unique:users,email',
            // email_tenant: correo de ACCESO (login). Si viene vacío el controlador
            // lo autogenera como nombre.apellido@dominio. Debe ser único.
            'email_tenant'   => 'nullable|string|max:100|unique:users,email_tenant',
            'password'       => 'required|string|min:6',
            'tel'            => 'nullable|string|max:20',

            // Client profile
            'name'           => 'required|string|max:255',
            // El apellido solo es obligatorio para personas; una empresa
            // (is_company=true) puede dejarlo vacío.
            'last_name'      => [Rule::requiredIf(fn () => !$this->boolean('is_company')), 'nullable', 'string', 'max:255'],
            'is_company'     => 'nullable|boolean',
            'cedula'         => 'required|string|max:20',
            'city'           => 'nullable|string|max:255',
            'state'          => 'nullable|string|max:255',
            'address'        => 'nullable|string|max:500',
            'precinto'       => 'nullable|string|max:100',
            'installation_date' => 'nullable|date',
            'estrato'        => 'nullable|integer|between:1,6',
            'comments'       => 'nullable|string|max:2000',

            // Service configuration
            'ip_user'        => 'nullable|string|max:45',
            'service_id'     => 'nullable|integer|exists:service_plan,id',
            'sectorial_id'   => 'nullable|integer|exists:sectorial,id',
            'is_fiber'       => 'nullable|boolean',
            'olt_id'         => 'nullable|integer|exists:sectorial,id',
            'nap_port'       => 'nullable|string|max:20',
            'router_id'      => 'nullable|integer|exists:router,id',
            'tenant_id'      => 'nullable|integer|exists:tenant,id',

            // PPPoE secret (optional, only when plan is PPPoE)
            'create_pppoe_secret' => 'nullable|boolean',
            'pppoe_username'      => 'nullable|string|max:255',
            'pppoe_password'      => 'nullable|string|max:255',
            'pppoe_local_address' => 'nullable|string|max:45',

            // HotSpot credentials (only when router control mode is HotSpot)
            'hotspot_username'    => 'nullable|string|max:255',
            'hotspot_password'    => 'nullable|string|max:255',

            // MAC address (only when router control mode is DHCP Leases / IP-MAC)
            'mac_address'         => 'nullable|string|max:17|regex:/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/',

            // Cuando es false el cliente se guarda solo en la BD y NO se aprovisiona
            // en la RB (botón "Guardar"). Si viene ausente se asume true para no
            // alterar el comportamiento de imports/conversión de prospectos/otros
            // llamadores que esperan el aprovisionamiento automático.
            'push_to_router'      => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'email.required'     => 'El correo personal es obligatorio.',
            'email.email'        => 'El correo personal no es válido.',
            'email.unique'       => 'Este correo personal ya está registrado.',
            'email_tenant.unique' => 'Este correo de acceso ya está en uso por otro usuario.',
            'password.required'  => 'La contraseña es obligatoria.',
            'password.min'       => 'La contraseña debe tener al menos 6 caracteres.',
            'name.required'      => 'El nombre es obligatorio.',
            'last_name.required' => 'El apellido es obligatorio.',
            'cedula.required'    => 'La cédula es obligatoria.',
        ];
    }
}
