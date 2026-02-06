<?php

/** @noinspection PhpUndefinedVariableInspection */

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Router;
use App\Services\VpnService;
use App\Services\MikroTikSshService;
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

        // DIAGNÓSTICO: Re-intentar sincronización explícita para exponer el resultado/error al frontend
        // Esto ayuda a depurar por qué falla silenciosamente en VpnService
        $sshService = new MikroTikSshService();
        $debugSync = $sshService->ensurePppSecret(
            $router->vpn_username ?? 'unknown',
            $router->vpn_password ?? 'unknown',
            'l2tp',
            'default'
        );

        return response()->json([
            'success' => true,
            'script' => $script,
            'server_ip' => $vpnService->getServerPublicIp(),
            'debug_sync_result' => $debugSync,
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
     * Conecta al CORE via SSH y desde allí conecta al router cliente via SSH
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

        // Usar SSH al CORE, luego SSH al cliente para obtener interfaces
        $sshService = new MikroTikSshService();
        $result = $sshService->getRouterInterfaces(
            $router->ip,
            $router->user_rb,
            $router->password_rb,
            $router->puerto_api ?? 8728
        );

        // Si obtuvo interfaces exitosamente, agregarlas
        if ($result['success'] && !empty($result['interfaces'])) {
            $result['current_wan'] = $router->wan_interface;
            return response()->json($result);
        }

        // Si falla la conexión, ofrecer interfaces sugeridas para selección manual
        return response()->json([
            'success' => true,
            'message' => $result['message'] ?? 'No se pudo obtener interfaces. Selecciona manualmente.',
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
            'note' => 'Interfaces sugeridas. El error fue: ' . ($result['message'] ?? 'desconocido'),
        ]);
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
     * Primero intenta API directa al router cliente, luego SSH via CORE
     */
    public function applyBlockRules(Router $router)
    {
        // Validar credenciales y WAN
        if (!$router->ip || !$router->user_rb || !$router->password_rb) {
            return response()->json([
                'success' => false,
                'message' => 'Router sin credenciales configuradas. Verifica la conexión VPN primero.',
            ]);
        }

        if (!$router->wan_interface) {
            return response()->json([
                'success' => false,
                'message' => 'Router sin interfaz WAN configurada. Configura la WAN primero.',
            ]);
        }

        // Obtener IP del portal
        $portalIp = env('PORTAL_IP');
        if (!$portalIp) {
            return response()->json([
                'success' => false,
                'message' => 'Configure PORTAL_IP en .env para habilitar la redirección al portal',
            ]);
        }

        // Puerto API del router (default 8728)
        $apiPort = $router->puerto_api ?? 8728;

        // Intentar: 1) API directa al cliente, 2) SSH via CORE
        $sshService = new MikroTikSshService();
        $result = $sshService->applyBlockRulesViaCore(
            $router->ip,
            $router->user_rb,
            $router->password_rb,
            $router->wan_interface,
            $portalIp,
            $apiPort
        );

        return response()->json($result);
    }

    /**
     * Verify firewall block rules installed on client router
     * Usa SSH via CORE para verificar las reglas
     */
    public function verifyBlockRules(Router $router)
    {
        if (!$router->ip || !$router->user_rb || !$router->password_rb) {
            return response()->json([
                'success' => false,
                'message' => 'Router sin credenciales configuradas.',
            ]);
        }

        $sshService = new MikroTikSshService();
        $result = $sshService->getFirewallRulesViaCore(
            $router->ip,
            $router->user_rb,
            $router->password_rb
        );

        return response()->json($result);
    }

    /**
     * DIAGNOSTIC METHOD: Test SSH connection from CORE to Client
     * This exposes the raw SSH output to debug why rules are not applying.
     */
    /**
     * DIAGNOSTIC METHOD: Test SSH connection from CORE to Client
     * This exposes the raw SSH output to debug why rules are not applying.
     */
    public function testClientSshConnection(Router $router)
    {
        if (!$router->ip || !$router->user_rb || !$router->password_rb) {
            return response()->json(['error' => 'Missing credentials']);
        }

        $sshService = new MikroTikSshService();
        $ssh = $sshService->connect();

        $results = [];

        // 1. LOCAL CONNECTIVITY CHECK (Laravel -> Client)
        // Ping local
        $localPing = shell_exec("ping -n 2 {$router->ip} 2>&1");

        // Fix for Windows console output encoding (sane default to avoid 500 error)
        $results['local_ping_from_laravel'] = mb_convert_encoding((string) $localPing, 'UTF-8', 'ISO-8859-1');

        // TCP Connect Local
        $fp = @fsockopen($router->ip, 8728, $errno, $errstr, 2);
        if ($fp) {
            $results['local_api_port_8728'] = "OPEN - Connected successfully";
            fclose($fp);
        } else {
            $results['local_api_port_8728'] = mb_convert_encoding("CLOSED/TIMEOUT - $errstr ($errno)", 'UTF-8', 'ISO-8859-1');
        }

        // 2. REMOTE CONNECTIVITY CHECK (CORE -> Client)
        if ($ssh) {
            // Ping from CORE to Client
            $remotePing = $ssh->exec("ping count=2 {$router->ip}");
            $results['remote_ping_from_core'] = mb_convert_encoding((string) $remotePing, 'UTF-8', 'ISO-8859-1');

            // Check direct API/SSH failing command output again just to confirm
            $safePass = str_replace("'", "\\'", $router->password_rb);
            $user = $router->user_rb;
            $ip = $router->ip;

            // Try without password param just to see syntax check
            $cmd = ":do { /system ssh address=$ip user=$user command=\"/system identity print\" } on-error={ :put \"SSH_NO_PASS_ERROR\" }";
            $sshOut = $ssh->exec($cmd);
            $results['remote_ssh_test_no_pass'] = mb_convert_encoding((string) $sshOut, 'UTF-8', 'ISO-8859-1');

            $ssh->disconnect();
        } else {
            $results['remote_ping_from_core'] = "Could not connect to CORE to test";
        }

        return response()->json([
            'router' => $router->name,
            'client_ip' => $router->ip,
            'results' => $results
        ]);
    }

    /**
     * Test connection to MikroTik CORE server
     * Tests both API (primary) and SSH (fallback) connections
     */
    public function testCoreConnection()
    {
        $service = new MikroTikSshService();
        $result = $service->testConnection();

        // Determine best response based on what works
        $apiWorks = $result['api']['success'] ?? false;
        $sshWorks = $result['ssh']['success'] ?? false;

        if (!$apiWorks && !$sshWorks) {
            return response()->json([
                'success' => false,
                'message' => '❌ No se pudo conectar al CORE MikroTik (ni API ni SSH)',
                'api' => $result['api'],
                'ssh' => $result['ssh'],
                'config' => $result['config'],
                'recommendation' => 'Verifica: 1) La IP del MikroTik, 2) Que el puerto 8728 (API) o 22 (SSH) esté abierto, 3) Credenciales correctas',
            ], 503);
        }

        return response()->json([
            'success' => true,
            'preferred_method' => $result['preferred_method'],
            'message' => $apiWorks
                ? '✅ Conexión API al CORE MikroTik exitosa'
                : '✅ Conexión SSH al CORE MikroTik exitosa',
            'api' => $result['api'],
            'ssh' => $result['ssh'],
            'config' => $result['config'],
        ]);
    }

    /**
     * Test secret synchronization with CORE
     * Diagnostic endpoint to verify secret creation in production
     */
    public function testSecretSync(Router $router)
    {
        // Generar o usar credenciales VPN existentes
        $vpnUsername = $router->vpn_username;
        $vpnPassword = $router->vpn_password;

        if (empty($vpnUsername)) {
            $vpnUsername = \Illuminate\Support\Str::random(10);
        }
        if (empty($vpnPassword)) {
            $vpnPassword = \Illuminate\Support\Str::random(20);
        }

        // Intentar sincronizar el secret
        $sshService = new MikroTikSshService();
        $result = $sshService->ensurePppSecret($vpnUsername, $vpnPassword, 'l2tp', 'default');

        return response()->json([
            'router_id' => $router->id,
            'router_name' => $router->name,
            'vpn_username' => $vpnUsername,
            'password_length' => strlen($vpnPassword),
            'sync_result' => $result,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
