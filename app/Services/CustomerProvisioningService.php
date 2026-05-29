<?php

namespace App\Services;

use App\Models\CustomerProfile;
use App\Models\Plan;
use App\Models\Router;
use App\Models\User;

/**
 * Aprovisionamiento de un cliente al MikroTik según el MÉTODO DE CONTROL del
 * router (excluyente): Simple Queue, PCQ + Address-list, HotSpot, PPPoE o DHCP.
 *
 * Esta es la ÚNICA fuente de la lógica por-cliente: la usan el job en cola
 * (ProvisionCustomerJob), el endpoint síncrono legado (bulkProvision) y el
 * alta/edición de clientes (CustomerProfileController). Cada llamada hace SSH
 * al CORE → SSH anidado al router, lo que tarda ~17-34s; por eso el camino
 * real masivo es asíncrono (un job por cliente).
 */
class CustomerProvisioningService
{
    public const MODE_SIMPLE_QUEUE = 'simple_queue';
    public const MODE_PCQ          = 'pcq';
    public const MODE_HOTSPOT      = 'hotspot';
    public const MODE_PPPOE        = 'pppoe';
    public const MODE_DHCP         = 'dhcp';

    /**
     * Devuelve el método de control activo del router (excluyente) o null.
     * El orden refleja la prioridad usada por el frontend (setControlMode).
     */
    public static function resolveControlMode(Router $router): ?string
    {
        if ($router->simple_queue) return self::MODE_SIMPLE_QUEUE;
        if ($router->control_pcq)  return self::MODE_PCQ;
        if ($router->hotspot)      return self::MODE_HOTSPOT;
        if ($router->pppoe)        return self::MODE_PPPOE;
        if ($router->dhcp_leases)  return self::MODE_DHCP;
        return null;
    }

    /**
     * Provisiona un cliente y devuelve la fila de resultado (mismo shape que
     * consume el frontend / el job). Es tenant-safe: un cliente de otro tenant
     * se reporta como "no encontrado" sin ejecutar nada (evita IDOR cross-tenant).
     */
    public function provisionOne(int $customerId, int $tenantId): array
    {
        // SECURITY (OWASP A01): un cliente de otro tenant se reporta igual que
        // uno inexistente — sin enumeración cross-tenant.
        $belongsToTenant = User::where('tenant_id', $tenantId)
            ->whereKey($customerId)
            ->exists();
        $customer = $belongsToTenant
            ? CustomerProfile::where('user_id', $customerId)->first()
            : null;

        if (!$customer) {
            return [
                'customer_id' => $customerId,
                'success' => false,
                'message' => 'Cliente no encontrado',
            ];
        }

        $name = trim("{$customer->name} {$customer->last_name}");

        if (!$customer->router_id || !$customer->service_id) {
            return [
                'customer_id' => $customerId,
                'customer_name' => $name,
                'success' => false,
                'message' => 'Cliente sin router o plan asignado',
            ];
        }

        if (!$customer->ip_user) {
            return [
                'customer_id' => $customerId,
                'customer_name' => $name,
                'success' => false,
                'message' => 'Cliente sin IP asignada',
            ];
        }

        $router = Router::find($customer->router_id);
        $servicePlan = Plan::find($customer->service_id);

        if (!$router) {
            return [
                'customer_id' => $customerId,
                'customer_name' => $name,
                'success' => false,
                'message' => 'Router no encontrado',
            ];
        }

        if (!$servicePlan) {
            return [
                'customer_id' => $customerId,
                'customer_name' => $name,
                'success' => false,
                'message' => 'Plan de servicio no encontrado',
            ];
        }

        // Pre-check rápido: sin credenciales de gestión cada intento SSH solo
        // hace timeout. Fallar al instante.
        if (!$router->ip || !$router->user_rb || !$router->password_rb) {
            return [
                'customer_id' => $customerId,
                'customer_name' => $name,
                'success' => false,
                'message' => "El router {$router->name} no tiene credenciales de gestión completas (IP VPN / usuario / contraseña). Genera y conecta el script VPN del router.",
            ];
        }

        $result = $this->provisionByControlMode($router, $customer, $servicePlan);

        return array_merge([
            'customer_id'   => $customerId,
            'customer_name' => $name,
        ], $result);
    }

    /**
     * Despacha el aprovisionamiento por-cliente según el método de control del
     * router. Devuelve un resultado normalizado con claves de compatibilidad
     * (queue_result / pppoe_result / pppoe_skipped / etc.) que ya consumían el
     * job y el frontend.
     *
     * NOTA: NO verifica `agregar_cliente_mkt`; esa compuerta la decide el
     * llamador (el alta/edición la respeta; el provisioning masivo es una
     * acción explícita del operador y siempre ejecuta).
     */
    public function provisionByControlMode(Router $router, CustomerProfile $customer, Plan $plan): array
    {
        $mikrotik = app(MikroTikSshService::class);
        $name     = trim("{$customer->name} {$customer->last_name}");
        $mode     = self::resolveControlMode($router);
        $port     = $router->puerto_api ?? 8728;

        $queueResult   = null;
        $pppoeResult   = null;
        $hotspotResult = null;
        $pcqResult     = null;
        $dhcpResult    = null;
        $pppoeSkipped  = false;
        $skipped       = false;
        $message       = 'OK';

        try {
            switch ($mode) {
                case self::MODE_SIMPLE_QUEUE:
                    $queueResult = $this->runQueue($mikrotik, $router, $customer, $plan, $name, $port);
                    break;

                case self::MODE_PCQ:
                    $pcqResult = $mikrotik->ensureClientInAddressList(
                        $router->ip, $router->user_rb, $router->password_rb,
                        $plan->name, $customer->ip_user, $port, $name
                    );
                    break;

                case self::MODE_HOTSPOT:
                    if ($customer->hotspot_username && $customer->hotspot_password) {
                        $hotspotResult = $mikrotik->ensureHotspotUserOnRouter(
                            $router->ip, $router->user_rb, $router->password_rb,
                            $customer->hotspot_username, $customer->hotspot_password,
                            $plan->name, $port, $customer->ip_user, $name
                        );
                    } else {
                        $skipped = true;
                        $message = 'El router usa HotSpot pero el cliente no tiene credenciales HotSpot configuradas';
                    }
                    break;

                case self::MODE_PPPOE:
                    if ($customer->pppoe_username && $customer->pppoe_password) {
                        $pppoeResult = $mikrotik->ensurePppoeSecretOnRouter(
                            $router->ip, $router->user_rb, $router->password_rb,
                            $customer->pppoe_username, $customer->pppoe_password,
                            $plan->name, 'pppoe', $port,
                            $customer->ip_user, $customer->pppoe_local_address, $name
                        );
                        // En modo 'queue' el secret se complementa con una Simple
                        // Queue; en 'dynamic' el rate-limit lo aplica el perfil.
                        if (($router->pppoe_limit_mode ?? 'dynamic') === 'queue') {
                            $queueResult = $this->runQueue($mikrotik, $router, $customer, $plan, $name, $port);
                        }
                    } else {
                        $pppoeSkipped = true;
                        $skipped = true;
                        $message = 'El router usa PPPoE pero el cliente no tiene credenciales PPPoE configuradas';
                    }
                    break;

                case self::MODE_DHCP:
                    if ($customer->mac_address) {
                        $dhcpResult = $mikrotik->ensureDhcpLeaseOnRouter(
                            $router->ip, $router->user_rb, $router->password_rb,
                            $customer->ip_user, $customer->mac_address,
                            $plan->speed_up, $plan->speed_down, $port, $name
                        );
                    } else {
                        $skipped = true;
                        $message = 'El router usa DHCP Leases pero el cliente no tiene MAC configurada';
                    }
                    break;

                default:
                    $skipped = true;
                    $message = 'El router no tiene un método de control activo';
                    break;
            }
        } catch (\Throwable $e) {
            \Log::warning('[CustomerProvisioningService] Provision exception', [
                'customer_id' => $customer->user_id,
                'mode'        => $mode,
                'error'       => $e->getMessage(),
            ]);
            return [
                'success'        => false,
                'mode'           => $mode,
                'message'        => 'Error al aprovisionar: ' . $e->getMessage(),
                'queue_result'   => $queueResult,
                'pppoe_result'   => $pppoeResult,
                'hotspot_result' => $hotspotResult,
                'pcq_result'     => $pcqResult,
                'dhcp_result'    => $dhcpResult,
            ];
        }

        // Éxito = todos los pasos ejecutados terminaron OK. Un modo "saltado"
        // por datos faltantes NO es éxito (el cliente queda sin cargar).
        $steps = array_filter([$queueResult, $pppoeResult, $hotspotResult, $pcqResult, $dhcpResult]);
        $ranSomething = !empty($steps);
        $allOk = $ranSomething && collect($steps)->every(fn ($r) => (bool) ($r['success'] ?? false));
        $success = $mode === null
            ? false               // router sin método activo → nada que cargar
            : (!$skipped && $allOk);

        if ($success) {
            $message = 'OK';
        } elseif (!$skipped && $ranSomething) {
            // Tomar el primer mensaje de error de los pasos.
            $message = collect($steps)
                ->firstWhere(fn ($r) => !($r['success'] ?? false))['message']
                ?? 'Error al aprovisionar';
        }

        return [
            'success'        => $success,
            'mode'           => $mode,
            'message'        => $message,
            'queue_result'   => $queueResult,
            'pppoe_result'   => $pppoeResult,
            'hotspot_result' => $hotspotResult,
            'pcq_result'     => $pcqResult,
            'dhcp_result'    => $dhcpResult,
            // Compatibilidad con el job/bulk legado:
            'pppoe_applies'  => $mode === self::MODE_PPPOE,
            'pppoe_skipped'  => $pppoeSkipped,
            'pppoe_created'  => $pppoeResult !== null && ($pppoeResult['success'] ?? false),
            'queue_ok'       => $queueResult !== null ? (bool) ($queueResult['success'] ?? false) : null,
            'queue_message'  => $queueResult['message'] ?? null,
            'pppoe_message'  => $pppoeResult['message'] ?? null,
        ];
    }

    /**
     * Helper: Simple Queue con el nombre espejando el secret PPPoE cuando existe
     * y el comentario con el nombre completo (igual que el flujo previo).
     */
    private function runQueue(MikroTikSshService $mikrotik, Router $router, CustomerProfile $customer, Plan $plan, string $name, int $port): array
    {
        return $mikrotik->syncQueueViaCore(
            $router->ip,
            $router->user_rb,
            $router->password_rb,
            $customer->ip_user,
            $customer->name,
            $customer->last_name,
            $plan->speed_up,
            $plan->speed_down,
            $port,
            $customer->pppoe_username,
            $name
        );
    }
}
