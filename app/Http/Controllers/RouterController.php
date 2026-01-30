<?php

/** @noinspection PhpUndefinedVariableInspection */

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Router;
use App\Services\VpnService;
use App\Traits\FixesSequences;

class RouterController extends Controller
{
    use FixesSequences;
    /**
     * Display a listing of the routers.
     */
    public function index()
    {
        $routers = Router::select(
            'id',
            'name',
            'ip',
            'firmware_version',
            'status',
            'created_at'
        )->get();

        return response()->json($routers);
    }

    /**
     * Store a newly created router in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'ip' => 'required|ip',
            'ipv6' => 'nullable|string|max:255',
            'failover' => 'nullable|string|max:255',
            'external_id' => 'nullable|string|max:255',
            'user_rb' => 'required|string|max:255',
            'password_rb' => 'required|string|max:255',
            'puerto_api' => 'nullable|integer|min:1|max:65535',
            'puerto_www' => 'nullable|integer|min:1|max:65535',
            'lan_interface' => 'nullable|string|max:255',
            'wan_interface' => 'nullable|string|max:255',
            'vpn_username' => 'nullable|string|max:255',
            'vpn_password' => 'nullable|string|max:255',
            'comments' => 'nullable|string',
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
            'ip_bindings' => 'nullable|boolean',
            'amarre' => 'nullable|boolean',
            'dhcp_leases' => 'nullable|boolean',
            'falla_general' => 'nullable|boolean',
        ]);

        $router = $this->createWithSequenceFix(Router::class, $data);

        return response()->json([
            'message' => 'Router creado correctamente. ✅',
            'router' => $router,
        ], 201);
    }


    /**
     * Display the specified router.
     */
    public function show(Router $router)
    {
        return response()->json($router);
    }

    /**
     * Update the specified router in storage.
     */
    public function update(Request $request, Router $router)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'ip' => 'sometimes|ip',
            'ipv6' => 'nullable|string|max:255',
            'failover' => 'nullable|string|max:255',
            'external_id' => 'nullable|string|max:255',
            'user_rb' => 'sometimes|string|max:255',
            'password_rb' => 'sometimes|string|max:255',
            'puerto_api' => 'nullable|integer|min:1|max:65535',
            'puerto_www' => 'nullable|integer|min:1|max:65535',
            'lan_interface' => 'nullable|string|max:255',
            'wan_interface' => 'nullable|string|max:255',
            'vpn_username' => 'nullable|string|max:255',
            'vpn_password' => 'nullable|string|max:255',
            'comments' => 'nullable|string',
            'cut_type_id' => 'nullable|integer',
            'billing_router_id' => 'nullable|integer',
            'firmware_version' => 'sometimes|string|max:100',
            'status' => 'sometimes|string|max:50',
            'coordinates' => 'nullable',
            'agregar_cliente_mkt' => 'nullable|boolean',
            'historial_trafico' => 'nullable|boolean',
            'simple_queue' => 'nullable|boolean',
            'control_pcq' => 'nullable|boolean',
            'hotspot' => 'nullable|boolean',
            'pppoe' => 'nullable|boolean',
            'ip_bindings' => 'nullable|boolean',
            'amarre' => 'nullable|boolean',
            'dhcp_leases' => 'nullable|boolean',
            'falla_general' => 'nullable|boolean',
        ]);

        $router->update($data);

        return response()->json([
            'message' => 'Router actualizado correctamente. ✅',
            'router' => $router,
        ]);
    }

    /**
     * Remove the specified router from storage.
     */
    public function destroy(Router $router)
    {
        $router->delete();

        return response()->json([
            'message' => 'Router eliminado exitosamente. ✅',
        ]);
    }

    /**
     * Generate VPN script for the router
     */
    public function generateVpnScript(Router $router)
    {
        $vpnService = new VpnService();
        $script = $vpnService->generateScript($router);

        return response()->json([
            'success' => true,
            'script' => $script,
            'server_ip' => $vpnService->getServerPublicIp(),
        ]);
    }

    /**
     * Verify VPN connection status
     */
    public function verifyVpnConnection(Router $router)
    {
        $vpnService = new VpnService();
        $result = $vpnService->verifyConnection($router);

        return response()->json($result);
    }

    /**
     * Get interfaces from the client router
     * Conecta directamente al router cliente via API (funciona desde producción vía VPN)
     */
    public function getInterfaces(Router $router)
    {
        // Validar que el router tenga credenciales
        if (!$router->ip || !$router->user_rb || !$router->password_rb) {
            return response()->json([
                'success' => false,
                'message' => 'Router sin credenciales configuradas. Verifica la conexión VPN primero.',
                'interfaces' => [],
            ]);
        }

        // Usar VpnService que se conecta directamente al router cliente via API
        // Esto funciona en producción porque el servidor tiene acceso a la red VPN
        $vpnService = new VpnService();
        $result = $vpnService->getInterfaces($router);

        // Si falla la conexión directa, ofrecer interfaces sugeridas
        if (!$result['success']) {
            return response()->json([
                'success' => true,
                'message' => 'No se pudo conectar al router. Selecciona la interfaz WAN manualmente.',
                'interfaces' => [
                    ['name' => 'ether1', 'type' => 'ether', 'running' => true, 'disabled' => false, 'comment' => 'WAN típico'],
                    ['name' => 'ether2', 'type' => 'ether', 'running' => true, 'disabled' => false, 'comment' => ''],
                    ['name' => 'ether3', 'type' => 'ether', 'running' => true, 'disabled' => false, 'comment' => ''],
                    ['name' => 'ether4', 'type' => 'ether', 'running' => true, 'disabled' => false, 'comment' => ''],
                    ['name' => 'ether5', 'type' => 'ether', 'running' => true, 'disabled' => false, 'comment' => ''],
                    ['name' => 'bridge', 'type' => 'bridge', 'running' => true, 'disabled' => false, 'comment' => 'LAN Bridge'],
                    ['name' => 'wlan1', 'type' => 'wlan', 'running' => true, 'disabled' => false, 'comment' => 'WiFi'],
                ],
                'current_wan' => $router->wan_interface,
                'note' => 'Error de conexión. Interfaces sugeridas para selección manual.',
                'error_detail' => $result['message'] ?? 'Unknown error',
            ]);
        }

        return response()->json($result);
    }

    /**
     * Set WAN interface for the router
     */
    public function setWanInterface(Request $request, Router $router)
    {
        $data = $request->validate([
            'wan_interface' => 'required|string|max:255',
        ]);

        $router->update([
            'wan_interface' => $data['wan_interface'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Interfaz WAN configurada correctamente',
            'wan_interface' => $router->wan_interface,
        ]);
    }

    /**
     * Apply firewall block rules for delinquent users
     * Usa conexión API directa al router cliente (funciona en producción con acceso a red VPN)
     */
    public function applyBlockRules(Router $router)
    {
        // Usar RouterApiService que conecta directamente al router cliente via API
        // Esto funciona en producción donde el servidor tiene acceso a la red VPN
        $routerApi = new \App\Services\RouterApiService();
        $result = $routerApi->applyBlockRules($router);

        return response()->json($result);
    }

    /**
     * Test connection to MikroTik CORE server
     * Uses SSH (preferred) or API as fallback
     */
    public function testCoreConnection()
    {
        // Try SSH first (preferred method)
        $sshService = new \App\Services\MikroTikSshService();
        $sshResult = $sshService->testConnection();

        if ($sshResult['success']) {
            return response()->json([
                'success' => true,
                'method' => 'SSH',
                'message' => $sshResult['message'],
                'identity' => $sshResult['identity'] ?? null,
                'config' => $sshResult['config'],
            ]);
        }

        // Fallback: try API connection
        $config = [
            'api_host' => env('MIKROTIK_CORE_API_HOST', '192.168.88.1'),
            'api_port' => env('MIKROTIK_CORE_API_PORT', 8728),
            'vpn_ip' => env('MIKROTIK_CORE_VPN_IP', '190.14.255.107'),
        ];

        $socket = @fsockopen($config['api_host'], $config['api_port'], $errno, $errstr, 10);

        if ($socket) {
            fclose($socket);
            return response()->json([
                'success' => true,
                'method' => 'API',
                'message' => '✅ Conexión API al CORE MikroTik exitosa',
                'config' => $config,
                'ssh_error' => $sshResult['message'] ?? 'SSH no disponible',
            ]);
        }

        // Both failed
        return response()->json([
            'success' => false,
            'message' => '❌ No se pudo conectar al CORE MikroTik (ni SSH ni API)',
            'ssh_result' => $sshResult,
            'api_error' => $errstr,
            'api_config' => $config,
            'recommendation' => 'Verifica: 1) La IP del MikroTik, 2) El passphrase de la clave SSH, 3) Que el puerto 22 o 8728 esté abierto',
        ], 503);
    }
}
