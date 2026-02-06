<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * Servicio para conexión al MikroTik CORE
 * Soporta conexión via API (puerto 8728) y SSH (puerto 22)
 * 
 * ESTRATEGIA DE CONEXIÓN:
 * 1. Intentar API primero (funciona en producción via Cloudflare)
 * 2. Si falla, intentar SSH como fallback (funciona en local)
 */
class MikroTikSshService
{
    // SSH Configuration
    private string $sshHost;
    private int $sshPort;
    private string $sshUsername;
    private ?string $sshPassword;
    private ?string $privateKeyPath;
    private ?string $keyPassphrase;

    // API Configuration
    private string $apiHost;
    private int $apiPort;
    private string $apiUser;
    private string $apiPass;

    private int $timeout = 15; // Reduced from 30 to avoid DigitalOcean App Platform timeouts

    public function __construct()
    {
        // SSH Configuration
        $this->sshHost = env('MIKROTIK_CORE_SSH_HOST', env('MIKROTIK_CORE_API_HOST', '138.197.30.155'));
        $this->sshPort = (int) env('MIKROTIK_CORE_SSH_PORT', 22);
        $this->sshUsername = env('MIKROTIK_CORE_SSH_USER', 'admin');
        $this->sshPassword = env('MIKROTIK_CORE_SSH_PASS', null);
        $this->privateKeyPath = env('MIKROTIK_CORE_SSH_KEY_PATH', storage_path('keys/mikrotik_core_id_ed25519'));
        $this->keyPassphrase = env('MIKROTIK_CORE_SSH_KEY_PASSPHRASE', null);

        // API Configuration
        $this->apiHost = env('MIKROTIK_CORE_API_HOST', '138.197.30.155');
        $this->apiPort = (int) env('MIKROTIK_CORE_API_PORT', 8728);
        $this->apiUser = env('MIKROTIK_CORE_API_USER', 'admin');
        $this->apiPass = env('MIKROTIK_CORE_API_PASS', 'Colombia2018');
    }

    // ==================== CONNECTION TESTING ====================

    /**
     * Test both API and SSH connections to MikroTik CORE
     * Optimized: If API works, SSH test is skipped to avoid long timeouts
     */
    public function testConnection(): array
    {
        // Try API first (fast, 5-10 seconds max)
        $apiResult = $this->testApiConnection();

        // If API works, skip SSH test (saves 30+ seconds in production)
        if ($apiResult['success']) {
            return [
                'success' => true,
                'api' => $apiResult,
                'ssh' => [
                    'success' => false,
                    'message' => 'SSH test skipped (API working)',
                    'skipped' => true,
                ],
                'preferred_method' => 'API',
                'config' => $this->getConfig(),
            ];
        }

        // API failed, try SSH as fallback (with reduced timeout)
        $sshResult = $this->testSshConnection(10); // 10 second timeout for test

        return [
            'success' => $sshResult['success'],
            'api' => $apiResult,
            'ssh' => $sshResult,
            'preferred_method' => $sshResult['success'] ? 'SSH' : 'NONE',
            'config' => $this->getConfig(),
        ];
    }

    /**
     * Test API connection to MikroTik CORE
     */
    public function testApiConnection(): array
    {
        try {
            $socket = @fsockopen($this->apiHost, $this->apiPort, $errno, $errstr, 10);

            if (!$socket) {
                return [
                    'success' => false,
                    'message' => "❌ No se pudo conectar a API: $errstr",
                    'host' => $this->apiHost,
                    'port' => $this->apiPort,
                ];
            }

            stream_set_timeout($socket, $this->timeout);

            // Try login
            if (!$this->apiLogin($socket, $this->apiUser, $this->apiPass)) {
                fclose($socket);
                return [
                    'success' => false,
                    'message' => '❌ Error de autenticación API',
                ];
            }

            // Get identity
            $this->apiSendCommand($socket, '/system/identity/print');
            $records = $this->apiReadAllRecords($socket);

            $identity = $records[0]['name'] ?? 'Unknown';

            fclose($socket);

            return [
                'success' => true,
                'message' => '✅ Conexión API al MikroTik CORE exitosa',
                'identity' => $identity,
            ];

        } catch (\Throwable $e) {
            Log::error('[MikroTikCore] Error testing API connection', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => '❌ Error API: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Test SSH connection to MikroTik CORE
     * @param int|null $timeout Optional timeout override (default uses class timeout)
     */
    public function testSshConnection(?int $timeout = null): array
    {
        try {
            $ssh = $this->connectSsh($timeout);

            if (!$ssh) {
                return [
                    'success' => false,
                    'message' => '❌ No se pudo establecer conexión SSH',
                ];
            }

            // Execute simple command to verify
            $output = $ssh->exec('/system identity print');
            $ssh->disconnect();

            return [
                'success' => true,
                'message' => '✅ Conexión SSH al MikroTik CORE exitosa',
                'identity' => trim($output),
            ];

        } catch (\Throwable $e) {
            Log::error('[MikroTikCore] Error testing SSH connection', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => '❌ Error SSH: ' . $e->getMessage(),
            ];
        }
    }

    // ==================== PPP ACTIVE CONNECTIONS ====================

    /**
     * Get PPP active connections (VPN status)
     * Uses API first, SSH as fallback
     */
    public function getPppActive(): array
    {
        // Try API first
        $apiResult = $this->getPppActiveViaApi();
        if ($apiResult['success']) {
            return $apiResult;
        }

        Log::info('[MikroTikCore] API failed for getPppActive, trying SSH', ['api_error' => $apiResult['message'] ?? 'unknown']);

        // Fallback to SSH
        return $this->getPppActiveViaSsh();
    }

    /**
     * Get PPP active connections via API
     */
    private function getPppActiveViaApi(): array
    {
        try {
            $socket = @fsockopen($this->apiHost, $this->apiPort, $errno, $errstr, 10);

            if (!$socket) {
                return ['success' => false, 'message' => "API connection failed: $errstr"];
            }

            stream_set_timeout($socket, $this->timeout);

            if (!$this->apiLogin($socket, $this->apiUser, $this->apiPass)) {
                fclose($socket);
                return ['success' => false, 'message' => 'API authentication failed'];
            }

            $this->apiSendCommand($socket, '/ppp/active/print');
            $records = $this->apiReadAllRecords($socket);

            fclose($socket);

            $connections = [];
            foreach ($records as $record) {
                $connections[] = [
                    'name' => $record['name'] ?? '',
                    'service' => $record['service'] ?? 'l2tp',
                    'caller_id' => $record['caller-id'] ?? '',
                    'address' => $record['address'] ?? '',
                    'uptime' => $record['uptime'] ?? '',
                ];
            }

            return [
                'success' => true,
                'method' => 'API',
                'connections' => $connections,
            ];

        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get PPP active connections via SSH
     */
    private function getPppActiveViaSsh(): array
    {
        $result = $this->execute('/ppp active print');

        if (!$result['success']) {
            return $result;
        }

        // Parse output to extract connections
        $lines = explode("\n", $result['output']);
        $connections = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, 'Flags') || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^\d+\s+(\S+)\s+l2tp\s+(\S+)\s+(\S+)\s+(\S+)/', $line, $matches)) {
                $connections[] = [
                    'name' => $matches[1],
                    'service' => 'l2tp',
                    'caller_id' => $matches[2],
                    'address' => $matches[3],
                    'uptime' => $matches[4],
                ];
            }
        }

        return [
            'success' => true,
            'method' => 'SSH',
            'connections' => $connections,
            'raw' => $result['output'],
        ];
    }

    // ==================== VPN CONNECTION CHECK ====================

    /**
     * Check if specific VPN user is connected
     * Uses API first, SSH as fallback
     */
    public function isVpnConnected(string $vpnUsername): array
    {
        $result = $this->getPppActive();

        if (!$result['success']) {
            return [
                'success' => false,
                'connected' => false,
                'message' => $result['message'] ?? 'Error al consultar conexiones',
            ];
        }

        foreach ($result['connections'] as $conn) {
            if ($conn['name'] === $vpnUsername) {
                return [
                    'success' => true,
                    'connected' => true,
                    'message' => '✅ VPN ACTIVA',
                    'method' => $result['method'] ?? 'unknown',
                    'assigned_ip' => $conn['address'] ?? null,
                    'uptime' => $conn['uptime'] ?? null,
                ];
            }
        }

        return [
            'success' => true,
            'connected' => false,
            'message' => '❌ VPN no conectada',
            'method' => $result['method'] ?? 'unknown',
        ];
    }

    // ==================== PPP SECRET MANAGEMENT ====================

    /**
     * Create or Update PPP secret
     * Uses API ONLY (SSH fallback disabled for production - causes timeouts)
     */
    public function ensurePppSecret(string $username, string $password, string $service = 'l2tp', string $profile = 'default-encryption'): array
    {
        // Use API only - SSH is blocked by Cloudflare in production and causes timeouts
        $apiResult = $this->ensurePppSecretViaApi($username, $password, $service, $profile);

        if (!$apiResult['success']) {
            Log::warning('[MikroTikCore] API failed for ensurePppSecret (SSH fallback disabled)', [
                'api_error' => $apiResult['message'] ?? 'unknown',
                'username' => $username,
            ]);
        }

        return $apiResult;
    }

    /**
     * Create or Update PPP secret via API
     */
    private function ensurePppSecretViaApi(string $username, string $password, string $service, string $profile): array
    {
        try {
            Log::info('[MikroTikCore] Intentando crear/actualizar secret via API', [
                'username' => $username,
                'api_host' => $this->apiHost,
                'api_port' => $this->apiPort,
            ]);

            $socket = @fsockopen($this->apiHost, $this->apiPort, $errno, $errstr, 10);

            if (!$socket) {
                Log::error('[MikroTikCore] API connection failed', [
                    'host' => $this->apiHost,
                    'port' => $this->apiPort,
                    'error' => $errstr,
                    'errno' => $errno,
                ]);
                return ['success' => false, 'message' => "API connection failed: $errstr"];
            }

            // Configure socket for reliable communication
            stream_set_blocking($socket, true);
            stream_set_timeout($socket, $this->timeout);

            if (!$this->apiLogin($socket, $this->apiUser, $this->apiPass)) {
                fclose($socket);
                Log::error('[MikroTikCore] API authentication failed', [
                    'user' => $this->apiUser,
                ]);
                return ['success' => false, 'message' => 'API authentication failed'];
            }

            Log::info('[MikroTikCore] API login exitoso, buscando secret existente');

            // Check if secret exists
            $this->apiSendCommand($socket, '/ppp/secret/print', [
                '?name=' . $username,
            ]);
            $existing = $this->apiReadAllRecords($socket);

            Log::info('[MikroTikCore] Búsqueda de secret completada', [
                'username' => $username,
                'found' => !empty($existing),
                'count' => count($existing),
            ]);

            $action = 'unknown';
            $apiError = null;

            if (!empty($existing)) {
                // Update existing
                $secretId = $existing[0]['.id'] ?? null;
                if ($secretId) {
                    Log::info('[MikroTikCore] Actualizando secret existente', ['id' => $secretId]);
                    $this->apiSendCommand($socket, '/ppp/secret/set', [
                        '=.id=' . $secretId,
                        '=password=' . $password,
                        '=service=' . $service,
                        '=profile=' . $profile,
                    ]);
                    $apiError = $this->apiReadUntilDoneWithError($socket);
                    $action = 'updated';
                } else {
                    Log::warning('[MikroTikCore] Secret encontrado pero sin .id', ['existing' => $existing]);
                }
            } else {
                // Create new
                Log::info('[MikroTikCore] Creando nuevo secret', ['username' => $username]);
                $this->apiSendCommand($socket, '/ppp/secret/add', [
                    '=name=' . $username,
                    '=password=' . $password,
                    '=service=' . $service,
                    '=profile=' . $profile,
                    '=comment=ISPWatch Auto',
                ]);
                $apiError = $this->apiReadUntilDoneWithError($socket);
                $action = 'created';
            }

            // Verificar si hubo error en la respuesta de la API
            if ($apiError) {
                fclose($socket);
                Log::error('[MikroTikCore] Error de API MikroTik al gestionar secret', [
                    'username' => $username,
                    'action' => $action,
                    'error' => $apiError,
                ]);
                return [
                    'success' => false,
                    'message' => "API error: $apiError",
                    'action' => $action,
                ];
            }

            // Verificar que el secret se creó correctamente
            $this->apiSendCommand($socket, '/ppp/secret/print', [
                '?name=' . $username,
            ]);
            $verification = $this->apiReadAllRecords($socket);

            fclose($socket);

            if (empty($verification)) {
                Log::error('[MikroTikCore] Secret no encontrado después de creación', [
                    'username' => $username,
                    'action' => $action,
                ]);
                return [
                    'success' => false,
                    'message' => 'Secret no encontrado después de creación/actualización',
                    'action' => $action,
                ];
            }

            Log::info('[MikroTikCore] PPP secret gestionado exitosamente via API', [
                'username' => $username,
                'action' => $action,
                'verified' => true,
            ]);

            return [
                'success' => true,
                'method' => 'API',
                'action' => $action,
                'message' => "Secret {$action} successfully via API",
                'verified' => true,
            ];

        } catch (\Throwable $e) {
            Log::error('[MikroTikCore] Exception managing PPP secret via API', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Create or Update PPP secret via SSH
     */
    private function ensurePppSecretViaSsh(string $username, string $password, string $service, string $profile): array
    {
        // 1. Check if exists
        $current = $this->getPppSecret($username);

        if (!$current['success']) {
            return $current; // Error state
        }

        if ($current['found']) {
            // Update if needed
            $cmd = sprintf(
                '/ppp secret set [find name=%s] password=%s service=%s profile=%s',
                escapeshellarg($username),
                escapeshellarg($password),
                escapeshellarg($service),
                escapeshellarg($profile)
            );
            $action = "updated";
        } else {
            // Create
            $cmd = sprintf(
                '/ppp secret add name=%s password=%s service=%s profile=%s comment="ISPWatch Auto"',
                escapeshellarg($username),
                escapeshellarg($password),
                escapeshellarg($service),
                escapeshellarg($profile)
            );
            $action = "created";
        }

        $result = $this->execute($cmd);

        if ($result['success']) {
            $result['method'] = 'SSH';
            $result['action'] = $action;
            $result['message'] = "Secret $action successfully via SSH";
        }

        return $result;
    }

    /**
     * Get specific PPP secret details (SSH only)
     */
    public function getPppSecret(string $username): array
    {
        $cmd = sprintf('/ppp secret print detail where name=%s', escapeshellarg($username));
        $result = $this->execute($cmd);

        if (!$result['success']) {
            return $result;
        }

        $output = $result['output'];

        if (trim($output) === '' || !str_contains($output, 'name=')) {
            return [
                'success' => true,
                'found' => false,
                'data' => null,
                'raw_debug' => $output
            ];
        }

        return [
            'success' => true,
            'found' => true,
            'raw' => $output
        ];
    }

    // ==================== SSH COMMAND EXECUTION ====================

    /**
     * Execute command on MikroTik via SSH
     */
    public function execute(string $command): array
    {
        try {
            $ssh = $this->connectSsh();

            if (!$ssh) {
                return [
                    'success' => false,
                    'message' => 'No se pudo conectar al MikroTik via SSH',
                    'output' => null,
                ];
            }

            $output = $ssh->exec($command);
            $ssh->disconnect();

            return [
                'success' => true,
                'output' => $output,
            ];

        } catch (\Throwable $e) {
            Log::error('[MikroTikCore] Error executing SSH command', [
                'command' => $command,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'output' => null,
            ];
        }
    }

    // ==================== SUSPENDED IP MANAGEMENT ====================

    /**
     * Add IP to suspended address-list
     */
    public function addSuspendedIp(string $ip, string $comment = ''): array
    {
        $cmd = sprintf(
            '/ip firewall address-list add list=ISPWATCH_SUSPENDIDOS address=%s comment="%s"',
            escapeshellarg($ip),
            addslashes($comment)
        );

        return $this->execute($cmd);
    }

    /**
     * Remove IP from suspended address-list
     */
    public function removeSuspendedIp(string $ip): array
    {
        // First find the entry
        $findCmd = sprintf(
            '/ip firewall address-list print where list=ISPWATCH_SUSPENDIDOS address=%s',
            escapeshellarg($ip)
        );

        $result = $this->execute($findCmd);

        if (!$result['success']) {
            return $result;
        }

        // Remove by address
        $removeCmd = sprintf(
            '/ip firewall address-list remove [find list=ISPWATCH_SUSPENDIDOS address=%s]',
            escapeshellarg($ip)
        );

        return $this->execute($removeCmd);
    }

    // ==================== CLIENT ROUTER VIA CORE ====================

    /**
     * Get interfaces from a client router via the CORE
     * Uses SSH to CORE, then CORE connects to client router via SSH
     * 
     * @param string $clientIp IP del router cliente (IP VPN asignada)
     * @param string $clientUser Usuario del router cliente
     * @param string $clientPass Password del router cliente
     * @param int $clientPort Puerto API del router cliente (no usado, solo para compatibilidad)
     */
    public function getRouterInterfaces(string $clientIp, string $clientUser, string $clientPass, int $clientPort = 8728): array
    {
        try {
            Log::info('[MikroTikCore] Obteniendo interfaces de router cliente via CORE SSH', [
                'client_ip' => $clientIp,
                'client_user' => $clientUser,
            ]);

            $ssh = $this->connectSsh();

            if (!$ssh) {
                return [
                    'success' => false,
                    'message' => 'No se pudo conectar al MikroTik CORE via SSH',
                    'interfaces' => [],
                ];
            }

            // Verificar conectividad con ping primero
            $pingCommand = sprintf('/ping address=%s count=1', $clientIp);
            $pingResult = $ssh->exec($pingCommand);

            Log::debug('[MikroTikCore] Ping result', ['output' => $pingResult]);

            if (str_contains($pingResult, 'timeout') || str_contains($pingResult, '0 received')) {
                $ssh->disconnect();
                return [
                    'success' => false,
                    'message' => 'El router cliente no responde. Verifica que la VPN esté conectada.',
                    'interfaces' => [],
                ];
            }

            // Ejecutar comando SSH desde CORE hacia el router cliente para obtener interfaces
            $escapedPass = addslashes($clientPass);
            $sshCmd = sprintf(
                '/system ssh address=%s user=%s password="%s" command="/interface print terse"',
                $clientIp,
                $clientUser,
                $escapedPass
            );

            Log::debug('[MikroTikCore] Ejecutando SSH al cliente para obtener interfaces');
            $interfaceOutput = $ssh->exec($sshCmd);

            Log::debug('[MikroTikCore] Interface output', ['output' => $interfaceOutput]);

            $ssh->disconnect();

            // Parsear la salida de /interface print terse
            $interfaces = [];
            $lines = explode("\n", $interfaceOutput);

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line))
                    continue;

                // Extraer name usando regex
                if (preg_match('/name="?([^"\s]+)"?/', $line, $nameMatch)) {
                    $name = $nameMatch[1];

                    // Extraer type
                    $type = 'unknown';
                    if (preg_match('/type="?([^"\s]+)"?/', $line, $typeMatch)) {
                        $type = $typeMatch[1];
                    }

                    // Verificar si está running (R flag al inicio)
                    $running = str_contains($line, ' R ') || preg_match('/^\d+\s+R\s/', $line);

                    // Verificar si está disabled (X flag)
                    $disabled = str_contains($line, ' X ') || preg_match('/^\d+\s+X/', $line);

                    // Extraer comment si existe
                    $comment = '';
                    if (preg_match('/comment="([^"]*)"/', $line, $commentMatch)) {
                        $comment = $commentMatch[1];
                    }

                    // Filtrar interfaces VPN/virtuales
                    $excludedTypes = ['l2tp', 'pptp', 'pppoe', 'ovpn', 'sstp', 'gre', 'ipip', 'eoip'];
                    $shouldExclude = false;
                    foreach ($excludedTypes as $excluded) {
                        if (stripos($type, $excluded) !== false || stripos($name, $excluded) !== false) {
                            $shouldExclude = true;
                            break;
                        }
                    }

                    // Excluir la interfaz VPN del CORE
                    if (stripos($name, 'ISPWatch-VPN') !== false) {
                        $shouldExclude = true;
                    }

                    if (!$shouldExclude) {
                        $interfaces[] = [
                            'name' => $name,
                            'type' => $type,
                            'running' => $running,
                            'disabled' => $disabled,
                            'comment' => $comment,
                        ];
                    }
                }
            }

            // Si no pudimos parsear interfaces, puede ser un error de autenticación
            if (empty($interfaces) && (str_contains($interfaceOutput, 'error') || str_contains($interfaceOutput, 'bad'))) {
                return [
                    'success' => false,
                    'message' => 'Error de autenticación SSH al router cliente. Verifica usuario y contraseña.',
                    'interfaces' => [],
                    'raw_output' => $interfaceOutput,
                ];
            }

            return [
                'success' => true,
                'message' => 'Interfaces obtenidas correctamente',
                'interfaces' => $interfaces,
            ];

        } catch (\Throwable $e) {
            Log::error('[MikroTikCore] Error obteniendo interfaces via CORE', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'interfaces' => [],
            ];
        }
    }

    /**
     * Apply block rules to a client router
     * Strategy:
     * 1. Try direct API connection (works when local machine has VPN access)
     * 2. If fails, use SSH tunneling through CORE (for production servers)
     */
    public function applyBlockRulesViaCore(string $clientIp, string $clientUser, string $clientPass, string $wanInterface, string $portalIp, int $apiPort = 8728): array
    {
        Log::info('[MikroTikCore] Aplicando reglas de bloqueo', [
            'client_ip' => $clientIp,
            'api_port' => $apiPort,
            'portal_ip' => $portalIp,
        ]);

        // STRATEGY 1: Try direct API connection (works in local with VPN access)
        $socket = @fsockopen($clientIp, $apiPort, $errno, $errstr, 5);

        if ($socket) {
            Log::info('[MikroTikCore] Conexión API directa exitosa, usando método directo');
            return $this->applyBlockRulesDirectApi($socket, $clientUser, $clientPass, $wanInterface, $portalIp);
        }

        Log::info('[MikroTikCore] API directa no disponible, intentando via SSH tunneling al CORE', [
            'direct_error' => $errstr,
        ]);

        // STRATEGY 2: Use SSH tunneling through CORE (for production)
        return $this->applyBlockRulesViaSshTunnel($clientIp, $clientUser, $clientPass, $wanInterface, $portalIp, $apiPort);
    }

    /**
     * Apply block rules using direct API connection
     */
    private function applyBlockRulesDirectApi($socket, string $clientUser, string $clientPass, string $wanInterface, string $portalIp): array
    {
        try {
            stream_set_timeout($socket, 30);

            // Login using API protocol
            if (!$this->apiLogin($socket, $clientUser, $clientPass)) {
                fclose($socket);
                return ['success' => false, 'message' => 'Error de autenticación en el router cliente'];
            }

            Log::info('[MikroTikCore] Login API exitoso');

            // Apply rules
            $this->applyFirewallRulesViaApi($socket, $wanInterface, $portalIp);

            fclose($socket);

            return [
                'success' => true,
                'method' => 'DIRECT_API',
                'message' => 'Reglas de bloqueo aplicadas correctamente via API directa',
                'rules_applied' => [
                    'address_list' => 'ISPWATCH_SUSPENDIDOS',
                    'portal_ip' => $portalIp,
                    'wan_interface' => $wanInterface,
                    'nat_rules' => ['HTTP:80', 'HTTPS:443'],
                    'filter_rule' => 'DROP forward to WAN',
                ],
            ];

        } catch (\Throwable $e) {
            @fclose($socket);
            Log::error('[MikroTikCore] Error en API directa', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Apply block rules using SSH connection through CORE
     */
    private function applyBlockRulesViaSshTunnel(string $clientIp, string $clientUser, string $clientPass, string $wanInterface, string $portalIp, int $apiPort): array
    {
        try {
            // Connect to CORE via SSH
            $ssh = $this->connectSsh();
            if (!$ssh) {
                return ['success' => false, 'message' => 'No se pudo conectar al MikroTik CORE'];
            }

            Log::info('[MikroTikCore] Conectado al CORE, ejecutando comandos via ssh-exec');

            // Use ssh-exec method directly (most reliable)
            $result = $this->applyBlockRulesViaSshCommands($ssh, $clientIp, $clientUser, $clientPass, $wanInterface, $portalIp);
            $ssh->disconnect();
            return $result;

        } catch (\Throwable $e) {
            Log::error('[MikroTikCore] Error en SSH', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Apply block rules by executing SSH commands on CORE to reach client
     */
    private function applyBlockRulesViaSshCommands(SSH2 $ssh, string $clientIp, string $clientUser, string $clientPass, string $wanInterface, string $portalIp): array
    {
        Log::info('[MikroTikCore] Usando método SSH commands via CORE');

        try {
            // Escape password for shell
            $safePass = addslashes($clientPass);

            // Build commands to execute via ssh-exec from CORE to CLIENT
            $commands = [
                // 1. Create address-list
                "/ip firewall address-list add list=ISPWATCH_SUSPENDIDOS address=0.0.0.0 comment=\"Control ISPWatch\"",
                // 2. NAT HTTP
                "/ip firewall nat add chain=dstnat src-address-list=ISPWATCH_SUSPENDIDOS protocol=tcp dst-port=80 action=dst-nat to-addresses={$portalIp} to-ports=80 comment=\"ISPWatch Portal HTTP\"",
                // 3. NAT HTTPS
                "/ip firewall nat add chain=dstnat src-address-list=ISPWATCH_SUSPENDIDOS protocol=tcp dst-port=443 action=dst-nat to-addresses={$portalIp} to-ports=443 comment=\"ISPWatch Portal HTTPS\"",
                // 4. Filter DROP
                "/ip firewall filter add chain=forward src-address-list=ISPWATCH_SUSPENDIDOS out-interface={$wanInterface} action=drop comment=\"ISPWatch - Bloqueo general\"",
            ];

            $results = [];
            $errors = [];

            foreach ($commands as $index => $cmd) {
                Log::debug("[MikroTikCore] Ejecutando comando " . ($index + 1));

                // Execute via CORE's ssh-exec to client
                $sshExecCmd = sprintf(
                    '/system ssh-exec address=%s user=%s password="%s" command="%s"',
                    $clientIp,
                    $clientUser,
                    $safePass,
                    addslashes($cmd)
                );

                $output = $ssh->exec($sshExecCmd);
                $results[] = trim($output);

                // Check for errors
                if (stripos($output, 'error') !== false || stripos($output, 'failure') !== false) {
                    $errors[] = "Comando " . ($index + 1) . ": " . trim($output);
                }

                // Small delay between commands
                usleep(500000); // 0.5 seconds
            }

            if (!empty($errors)) {
                Log::warning('[MikroTikCore] Algunos comandos fallaron', ['errors' => $errors]);
            }

            return [
                'success' => true,
                'method' => 'SSH_COMMANDS',
                'message' => 'Reglas de bloqueo aplicadas via comandos SSH',
                'rules_applied' => [
                    'address_list' => 'ISPWATCH_SUSPENDIDOS',
                    'portal_ip' => $portalIp,
                    'wan_interface' => $wanInterface,
                    'nat_rules' => ['HTTP:80', 'HTTPS:443'],
                    'filter_rule' => 'DROP forward to WAN',
                ],
                'warnings' => $errors,
            ];

        } catch (\Throwable $e) {
            Log::error('[MikroTikCore] Error en SSH commands', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Apply firewall rules using API protocol
     */
    private function applyFirewallRulesViaApi($socket, string $wanInterface, string $portalIp): void
    {
        // 1. Create address-list (placeholder)
        Log::info('[MikroTikCore] Creando address-list ISPWATCH_SUSPENDIDOS');
        $this->apiSendCommand($socket, '/ip/firewall/address-list/add', [
            '=list=ISPWATCH_SUSPENDIDOS',
            '=address=0.0.0.0',
            '=comment=Control ISPWatch',
        ]);
        $this->apiReadUntilDone($socket);

        // 2. NAT Rule HTTP
        Log::info('[MikroTikCore] Creando regla NAT HTTP');
        $this->apiSendCommand($socket, '/ip/firewall/nat/add', [
            '=chain=dstnat',
            '=src-address-list=ISPWATCH_SUSPENDIDOS',
            '=protocol=tcp',
            '=dst-port=80',
            '=action=dst-nat',
            '=to-addresses=' . $portalIp,
            '=to-ports=80',
            '=comment=ISPWatch Portal HTTP',
        ]);
        $this->apiReadUntilDone($socket);

        // 3. NAT Rule HTTPS
        Log::info('[MikroTikCore] Creando regla NAT HTTPS');
        $this->apiSendCommand($socket, '/ip/firewall/nat/add', [
            '=chain=dstnat',
            '=src-address-list=ISPWATCH_SUSPENDIDOS',
            '=protocol=tcp',
            '=dst-port=443',
            '=action=dst-nat',
            '=to-addresses=' . $portalIp,
            '=to-ports=443',
            '=comment=ISPWatch Portal HTTPS',
        ]);
        $this->apiReadUntilDone($socket);

        // 4. Filter DROP rule
        Log::info('[MikroTikCore] Creando regla Filter DROP');
        $this->apiSendCommand($socket, '/ip/firewall/filter/add', [
            '=chain=forward',
            '=src-address-list=ISPWATCH_SUSPENDIDOS',
            '=out-interface=' . $wanInterface,
            '=action=drop',
            '=comment=ISPWatch - Bloqueo general',
        ]);
        $this->apiReadUntilDone($socket);

        Log::info('[MikroTikCore] Reglas de firewall aplicadas');
    }

    /**
     * Get firewall rules from a client router via SSH from CORE
     */
    public function getFirewallRulesViaCore(string $clientIp, string $clientUser, string $clientPass): array
    {
        try {
            Log::info('[MikroTikCore] Verificando reglas de firewall en cliente via CORE (SSH)', [
                'client_ip' => $clientIp,
            ]);

            $ssh = $this->connectSsh();

            if (!$ssh) {
                return [
                    'success' => false,
                    'message' => 'No se pudo conectar al MikroTik CORE',
                ];
            }

            // Verificar conectividad con ping
            $pingCommand = sprintf('/ping address=%s count=1', $clientIp);
            $pingResult = $ssh->exec($pingCommand);

            if (str_contains($pingResult, 'timeout') || str_contains($pingResult, '0 received')) {
                $ssh->disconnect();
                return [
                    'success' => false,
                    'message' => 'El router cliente no responde. Verifica que la VPN esté activa.',
                ];
            }

            $escapedPass = addslashes($clientPass);

            // Helper para ejecutar comando SSH al cliente
            $execOnClient = function (string $command) use ($ssh, $clientIp, $clientUser, $escapedPass): string {
                $sshCmd = sprintf(
                    '/system ssh address=%s user=%s password="%s" command="%s"',
                    $clientIp,
                    $clientUser,
                    $escapedPass,
                    addslashes($command)
                );
                return $ssh->exec($sshCmd);
            };

            // Obtener reglas
            $addressList = $execOnClient('/ip firewall address-list print where list=ISPWATCH_SUSPENDIDOS');
            $natRules = $execOnClient('/ip firewall nat print where comment~"ISPWatch"');
            $filterRules = $execOnClient('/ip firewall filter print where comment~"ISPWatch"');

            $ssh->disconnect();

            return [
                'success' => true,
                'message' => 'Reglas obtenidas correctamente',
                'address_list' => [
                    'found' => str_contains($addressList, 'ISPWATCH_SUSPENDIDOS'),
                    'raw' => trim($addressList),
                ],
                'nat_rules' => [
                    'found' => str_contains($natRules, 'ISPWatch'),
                    'raw' => trim($natRules),
                ],
                'filter_rules' => [
                    'found' => str_contains($filterRules, 'ISPWatch'),
                    'raw' => trim($filterRules),
                ],
            ];

        } catch (\Throwable $e) {
            Log::error('[MikroTikCore] Error verificando reglas en cliente', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Create PPP secret for new router (Legacy/Simple wrapper)
     */
    public function createPppSecret(string $username, string $password): array
    {
        return $this->ensurePppSecret($username, $password);
    }

    /**
     * Test command execution on client via CORE
     */
    public function testExecuteOnClient(string $clientIp, string $clientUser, string $clientPass, string $command): array
    {
        try {
            Log::info('[MikroTikCore] PRUEBA - Ejecutando comando en cliente via CORE', [
                'client_ip' => $clientIp,
                'command' => $command,
            ]);

            // 1. Conectar al CORE
            $ssh = $this->connectSsh();

            if (!$ssh) {
                return [
                    'success' => false,
                    'message' => 'No se pudo conectar al MikroTik CORE',
                ];
            }

            // 2. Verificar conectividad con ping
            $pingCommand = sprintf('/ping address=%s count=2', $clientIp);
            $pingResult = $ssh->exec($pingCommand);

            Log::info('[MikroTikCore] PRUEBA - Ping result', ['output' => $pingResult]);

            if (str_contains($pingResult, 'timeout') || str_contains($pingResult, '0 received')) {
                $ssh->disconnect();
                return [
                    'success' => false,
                    'message' => "El router cliente $clientIp no responde al ping. Verifica que la VPN esté activa.",
                    'ping_output' => $pingResult,
                ];
            }

            // 3. Ejecutar comando SSH al cliente
            $escapedPass = str_replace("'", "\\'", $clientPass);
            $sshCmd = sprintf(
                '/system ssh address=%s user=%s password=\'%s\' command="%s"',
                $clientIp,
                $clientUser,
                $escapedPass,
                addslashes($command)
            );

            Log::info('[MikroTikCore] PRUEBA - Ejecutando SSH command');
            $output = $ssh->exec($sshCmd);

            Log::info('[MikroTikCore] PRUEBA - Resultado', ['output' => $output]);

            $ssh->disconnect();

            return [
                'success' => true,
                'message' => 'Comando ejecutado correctamente',
                'command' => $command,
                'output' => $output,
                'client_ip' => $clientIp,
            ];

        } catch (\Throwable $e) {
            Log::error('[MikroTikCore] PRUEBA - Error ejecutando comando', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }

    // ==================== SSH CONNECTION ====================

    /**
     * Establish SSH connection
     * @param int|null $timeout Optional timeout override (default uses class timeout of 30s)
     */
    public function connectSsh(?int $timeout = null): ?SSH2
    {
        try {
            $effectiveTimeout = $timeout ?? $this->timeout;
            $ssh = new SSH2($this->sshHost, $this->sshPort);
            $ssh->setTimeout($effectiveTimeout);

            // Try key-based authentication first
            if ($this->privateKeyPath && file_exists($this->privateKeyPath)) {
                try {
                    $keyContent = file_get_contents($this->privateKeyPath);

                    if ($this->keyPassphrase) {
                        $key = PublicKeyLoader::load($keyContent, $this->keyPassphrase);
                    } else {
                        $key = PublicKeyLoader::load($keyContent);
                    }

                    try {
                        if ($ssh->login($this->sshUsername, $key)) {
                            Log::info('[MikroTikCore] Conectado con clave SSH');
                            return $ssh;
                        }
                    } catch (\TypeError $e) {
                        Log::warning('[MikroTikCore] TypeError en login con objeto key, intentando string conversion', [
                            'error' => $e->getMessage(),
                            'key_class' => get_class($key)
                        ]);

                        try {
                            if ($ssh->login($this->sshUsername, (string) $key)) {
                                Log::info('[MikroTikCore] Conectado con clave SSH (convertida a string)');
                                return $ssh;
                            }
                        } catch (\Throwable $ex) {
                            Log::error('[MikroTikCore] Falló fallback string login', ['error' => $ex->getMessage()]);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('[MikroTikCore] Error con clave SSH, intentando password', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Fallback to password authentication
            if ($this->sshPassword) {
                if ($ssh->login($this->sshUsername, $this->sshPassword)) {
                    Log::info('[MikroTikCore] Conectado con password');
                    return $ssh;
                }
            }

            Log::error('[MikroTikCore] Falló autenticación SSH', [
                'host' => $this->sshHost,
                'user' => $this->sshUsername,
                'hasKey' => file_exists($this->privateKeyPath ?? ''),
                'hasPassword' => !empty($this->sshPassword),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error('[MikroTikCore] SSH connection exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Legacy alias for connectSsh
     */
    public function connect(): ?SSH2
    {
        return $this->connectSsh();
    }

    // ==================== API HELPER METHODS ====================

    /**
     * Login to router via API
     */
    private function apiLogin($socket, string $user, string $pass): bool
    {
        $this->apiSendCommand($socket, '/login', [
            '=name=' . $user,
            '=password=' . $pass,
        ]);

        $response = [];
        $challenge = null;

        while (true) {
            $word = $this->apiReadWord($socket);
            if ($word === '')
                break;

            $response[] = $word;

            if (str_starts_with($word, '=ret=')) {
                $challenge = substr($word, 5);
            }

            if ($word === '!trap') {
                Log::error('[MikroTikCore] API Login trap');
                return false;
            }
        }

        // Si hay challenge, hacer login MD5
        if ($challenge) {
            $challengeBin = hex2bin($challenge);
            $hash = md5(chr(0) . $pass . $challengeBin);

            $this->apiSendCommand($socket, '/login', [
                '=name=' . $user,
                '=response=00' . $hash,
            ]);

            while (true) {
                $word = $this->apiReadWord($socket);
                if ($word === '')
                    break;
                if ($word === '!trap')
                    return false;
            }
        }

        return true;
    }

    /**
     * Send API command
     */
    private function apiSendCommand($socket, string $command, array $params = []): void
    {
        $this->apiWriteWord($socket, $command);
        foreach ($params as $param) {
            $this->apiWriteWord($socket, $param);
        }
        $result = @fwrite($socket, chr(0));
        if ($result === false) {
            throw new \RuntimeException('Socket write failed: Broken pipe or connection closed');
        }
    }

    /**
     * Read all records from API response
     */
    private function apiReadAllRecords($socket): array
    {
        $records = [];
        $current = [];
        $wordCount = 0;
        $maxWords = 2000;

        while ($wordCount < $maxWords) {
            $word = $this->apiReadWord($socket);
            $wordCount++;

            if ($word === '!re') {
                if (!empty($current)) {
                    $records[] = $current;
                }
                $current = [];
                continue;
            }

            if ($word === '!done' || $word === '!trap') {
                if (!empty($current)) {
                    $records[] = $current;
                }
                break;
            }

            if ($word === '') {
                continue;
            }

            if (str_starts_with($word, '=')) {
                $parts = explode('=', substr($word, 1), 2);
                if (count($parts) === 2) {
                    $current[$parts[0]] = $parts[1];
                }
            }
        }

        return $records;
    }

    /**
     * Read until !done
     */
    private function apiReadUntilDone($socket): void
    {
        $count = 0;
        while ($count < 100) {
            $word = $this->apiReadWord($socket);
            $count++;
            if ($word === '!done' || $word === '!trap')
                break;
        }
    }

    /**
     * Read until !done and return error message if !trap was received
     * @return string|null Error message if trap, null if success
     */
    private function apiReadUntilDoneWithError($socket): ?string
    {
        $count = 0;
        $trapMessage = null;
        $gotTrap = false;

        while ($count < 100) {
            $word = $this->apiReadWord($socket);
            $count++;

            if ($word === '!trap') {
                $gotTrap = true;
                continue;
            }

            if ($word === '!done') {
                break;
            }

            // Si estamos después de un !trap, capturar el mensaje de error
            if ($gotTrap && str_starts_with($word, '=message=')) {
                $trapMessage = substr($word, 9);
            }

            if ($word === '') {
                if ($gotTrap)
                    break;
                continue;
            }
        }

        return $trapMessage;
    }

    /**
     * Write API word
     */
    private function apiWriteWord($socket, string $word): void
    {
        $len = strlen($word);
        $result = false;

        if ($len < 0x80) {
            $result = @fwrite($socket, chr($len));
        } elseif ($len < 0x4000) {
            $len |= 0x8000;
            $result = @fwrite($socket, chr(($len >> 8) & 0xFF));
            if ($result !== false) {
                $result = @fwrite($socket, chr($len & 0xFF));
            }
        } else {
            $result = @fwrite($socket, chr(($len >> 16) & 0xFF));
            if ($result !== false) {
                $result = @fwrite($socket, chr(($len >> 8) & 0xFF));
            }
            if ($result !== false) {
                $result = @fwrite($socket, chr($len & 0xFF));
            }
        }

        if ($result === false) {
            throw new \RuntimeException('Socket write failed: Broken pipe or connection closed');
        }

        $result = @fwrite($socket, $word);
        if ($result === false) {
            throw new \RuntimeException('Socket write failed: Broken pipe or connection closed');
        }
    }

    /**
     * Read API word
     */
    private function apiReadWord($socket): string
    {
        $byte = @fread($socket, 1);
        if ($byte === '' || $byte === false)
            return '';

        $len = ord($byte);
        if ($len === 0)
            return '';

        if (($len & 0x80) == 0x00) {
            // 1 byte
        } elseif (($len & 0xC0) == 0x80) {
            $b2 = ord(@fread($socket, 1));
            $len = (($len & 0x3F) << 8) + $b2;
        } elseif (($len & 0xE0) == 0xC0) {
            $b2 = ord(@fread($socket, 1));
            $b3 = ord(@fread($socket, 1));
            $len = (($len & 0x1F) << 16) + ($b2 << 8) + $b3;
        }

        if ($len <= 0)
            return '';

        $data = '';
        $remaining = $len;
        while ($remaining > 0) {
            $chunk = @fread($socket, $remaining);
            if ($chunk === '' || $chunk === false)
                break;
            $data .= $chunk;
            $remaining = $len - strlen($data);
        }

        return $data;
    }

    // ==================== CONFIGURATION ====================

    /**
     * Get current configuration (sanitized)
     */
    private function getConfig(): array
    {
        return [
            'ssh' => [
                'host' => $this->sshHost,
                'port' => $this->sshPort,
                'username' => $this->sshUsername,
                'auth_method' => file_exists($this->privateKeyPath ?? '') ? 'ssh_key' : 'password',
                'key_exists' => file_exists($this->privateKeyPath ?? ''),
            ],
            'api' => [
                'host' => $this->apiHost,
                'port' => $this->apiPort,
                'username' => $this->apiUser,
            ],
        ];
    }
}
