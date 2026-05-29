<?php

namespace App\Services;

use App\Models\CustomerProfile;
use App\Models\Plan;
use App\Models\Router;
use App\Models\User;

/**
 * Aprovisionamiento de un cliente al MikroTik (queue + secret PPPoE).
 *
 * Esta es la ÚNICA fuente de la lógica por-cliente: la usa tanto el job en cola
 * (ProvisionCustomerJob) como el endpoint síncrono legado (bulkProvision). Cada
 * llamada hace SSH al CORE → SSH anidado al router, lo que tarda ~17-34s; por
 * eso el camino real es asíncrono (un job por cliente).
 */
class CustomerProvisioningService
{
    /**
     * Provisiona un cliente y devuelve la fila de resultado (mismo shape que
     * consume el frontend). Es tenant-safe: un cliente de otro tenant se reporta
     * como "no encontrado" sin ejecutar nada (evita IDOR cross-tenant).
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

        $name = "{$customer->name} {$customer->last_name}";

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

        $mikrotik = app(MikroTikSshService::class);

        try {
            $queueResult = $mikrotik->syncQueueViaCore(
                $router->ip,
                $router->user_rb,
                $router->password_rb,
                $customer->ip_user,
                $customer->name,
                $customer->last_name,
                $servicePlan->speed_up,
                $servicePlan->speed_down,
                $router->puerto_api ?? 8728,
                $customer->pppoe_username,
                $name
            );
        } catch (\Throwable $e) {
            \Log::warning('[CustomerProvisioningService] Queue exception', [
                'customer_id' => $customerId,
                'error'       => $e->getMessage(),
            ]);
            $queueResult = ['success' => false, 'message' => 'Error al cargar queue: ' . $e->getMessage()];
        }

        $pppoeResult  = null;
        $pppoeSkipped = false;

        if ($router->pppoe) {
            if ($customer->pppoe_username && $customer->pppoe_password) {
                try {
                    $pppoeResult = $mikrotik->ensurePppoeSecretOnRouter(
                        $router->ip,
                        $router->user_rb,
                        $router->password_rb,
                        $customer->pppoe_username,
                        $customer->pppoe_password,
                        $servicePlan->name,
                        'pppoe',
                        $router->puerto_api ?? 8728,
                        $customer->ip_user,
                        $customer->pppoe_local_address,
                        $name
                    );
                } catch (\Throwable $e) {
                    \Log::warning('[CustomerProvisioningService] PPPoE secret exception', [
                        'customer_id' => $customerId,
                        'error'       => $e->getMessage(),
                    ]);
                    $pppoeResult = ['success' => false, 'message' => $e->getMessage()];
                }
            } else {
                $pppoeSkipped = true;
            }
        }

        $rowSuccess = $queueResult['success'] && ($pppoeResult === null || $pppoeResult['success']);

        return [
            'customer_id'    => $customerId,
            'customer_name'  => $name,
            'success'        => $rowSuccess,
            'pppoe_skipped'  => $pppoeSkipped,
            'pppoe_applies'  => (bool) $router->pppoe,
            'pppoe_created'  => $pppoeResult !== null && ($pppoeResult['success'] ?? false),
            'queue_ok'       => (bool) ($queueResult['success'] ?? false),
            'queue_message'  => $queueResult['message'] ?? (($queueResult['success'] ?? false) ? 'Queue cargado' : 'Error en queue'),
            'pppoe_message'  => $pppoeSkipped
                ? 'Credenciales PPPoE no configuradas en el cliente'
                : ($pppoeResult === null
                    ? 'El router no usa PPPoE'
                    : ($pppoeResult['message'] ?? (($pppoeResult['success'] ?? false) ? 'Secret PPPoE creado' : 'Error creando el secret PPPoE'))),
            'message'        => $rowSuccess
                ? ($pppoeSkipped ? 'Queue OK — credenciales PPPoE no configuradas' : 'OK')
                : ($queueResult['message'] ?? ($pppoeResult['message'] ?? 'Error')),
            'queue_result'   => $queueResult,
            'pppoe_result'   => $pppoeResult,
        ];
    }
}
