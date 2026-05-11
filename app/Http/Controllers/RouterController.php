<?php

/** @noinspection PhpUndefinedVariableInspection */

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\StoreRouterRequest;
use App\Http\Requests\UpdateRouterRequest;
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
            'pppoe',
            'firmware_version',
            'status',
            'created_at'
        )->get();

        return response()->json($routers);
    }

    /**
     * Store a newly created router in storage.
     */
    public function store(StoreRouterRequest $request)
    {
        $router = $this->createWithSequenceFix(Router::class, $request->validated());

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
    public function update(UpdateRouterRequest $request, Router $router)
    {
        $router->update($request->validated());

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
     * Generate VPN script for the router and sync the PPP secret to CORE.
     * VpnService::generateScript() already calls syncPppSecret internally.
     */
    public function generateVpnScript(Router $router)
    {
        $vpnService = new VpnService();
        $script = $vpnService->generateScript($router);

        // Re-read router to get updated vpn_username after generateScript saved it
        $router->refresh();

        // Confirm the secret exists on the CORE by querying it
        $sshService = new MikroTikSshService();
        $secretCheck = $sshService->getPppSecret($router->vpn_username ?? '');

        $secretSynced = $secretCheck['success'] ?? false;

        return response()->json([
            'success'        => true,
            'script'         => $script,
            'server_ip'      => $vpnService->getServerPublicIp(),
            'vpn_username'   => $router->vpn_username,
            'secret_synced'  => $secretSynced,
            'secret_message' => $secretSynced
                ? '✅ Secret VPN creado/verificado correctamente en el CORE MikroTik'
                : '⚠️ Script generado pero no se pudo confirmar el secret en el CORE. Verifica la conexión al MikroTik.',
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
     * Get interfaces from the client router.
     * Tries: 1) Direct API, 2) CORE → SSH-exec to client.
     * Returns the real error if both fail (no silent hardcoded fallback).
     */
    public function getInterfaces(Router $router)
    {
        // Validate credentials
        if (!$router->ip || !$router->user_rb || !$router->password_rb) {
            return response()->json([
                'success'    => false,
                'message'    => 'Router sin credenciales configuradas (user_rb / password_rb). Genera el script VPN primero.',
                'interfaces' => [],
            ]);
        }

        $sshService = new MikroTikSshService();
        $result = $sshService->getRouterInterfaces(
            $router->ip,
            $router->user_rb,
            $router->password_rb,
            $router->puerto_api ?? 8728
        );

        // If successful, attach current WAN
        if ($result['success'] && !empty($result['interfaces'])) {
            $result['current_wan'] = $router->wan_interface;
            return response()->json($result);
        }

        // Return the real error message — no hardcoded fallback
        return response()->json([
            'success'    => false,
            'message'    => $result['message'] ?? 'No se pudo obtener interfaces del router.',
            'interfaces' => [],
            'current_wan' => $router->wan_interface,
            'hint'       => 'Para configurar la WAN manualmente, ingresa el nombre de la interfaz en el campo de texto.',
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

    /**
     * Diagnostic endpoint to test queue sync on a router
     * Tests the ssh-exec mechanism from CORE to client
     */
    public function testQueueSync(Router $router)
    {
        if (!$router->ip || !$router->user_rb || !$router->password_rb) {
            return response()->json([
                'success' => false,
                'message' => 'Router sin credenciales configuradas',
            ], 400);
        }

        $sshService = new MikroTikSshService();

        // Test with a dummy queue to see if ssh-exec works
        $result = $sshService->syncQueueViaCore(
            $router->ip,
            $router->user_rb,
            $router->password_rb,
            '192.168.88.254',  // Dummy target IP for testing
            'Test',
            'Queue',
            '1M',
            '1M',
            $router->puerto_api ?? 8728
        );

        return response()->json([
            'router_id' => $router->id,
            'router_name' => $router->name,
            'router_ip' => $router->ip,
            'test_result' => $result,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Return free IPs for a router based on its rangos_ip (CIDR notation, one per line).
     * Subtracts IPs already assigned to any customer in the same tenant.
     */
    public function getFreeIps(Router $router, Request $request): \Illuminate\Http\JsonResponse
    {
        $rangosIp = trim($router->rangos_ip ?? '');

        if (!$rangosIp) {
            return response()->json(['ranges' => [], 'free_ips' => [], 'message' => 'El router no tiene rangos IP configurados.']);
        }

        // Collect used IPs from customer_profile for this tenant
        $tenantId = $request->query('tenant_id') ?? $request->query('tenant');
        $usedQuery = \App\Models\CustomerProfile::join('users', 'customer_profile.user_id', '=', 'users.id')
            ->whereNotNull('customer_profile.ip_user');
        if ($tenantId) {
            $usedQuery->where('users.tenant_id', $tenantId);
        }
        $usedIps = $usedQuery->pluck('customer_profile.ip_user')->toArray();
        $usedSet = array_flip($usedIps);

        $lines    = array_filter(array_map('trim', explode("\n", $rangosIp)));
        $ranges   = [];
        $freeIps  = [];

        foreach ($lines as $cidr) {
            if (!preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\/(\d{1,2})$/', $cidr, $m)) continue;

            $prefix = (int) $m[2];
            if ($prefix < 20 || $prefix > 30) continue;

            $ipLong    = ip2long($m[1]);
            $mask      = ~((1 << (32 - $prefix)) - 1);
            $network   = $ipLong & $mask;
            $broadcast = $network | ~$mask;

            $hosts = [];
            $free  = [];
            for ($i = $network + 1; $i < $broadcast; $i++) {
                $ip = long2ip($i);
                $hosts[] = $ip;
                if (!isset($usedSet[$ip])) {
                    $free[]    = $ip;
                    $freeIps[] = $ip;
                }
            }

            $ranges[] = [
                'cidr'  => $cidr,
                'total' => count($hosts),
                'used'  => count($hosts) - count($free),
                'free'  => count($free),
            ];
        }

        return response()->json([
            'rangos_ip' => $rangosIp,
            'ranges'    => $ranges,
            'free_ips'  => $freeIps,
            'used_ips'  => array_values($usedIps),
        ]);
    }
}
