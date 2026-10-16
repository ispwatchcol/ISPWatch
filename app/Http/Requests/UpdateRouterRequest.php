<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesRouterControlMode;
use App\Models\Router;
use App\Services\CustomerProvisioningService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRouterRequest extends FormRequest
{
    use NormalizesRouterControlMode;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeControlMode();
    }

    public function rules(): array
    {
        $equipo = $this->equipmentRule(...);

        return [
            'name' => 'sometimes|required|string|max:255',
            'ip' => $equipo('ip', 'ip'),
            'ipv6' => 'nullable|string|max:255',
            'failover' => 'nullable|string|max:255',
            'external_id' => 'nullable|string|max:255',
            'user_rb' => $equipo('user_rb', 'string|max:255'),
            'password_rb' => $equipo('password_rb', 'string|max:255'),
            'puerto_api' => 'nullable|integer|min:1|max:65535',
            'puerto_www' => 'nullable|integer|min:1|max:65535',
            'puerto_ssh' => 'nullable|integer|min:1|max:65535',
            'lan_interface' => 'nullable|string|max:255',
            'wan_interface' => 'nullable|string|max:255',
            'vpn_username' => 'nullable|string|max:255',
            'vpn_password' => 'nullable|string|max:255',
            'comments'  => 'nullable|string',
            'rangos_ip' => 'nullable|string',
            'cut_type_id' => 'nullable|integer',
            'billing_router_id' => 'nullable|integer',
            'firmware_version' => $equipo('firmware_version', 'string|max:100'),
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

            // Sexto método de control: el AAA externo gestiona este router.
            'radius' => 'nullable|boolean',
        ];
    }

    /**
     * Regla de los datos de acceso al equipo (IP, usuario, contraseña,
     * firmware) según el modo en que QUEDA el router (KAN-102).
     *
     * - Queda en RADIUS: se pueden omitir y también vaciar. Es el caso real de
     *   la migración de CNO: un router existente que pasa a AAA externo.
     * - Sale de RADIUS y el dato guardado está vacío: se exige en este mismo
     *   request. Si no, el router volvería a un modo clásico sin con qué
     *   operar el equipo, que es justo la basura que esto quiere evitar.
     * - Cualquier otro caso: igual que siempre (`sometimes|required`).
     */
    private function equipmentRule(string $field, string $rules): string
    {
        $router = $this->route('router');
        $router = $router instanceof Router ? $router : null;

        // Si el request no toca el método de control, manda lo guardado.
        $mentionsMode = collect(self::CONTROL_MODE_COLUMNS)->contains(fn ($c) => $this->has($c));
        $willBeRadius = $mentionsMode
            ? $this->normalizedControlMode() === CustomerProvisioningService::MODE_RADIUS
            : (bool) $router?->usesRadius();

        if ($willBeRadius) {
            return "sometimes|nullable|{$rules}";
        }

        if ($router?->usesRadius() && blank($router->getAttribute($field))) {
            return "required|{$rules}";
        }

        return "sometimes|required|{$rules}";
    }
}
