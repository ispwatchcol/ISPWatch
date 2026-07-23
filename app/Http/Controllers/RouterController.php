<?php

/** @noinspection PhpUndefinedVariableInspection */

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\StoreRouterRequest;
use App\Http\Requests\UpdateRouterRequest;
use App\Models\Router;
use App\Models\Billing;
use App\Services\VpnService;
use App\Services\MikroTikSshService;
use App\Services\MikroTik\SshTunnelManager;
use App\Traits\FixesSequences;
use Illuminate\Support\Facades\DB;

class RouterController extends Controller
{
    use FixesSequences;
    /**
     * Display a listing of the routers.
     */
    public function index()
    {
        // Tenant scoping is automatic via the BelongsToTenant global scope.
        // Fields/relation chosen to cover every former direct-Supabase reader
        // (Routers list, MassActions, Sectorial dropdowns, billing dropdowns).
        $routers = Router::with('cutType:id,name')->select(
            'id',
            'name',
            'ip',
            'user_rb',
            'lan_interface',
            'wan_interface',
            'cut_type_id',
            'simple_queue',
            'control_pcq',
            'hotspot',
            'pppoe',
            'dhcp_leases',
            'firmware_version',
            'status',
            'falla_general',
            'created_at'
        )->get();

        return response()->json($routers);
    }

    /**
     * Store a newly created router in storage.
     */
    public function store(StoreRouterRequest $request)
    {
        $data = $request->validated();
        $billingInput = $request->input('billing');
        $tenantId = $request->user()?->tenant_id;

        // Create the billing config + router atomically. Mirrors the former
        // two-step Supabase flow (insert billing, then router) but transactional,
        // so a failure never leaves an orphaned billing row or an unlinked router.
        $router = DB::transaction(function () use ($data, $billingInput, $tenantId) {
            if (is_array($billingInput)) {
                $billing = new Billing($this->billingConfigPayload($billingInput));
                $billing->tenant_id = $tenantId;
                $billing->save();
                $data['billing_router_id'] = $billing->id;
            }

            return $this->createWithSequenceFix(Router::class, $data);
        });

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
        // Include the linked billing config (frontend edit form reads `billing`).
        return response()->json($router->load('billing'));
    }

    /**
     * Update the specified router in storage.
     */
    public function update(UpdateRouterRequest $request, Router $router)
    {
        $data = $request->validated();
        $billingInput = $request->input('billing');
        $tenantId = $request->user()?->tenant_id;

        DB::transaction(function () use (&$data, $billingInput, $tenantId, $router) {
            if (is_array($billingInput)) {
                $payload = $this->billingConfigPayload($billingInput);
                if ($router->billing_router_id) {
                    // Safe: $router is already tenant-scoped via route-model binding,
                    // so its billing_router_id belongs to this tenant.
                    Billing::whereKey($router->billing_router_id)->update($payload);
                    $data['billing_router_id'] = $router->billing_router_id;
                } else {
                    $billing = new Billing($payload);
                    $billing->tenant_id = $tenantId;
                    $billing->save();
                    $data['billing_router_id'] = $billing->id;
                }
            }

            $router->update($data);
        });

        return response()->json([
            'message' => 'Router actualizado correctamente. ✅',
            'router' => $router->fresh()->load('billing'),
        ]);
    }

    /**
     * Whitelist of billing-config columns accepted when creating/updating a
     * router. Keeps mass-assignment tight and mirrors the columns the router
     * form sends (formatted dates/times are produced client-side).
     */
    private function billingConfigPayload(array $input): array
    {
        return collect($input)->only([
            'create_invoice', 'create_invoice_time', 'cut_day', 'cut_time',
            'payment_day', 'payment_reminder', 'payment_reminder_time',
            'payment_reminder_enabled', 'overdue_invoices', 'amount', 'id_type',
            'status', 'notificar_wpp', 'notification_type', 'billing_mode', 'comments',
        ])->toArray();
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

        // `router.ip` goes stale on every L2TP reconnect (the CORE re-assigns
        // from the tenant pool), so ask the CORE which address this router is
        // actually on before trying to read its interfaces.
        $endpoint = app(\App\Services\MikroTik\RouterEndpointResolver::class)->resolve($router);

        $sshService = new MikroTikSshService();
        $result = $sshService->getRouterInterfaces(
            $endpoint['ip'],
            $router->user_rb,
            $router->password_rb,
            $endpoint['api_port'],
            $router->firmware_version,  // lets InterfaceReader pick v6/v7-aware command syntax
            $endpoint['ssh_port']
        );

        // If successful, attach current WAN
        if ($result['success'] && !empty($result['interfaces'])) {
            $result['current_wan'] = $router->wan_interface;
            $result['configured_port'] = $router->puerto_api ?? 8728;
            return response()->json($result);
        }

        // Return the real error message — no hardcoded fallback
        return response()->json([
            'success'        => false,
            'message'        => $result['message'] ?? 'No se pudo obtener interfaces del router.',
            'attempts'       => $result['attempts'] ?? [],
            'interfaces'     => [],
            'current_wan'    => $router->wan_interface,
            'configured_port' => $router->puerto_api ?? 8728,
            'hint'           => $result['hint'] ?? 'Para configurar la WAN manualmente, ingresa el nombre de la interfaz en el campo de texto.',
        ]);
    }


    /**
     * Router/WAN traffic history: daily aggregates for the last N days plus
     * today/current-month totals. Data is collected by the scheduled
     * `traffic:collect` command into the traffic_daily table.
     */
    public function trafficHistory(Router $router, Request $request)
    {
        $days = (int) $request->query('days', 30);
        $days = max(1, min($days, 90));
        $since = now()->subDays($days - 1)->toDateString();

        $daily = \App\Models\TrafficDaily::where('router_id', $router->id)
            ->where('day', '>=', $since)
            ->orderBy('day')
            ->get(['day', 'rx_bytes', 'tx_bytes']);

        $todayRow = \App\Models\TrafficDaily::where('router_id', $router->id)
            ->where('day', now()->toDateString())
            ->first();

        $month = \App\Models\TrafficDaily::where('router_id', $router->id)
            ->where('day', '>=', now()->startOfMonth()->toDateString())
            ->selectRaw('COALESCE(SUM(rx_bytes),0) as rx, COALESCE(SUM(tx_bytes),0) as tx')
            ->first();

        return response()->json([
            'router_id'         => $router->id,
            'wan_interface'     => $router->wan_interface,
            'historial_trafico' => (bool) $router->historial_trafico,
            'days'              => $days,
            'daily'             => $daily->map(fn ($d) => [
                'day'      => $d->day->toDateString(),
                'rx_bytes' => (int) $d->rx_bytes,
                'tx_bytes' => (int) $d->tx_bytes,
            ]),
            'totals' => [
                'today' => [
                    'rx_bytes' => (int) ($todayRow->rx_bytes ?? 0),
                    'tx_bytes' => (int) ($todayRow->tx_bytes ?? 0),
                ],
                'month' => [
                    'rx_bytes' => (int) ($month->rx ?? 0),
                    'tx_bytes' => (int) ($month->tx ?? 0),
                ],
            ],
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

        // Resolve the live endpoint: router.ip drifts on L2TP reconnect and SSH
        // may be on a non-default port — a cut that lands on the wrong endpoint
        // silently leaves the customer online.
        $endpoint = app(\App\Services\MikroTik\RouterEndpointResolver::class)->resolve($router);

        // Intentar: 1) API directa al cliente, 2) SSH via CORE
        $sshService = new MikroTikSshService();
        $result = $sshService->applyBlockRulesViaCore(
            $endpoint['ip'],
            $router->user_rb,
            $router->password_rb,
            $router->wan_interface,
            $portalIp,
            $endpoint['api_port'],
            $router->firmware_version,
            $endpoint['ssh_port']
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

        $endpoint = app(\App\Services\MikroTik\RouterEndpointResolver::class)->resolve($router);

        $sshService = new MikroTikSshService();
        $result = $sshService->getFirewallRulesViaCore(
            $endpoint['ip'],
            $router->user_rb,
            $router->password_rb,
            $endpoint['ssh_port']
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

        // SECURITY FIX (OWASP A03): Validate IP before using in shell commands
        $validatedIp = filter_var($router->ip, FILTER_VALIDATE_IP);
        if (!$validatedIp) {
            return response()->json([
                'error' => 'Invalid IP address format',
                'ip' => $router->ip,
            ], 400);
        }

        $sshService = new MikroTikSshService();
        $ssh = $sshService->connect();

        $results = [];

        // 1. LOCAL CONNECTIVITY CHECK (Laravel -> Client)
        // Ping local — use escapeshellarg to prevent command injection
        $localPing = shell_exec("ping -n 2 " . escapeshellarg($validatedIp) . " 2>&1");

        // Fix for Windows console output encoding (sane default to avoid 500 error)
        $results['local_ping_from_laravel'] = mb_convert_encoding((string) $localPing, 'UTF-8', 'ISO-8859-1');

        // TCP Connect Local — client routers live behind the L2TP overlay, so we
        // probe through an SSH local-forward via the CORE.
        try {
            $tunnelManager = new SshTunnelManager();
            $tunnel = $tunnelManager->open($validatedIp, 8728);
            $fp = @fsockopen($tunnel->localHost(), $tunnel->localPort(), $errno, $errstr, 2);
            if ($fp) {
                $results['local_api_port_8728'] = "OPEN via CORE tunnel (local port {$tunnel->localPort()})";
                fclose($fp);
            } else {
                $results['local_api_port_8728'] = mb_convert_encoding(
                    "Tunnel up but client TCP refused: $errstr ($errno)",
                    'UTF-8',
                    'ISO-8859-1'
                );
            }
            $tunnel->close();
        } catch (\Throwable $e) {
            $results['local_api_port_8728'] = mb_convert_encoding(
                "Tunnel open failed: " . $e->getMessage(),
                'UTF-8',
                'ISO-8859-1'
            );
        }

        // 2. REMOTE CONNECTIVITY CHECK (CORE -> Client)
        if ($ssh) {
            // Ping from CORE to Client — IP is already validated above
            $remotePing = $ssh->exec("ping count=2 {$validatedIp}");
            $results['remote_ping_from_core'] = mb_convert_encoding((string) $remotePing, 'UTF-8', 'ISO-8859-1');

            // SECURITY FIX: Sanitize user/password for MikroTik CLI context
            // Only allow alphanumeric, dash, underscore, dot in username
            $safeUser = preg_replace('/[^a-zA-Z0-9._\-]/', '', $router->user_rb);

            // Try without password param just to see syntax check
            $cmd = ":do { /system ssh address={$validatedIp} user={$safeUser} command=\"/system identity print\" } on-error={ :put \"SSH_NO_PASS_ERROR\" }";
            $sshOut = $ssh->exec($cmd);
            $results['remote_ssh_test_no_pass'] = mb_convert_encoding((string) $sshOut, 'UTF-8', 'ISO-8859-1');

            $ssh->disconnect();
        } else {
            $results['remote_ping_from_core'] = "Could not connect to CORE to test";
        }

        return response()->json([
            'router' => $router->name,
            'client_ip' => $validatedIp,
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
