<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRouterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'ip' => 'required|ip',
            'ipv6' => 'nullable|string|max:255',
            'failover' => 'nullable|string|max:255',
            'external_id' => 'nullable|string|max:255',
            'user_rb' => 'required|string|max:255',
            'password_rb' => 'required|string|max:255',
            'puerto_api' => 'nullable|integer|min:1|max:65535',
            'puerto_www' => 'nullable|integer|min:1|max:65535',
            'puerto_ssh' => 'nullable|integer|min:1|max:65535',
            'lan_interface' => 'nullable|string|max:255',
            'wan_interface' => 'nullable|string|max:255',
            'vpn_username' => 'nullable|string|max:255',
            'vpn_password' => 'nullable|string|max:255',
            'comments' => 'nullable|string',
            'rangos_ip' => 'nullable|string',
            'cut_type_id' => 'nullable|integer',
            'billing_router_id' => 'nullable|integer',
            'firmware_version' => 'required|string|max:100',
            'status' => 'required|string|max:50',
            'coordinates' => 'nullable',
            'agregar_cliente_mkt' => 'nullable|boolean',
            'historial_trafico' => 'nullable|boolean',
            'simple_queue' => 'nullable|boolean',
            'control_pcq' => 'nullable|boolean',
            'hotspot' => 'nullable|boolean',
            'pppoe' => 'nullable|boolean',
            'pppoe_limit_mode' => 'nullable|in:dynamic,queue',
            'ip_bindings' => 'nullable|boolean',
            'amarre' => 'nullable|boolean',
            'dhcp_leases' => 'nullable|boolean',
            'falla_general' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del router es obligatorio.',
            'ip.required' => 'La dirección IP es obligatoria.',
            'ip.ip' => 'La dirección IP no es válida.',
            'user_rb.required' => 'El usuario RouterBoard es obligatorio.',
            'password_rb.required' => 'La contraseña RouterBoard es obligatoria.',
            'firmware_version.required' => 'La versión de firmware es obligatoria.',
            'status.required' => 'El estado es obligatorio.',
        ];
    }
}
