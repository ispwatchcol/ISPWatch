<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRouterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'ip' => 'sometimes|required|ip',
            'ipv6' => 'nullable|string|max:255',
            'failover' => 'nullable|string|max:255',
            'external_id' => 'nullable|string|max:255',
            'user_rb' => 'sometimes|required|string|max:255',
            'password_rb' => 'sometimes|required|string|max:255',
            'puerto_api' => 'nullable|integer|min:1|max:65535',
            'puerto_www' => 'nullable|integer|min:1|max:65535',
            'lan_interface' => 'nullable|string|max:255',
            'wan_interface' => 'nullable|string|max:255',
            'vpn_username' => 'nullable|string|max:255',
            'vpn_password' => 'nullable|string|max:255',
            'comments'  => 'nullable|string',
            'rangos_ip' => 'nullable|string',
            'cut_type_id' => 'nullable|integer',
            'billing_router_id' => 'nullable|integer',
            'firmware_version' => 'sometimes|required|string|max:100',
            'status' => 'sometimes|required|string|max:50',
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
}
