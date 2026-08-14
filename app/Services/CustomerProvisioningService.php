<?php

namespace App\Services;

use App\Models\CustomerProfile;
use App\Models\Plan;
use App\Models\Router;
use App\Models\User;
use App\Services\MikroTik\RouterEndpointResolver;

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
    public const MODE_RADIUS       = 'radius';

    private RouterEndpointResolver $endpoints;

    public function __construct(?RouterEndpointResolver $endpoints = null)
    {
        $this->endpoints = $endpoints ?? new RouterEndpointResolver();
    }

    /**
     * Devuelve el método de control activo del router (excluyente) o null.
     * El orden refleja la prioridad usada por el frontend (setControlMode).
     */
    public static function resolveControlMode(Router $router): ?string
    {
        // RADIUS va primero: si alguna vez un router quedara con dos banderas
        // encendidas, la que NO escribe en el RouterBoard es la que menos daño
        // hace ganando el desempate.
        if ($router->radius)       return self::MODE_RADIUS;
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
        //
        // No aplica a RADIUS: ese modo no abre una sola sesión SSH, así que
        // exigirle credenciales de gestión rechazaría clientes perfectamente
        // aprovisionables. Un router RADIUS puede no tener VPN configurada
        // todavía y aun así atender clientes.
        if (!$router->usesRadius() && (!$router->ip || !$router->user_rb || !$router->password_rb)) {
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
        $name = trim("{$customer->name} {$customer->last_name}");
        $mode = self::resolveControlMode($router);

        // ── RADIUS: se resuelve aquí y se sale, ANTES de tocar la red ──
        //
        // Va delante de todo a propósito. Debajo de esta línea el método hace
        // dos cosas caras que para RADIUS no tendrían sentido: resolver el
        // endpoint (que abre SSH contra el CORE para preguntar qué IP tiene
        // realmente el router) y abrir sesiones SSH al RouterBoard.
        //
        // En RADIUS el aprovisionamiento por-cliente NO escribe en el router:
        // el equipo pregunta en cada conexión y la respuesta sale de la BD.
        // Resolver el endpoint solo para no usarlo agregaría ~17-34s y un
        // punto de falla (CORE inalcanzable) a una operación que no necesita
        // la red en absoluto. De ahí que el aprovisionamiento masivo en modo
        // RADIUS deje de dar 504.
        if ($mode === self::MODE_RADIUS) {
            return $this->provisionRadius($customer);
        }

        // Never dial `router.ip` blindly: the CORE hands client routers a pool
        // address on each L2TP reconnect, so the stored value goes stale and
        // every push lands on an address nobody answers (`<connection failed>`
        // / `action timed out`, which reads like a client-side firewall issue).
        // The resolver asks the CORE which address this router is really using.
        $mikrotik = app(MikroTikSshService::class);
        $endpoint = $this->endpoints->resolve($router);
        $ip       = $endpoint['ip'];
        $port     = $endpoint['api_port'];
        $sshPort  = $endpoint['ssh_port'];

        $queueResult   = null;
        $pppoeResult   = null;
        $hotspotResult = null;
        $pcqResult     = null;
        $dhcpResult    = null;
        $arpResult     = null;
        $amarreResult  = null;
        $pppoeSkipped  = false;
        $skipped       = false;
        $message       = 'OK';

        try {
            switch ($mode) {
                case self::MODE_SIMPLE_QUEUE:
                    $queueResult = $this->runQueue($mikrotik, $router, $customer, $plan, $name, $port, $ip, $sshPort);
                    break;

                case self::MODE_PCQ:
                    $pcqResult = $mikrotik->ensureClientInAddressList(
                        $ip, $router->user_rb, $router->password_rb,
                        $plan->name, $customer->ip_user, $port, $name, $sshPort
                    );
                    break;

                case self::MODE_HOTSPOT:
                    if ($customer->hotspot_username && $customer->hotspot_password) {
                        $hotspotResult = $mikrotik->ensureHotspotUserOnRouter(
                            $ip, $router->user_rb, $router->password_rb,
                            $customer->hotspot_username, $customer->hotspot_password,
                            $plan->name, $port, $customer->ip_user, $name, $sshPort
                        );
                    } else {
                        $skipped = true;
                        $message = 'El router usa HotSpot pero el cliente no tiene credenciales HotSpot configuradas';
                    }
                    break;

                case self::MODE_PPPOE:
                    if ($customer->pppoe_username && $customer->pppoe_password) {
                        $pppoeResult = $mikrotik->ensurePppoeSecretOnRouter(
                            $ip, $router->user_rb, $router->password_rb,
                            $customer->pppoe_username, $customer->pppoe_password,
                            $plan->name, 'pppoe', $port,
                            $customer->ip_user, $customer->pppoe_local_address, $name, $sshPort
                        );
                        // En modo 'queue' el secret se complementa con una Simple
                        // Queue; en 'dynamic' el rate-limit lo aplica el perfil.
                        if (($router->pppoe_limit_mode ?? 'dynamic') === 'queue') {
                            $queueResult = $this->runQueue($mikrotik, $router, $customer, $plan, $name, $port, $ip, $sshPort);
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
                            $ip, $router->user_rb, $router->password_rb,
                            $customer->ip_user, $customer->mac_address,
                            $plan->is_courtesy ? '0' : $plan->speed_up,
                            $plan->is_courtesy ? '0' : $plan->speed_down,
                            $port, $name, $sshPort
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

            // ── Opciones adicionales (aditivas, combinables con el control) ──
            // Solo corren si el router las tiene activas. Requieren la MAC del
            // cliente; si falta, se reporta como paso fallido (visible) en vez
            // de aplicarse a medias.
            if ($router->ip_bindings) {
                $arpResult = $customer->mac_address
                    ? $mikrotik->ensureArpBindingOnRouter(
                        $ip, $router->user_rb, $router->password_rb,
                        $customer->ip_user, $customer->mac_address, $router->lan_interface, $port, $name, $sshPort
                    )
                    : ['success' => false, 'message' => 'IP Bindings activo pero el cliente no tiene MAC configurada'];
            }
            if ($router->amarre) {
                $amarreResult = $customer->mac_address
                    ? $mikrotik->ensureMacAmarreOnRouter(
                        $ip, $router->user_rb, $router->password_rb,
                        $customer->ip_user, $customer->mac_address, $port, $name, $sshPort
                    )
                    : ['success' => false, 'message' => 'Amarre IP/MAC activo pero el cliente no tiene MAC configurada'];
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
                'arp_result'     => $arpResult,
                'amarre_result'  => $amarreResult,
            ];
        }

        // Éxito = todos los pasos ejecutados terminaron OK. Un modo "saltado"
        // por datos faltantes NO es éxito (el cliente queda sin cargar).
        $steps = array_filter([$queueResult, $pppoeResult, $hotspotResult, $pcqResult, $dhcpResult, $arpResult, $amarreResult]);
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
            'arp_result'     => $arpResult,
            'amarre_result'  => $amarreResult,
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
     * Aprovisionamiento en modo RADIUS.
     *
     * Es deliberadamente casi vacío: valida que el cliente tenga con qué
     * autenticarse y devuelve. No hay nada que empujar al router porque en
     * este modo el estado del cliente no vive en el RouterBoard.
     *
     * Se validan las credenciales PPPoE porque RADIUS autentica la sesión
     * PPPoE: el User-Name que llega en el Access-Request es pppoe_username.
     * Sin ellas el cliente nunca podría conectarse, así que reportarlo aquí
     * —con el mismo mensaje que usa el modo PPPoE directo— le ahorra al
     * operador descubrirlo cuando el cliente llame.
     *
     * La configuración del router (servidor PPPoE, cliente RADIUS, walled
     * garden) se instala UNA vez por equipo, no por cliente, y es trabajo de
     * RouterPolicyInstallerService.
     *
     * SOBRE ip_bindings Y amarre
     * --------------------------
     * Esta rama sale antes de aplicarlos, y no es un olvido: ambos existen
     * para atar una IP a una MAC en redes donde el cliente toma la IP por su
     * cuenta (ARP estático + drop por par IP/MAC). Con RADIUS sobre PPPoE la
     * IP la entrega la sesión autenticada, así que el amarre lo hace ya el
     * propio login — replicarlo con reglas estáticas no agrega seguridad y sí
     * agrega SSH que este modo no necesita.
     *
     * @return array Mismo shape que provisionByControlMode; el job y el
     *               frontend ya lo consumen sin cambios.
     */
    private function provisionRadius(CustomerProfile $customer): array
    {
        $hasCredentials = $customer->pppoe_username && $customer->pppoe_password;

        return [
            'success'        => $hasCredentials,
            'mode'           => self::MODE_RADIUS,
            'message'        => $hasCredentials
                ? 'OK'
                : 'El router usa RADIUS pero el cliente no tiene credenciales PPPoE configuradas',
            'queue_result'   => null,
            'pppoe_result'   => null,
            'hotspot_result' => null,
            'pcq_result'     => null,
            'dhcp_result'    => null,
            'arp_result'     => null,
            'amarre_result'  => null,
            // Compatibilidad con el job/bulk legado. pppoe_applies queda en
            // false: mide si hay que escribir un secret PPPoE en el router, y
            // en RADIUS no lo hay aunque el cliente sí use PPPoE.
            'pppoe_applies'  => false,
            'pppoe_skipped'  => false,
            'pppoe_created'  => false,
            'queue_ok'       => null,
            'queue_message'  => null,
            'pppoe_message'  => null,
        ];
    }

    /**
     * Helper: Simple Queue con el nombre espejando el secret PPPoE cuando existe
     * y el comentario con el nombre completo (igual que el flujo previo).
     */
    private function runQueue(MikroTikSshService $mikrotik, Router $router, CustomerProfile $customer, Plan $plan, string $name, int $port, string $ip, ?int $sshPort = null): array
    {
        return $mikrotik->syncQueueViaCore(
            $ip,
            $router->user_rb,
            $router->password_rb,
            $customer->ip_user,
            $customer->name,
            $customer->last_name,
            $plan->is_courtesy ? '0' : $plan->speed_up,
            $plan->is_courtesy ? '0' : $plan->speed_down,
            $port,
            $customer->pppoe_username,
            $name,
            $sshPort
        );
    }
}
