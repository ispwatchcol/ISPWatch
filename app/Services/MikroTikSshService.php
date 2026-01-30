<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * Servicio para conexión SSH al MikroTik CORE
 * Usado cuando el API no está habilitado y solo SSH está disponible
 */
class MikroTikSshService
{
    private string $host;
    private int $port;
    private string $username;
    private ?string $password;
    private ?string $privateKeyPath;
    private ?string $keyPassphrase;
    private int $timeout = 30;

    public function __construct()
    {
        $this->host = env('MIKROTIK_CORE_SSH_HOST', env('MIKROTIK_CORE_API_HOST', '190.14.255.107'));
        $this->port = (int) env('MIKROTIK_CORE_SSH_PORT', 22);
        $this->username = env('MIKROTIK_CORE_SSH_USER', 'admin');
        $this->password = env('MIKROTIK_CORE_SSH_PASS', null);
        $this->privateKeyPath = env('MIKROTIK_CORE_SSH_KEY_PATH', storage_path('keys/mikrotik_core_id_ed25519'));
        $this->keyPassphrase = env('MIKROTIK_CORE_SSH_KEY_PASSPHRASE', null);
    }

    /**
     * Test SSH connection to MikroTik CORE
     */
    public function testConnection(): array
    {
        try {
            $ssh = $this->connect();

            if (!$ssh) {
                return [
                    'success' => false,
                    'message' => '❌ No se pudo establecer conexión SSH',
                    'config' => $this->getConfig(),
                ];
            }

            // Execute simple command to verify
            $output = $ssh->exec('/system identity print');
            $ssh->disconnect();

            return [
                'success' => true,
                'message' => '✅ Conexión SSH al MikroTik CORE exitosa',
                'identity' => trim($output),
                'config' => $this->getConfig(),
            ];

        } catch (\Throwable $e) {
            Log::error('[MikroTikSSH] Error de conexión', [
                'error' => $e->getMessage(),
                'host' => $this->host,
            ]);

            return [
                'success' => false,
                'message' => '❌ Error de conexión SSH: ' . $e->getMessage(),
                'config' => $this->getConfig(),
            ];
        }
    }

    /**
     * Execute command on MikroTik
     */
    public function execute(string $command): array
    {
        try {
            $ssh = $this->connect();

            if (!$ssh) {
                return [
                    'success' => false,
                    'message' => 'No se pudo conectar al MikroTik',
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
            Log::error('[MikroTikSSH] Error ejecutando comando', [
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

    /**
     * Get PPP active connections (VPN status)
     */
    public function getPppActive(): array
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

            // Parse MikroTik table format
            // Output example:
            // 0 ikXcLyXb3M  l2tp     181.225.70.27  10.10.10.254  1m14s   cbc(aes) + hmac(sha1)
            // Regex: Index(digits) Name Service CallerID Address Uptime
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
            'connections' => $connections,
            'raw' => $result['output'],
        ];
    }

    /**
     * Check if specific VPN user is connected
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
                    'assigned_ip' => $conn['address'] ?? null,
                    'uptime' => $conn['uptime'] ?? null,
                ];
            }
        }

        return [
            'success' => true,
            'connected' => false,
            'message' => '❌ VPN no conectada',
        ];
    }

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

    /**
     * Create or Update PPP secret
     */
    public function ensurePppSecret(string $username, string $password, string $service = 'l2tp', string $profile = 'default-encryption'): array
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
            $result['action'] = $action;
            $result['message'] = "Secret $action successfully";
        }

        return $result;
    }

    /**
     * Get specific PPP secret details
     */
    public function getPppSecret(string $username): array
    {
        $cmd = sprintf('/ppp secret print detail where name=%s', escapeshellarg($username));
        $result = $this->execute($cmd);

        if (!$result['success']) {
            return $result;
        }

        $output = $result['output'];

        // Check if output contains "name=" to confirm it's a valid record
        // The print detail command usually outputs: 0   name="foo" ...
        // If it outputs only headers (Flags: ...), it means no record found.
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
            Log::info('[MikroTikSSH] Obteniendo interfaces de router cliente via CORE SSH', [
                'client_ip' => $clientIp,
                'client_user' => $clientUser,
            ]);

            $ssh = $this->connect();

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

            Log::debug('[MikroTikSSH] Ping result', ['output' => $pingResult]);

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

            Log::debug('[MikroTikSSH] Ejecutando SSH al cliente para obtener interfaces');
            $interfaceOutput = $ssh->exec($sshCmd);

            Log::debug('[MikroTikSSH] Interface output', ['output' => $interfaceOutput]);

            $ssh->disconnect();

            // Parsear la salida de /interface print terse
            // Formato: 0 R name="ether1" type="ether" mtu=1500 ...
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
            Log::error('[MikroTikSSH] Error obteniendo interfaces via CORE', [
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
     * 
     * @param string $clientIp IP del router cliente (IP VPN asignada)
     * @param string $clientUser Usuario del router cliente
     * @param string $clientPass Password del router cliente
     * @param string $wanInterface Interfaz WAN del router cliente
     * @param string $portalIp IP del portal de redirección
     * @param int $apiPort Puerto API del router cliente (default 8728)
     */
    public function applyBlockRulesViaCore(string $clientIp, string $clientUser, string $clientPass, string $wanInterface, string $portalIp, int $apiPort = 8728): array
    {
        Log::info('[MikroTikSSH] Aplicando reglas de bloqueo', [
            'client_ip' => $clientIp,
            'api_port' => $apiPort,
            'portal_ip' => $portalIp,
        ]);

        // STRATEGY 1: Try direct API connection (works in local with VPN access)
        $socket = @fsockopen($clientIp, $apiPort, $errno, $errstr, 5); // Short timeout for quick fail

        if ($socket) {
            Log::info('[MikroTikSSH] Conexión API directa exitosa, usando método directo');
            return $this->applyBlockRulesDirectApi($socket, $clientUser, $clientPass, $wanInterface, $portalIp);
        }

        Log::info('[MikroTikSSH] API directa no disponible, intentando via SSH tunneling al CORE', [
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

            Log::info('[MikroTikSSH] Login API exitoso');

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
            Log::error('[MikroTikSSH] Error en API directa', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Apply block rules using SSH tunneling through CORE
     */
    private function applyBlockRulesViaSshTunnel(string $clientIp, string $clientUser, string $clientPass, string $wanInterface, string $portalIp, int $apiPort): array
    {
        try {
            // Connect to CORE via SSH
            $ssh = $this->connect();
            if (!$ssh) {
                return ['success' => false, 'message' => 'No se pudo conectar al MikroTik CORE'];
            }

            Log::info('[MikroTikSSH] Conectado al CORE, creando túnel SSH');

            // Create SSH tunnel to client's API port using phpseclib3
            // This creates a direct-tcpip channel through the SSH connection
            $tunnel = $ssh->openSocketChannel($clientIp, $apiPort);

            if (!$tunnel) {
                Log::warning('[MikroTikSSH] openSocketChannel falló, intentando método alternativo');

                // Fallback: Execute commands via SSH directly on CORE
                $result = $this->applyBlockRulesViaSshCommands($ssh, $clientIp, $clientUser, $clientPass, $wanInterface, $portalIp);
                $ssh->disconnect();
                return $result;
            }

            Log::info('[MikroTikSSH] Túnel SSH creado exitosamente');

            // Use tunnel as socket for API calls
            stream_set_timeout($tunnel, 30);

            if (!$this->apiLogin($tunnel, $clientUser, $clientPass)) {
                @fclose($tunnel);
                $ssh->disconnect();
                return ['success' => false, 'message' => 'Error de autenticación via túnel SSH'];
            }

            // Apply rules through tunnel
            $this->applyFirewallRulesViaApi($tunnel, $wanInterface, $portalIp);

            @fclose($tunnel);
            $ssh->disconnect();

            return [
                'success' => true,
                'method' => 'SSH_TUNNEL',
                'message' => 'Reglas de bloqueo aplicadas correctamente via túnel SSH',
                'rules_applied' => [
                    'address_list' => 'ISPWATCH_SUSPENDIDOS',
                    'portal_ip' => $portalIp,
                    'wan_interface' => $wanInterface,
                    'nat_rules' => ['HTTP:80', 'HTTPS:443'],
                    'filter_rule' => 'DROP forward to WAN',
                ],
            ];

        } catch (\Throwable $e) {
            Log::error('[MikroTikSSH] Error en SSH tunnel', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Apply block rules by executing SSH commands on CORE to reach client
     * This is the fallback method when tunneling is not available
     */
    private function applyBlockRulesViaSshCommands(SSH2 $ssh, string $clientIp, string $clientUser, string $clientPass, string $wanInterface, string $portalIp): array
    {
        Log::info('[MikroTikSSH] Usando método SSH commands via CORE');

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
                Log::debug("[MikroTikSSH] Ejecutando comando " . ($index + 1));

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
                Log::warning('[MikroTikSSH] Algunos comandos fallaron', ['errors' => $errors]);
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
            Log::error('[MikroTikSSH] Error en SSH commands', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Apply firewall rules using API protocol (used by both direct and tunnel methods)
     */
    private function applyFirewallRulesViaApi($socket, string $wanInterface, string $portalIp): void
    {
        // 1. Create address-list (placeholder)
        Log::info('[MikroTikSSH] Creando address-list ISPWATCH_SUSPENDIDOS');
        $this->apiSendCommand($socket, '/ip/firewall/address-list/add', [
            '=list=ISPWATCH_SUSPENDIDOS',
            '=address=0.0.0.0',
            '=comment=Control ISPWatch',
        ]);
        $this->apiReadUntilDone($socket);

        // 2. NAT Rule HTTP
        Log::info('[MikroTikSSH] Creando regla NAT HTTP');
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
        Log::info('[MikroTikSSH] Creando regla NAT HTTPS');
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
        Log::info('[MikroTikSSH] Creando regla Filter DROP');
        $this->apiSendCommand($socket, '/ip/firewall/filter/add', [
            '=chain=forward',
            '=src-address-list=ISPWATCH_SUSPENDIDOS',
            '=out-interface=' . $wanInterface,
            '=action=drop',
            '=comment=ISPWatch - Bloqueo general',
        ]);
        $this->apiReadUntilDone($socket);

        Log::info('[MikroTikSSH] Reglas de firewall aplicadas');
    }

    /**
     * Create a direct-tcpip tunnel through the SSH connection
     */
    private function createTunnelToClient(SSH2 $ssh, string $clientIp, int $clientPort)
    {
        try {
            Log::debug("[MikroTikSSH] Opening tunnel to $clientIp:$clientPort");

            // phpseclib3 supports valid fsockopen over SSH to create a channel
            $channel = $ssh->fsockopen($clientIp, $clientPort);

            if (!$channel) {
                Log::error("[MikroTikSSH] Failed to open channel to $clientIp:$clientPort");
                return null;
            }

            // Set blocking mode to ensure we read data correctly
            stream_set_blocking($channel, true);
            stream_set_timeout($channel, 30);

            return $channel;
        } catch (\Throwable $e) {
            Log::error("[MikroTikSSH] Tunnel exception", ['error' => $e->getMessage()]);
            return null;
        }
    }

    // Método generateFirewallScript eliminado - ya no se usa

    // ==================== API Helper Methods ====================

    /**
     * Login al router via API
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
                Log::error('[MikroTikSSH] API Login trap');
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
     * Enviar comando API
     */
    private function apiSendCommand($socket, string $command, array $params = []): void
    {
        $this->apiWriteWord($socket, $command);
        foreach ($params as $param) {
            $this->apiWriteWord($socket, $param);
        }
        fwrite($socket, chr(0));
    }

    /**
     * Leer hasta !done
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
     * Escribir palabra API
     */
    private function apiWriteWord($socket, string $word): void
    {
        $len = strlen($word);
        if ($len < 0x80) {
            fwrite($socket, chr($len));
        } elseif ($len < 0x4000) {
            $len |= 0x8000;
            fwrite($socket, chr(($len >> 8) & 0xFF));
            fwrite($socket, chr($len & 0xFF));
        } else {
            fwrite($socket, chr(($len >> 16) & 0xFF));
            fwrite($socket, chr(($len >> 8) & 0xFF));
            fwrite($socket, chr($len & 0xFF));
        }
        fwrite($socket, $word);
    }

    /**
     * Leer palabra API
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

    /**
     * Get firewall rules from a client router via SSH from CORE
     * Útil para verificar que las reglas de ISPWatch estén instaladas
     * 
     * @param string $clientIp IP del router cliente (IP VPN asignada)
     * @param string $clientUser Usuario del router cliente
     * @param string $clientPass Password del router cliente
     */
    public function getFirewallRulesViaCore(string $clientIp, string $clientUser, string $clientPass): array
    {
        try {
            Log::info('[MikroTikSSH] Verificando reglas de firewall en cliente via CORE (SSH)', [
                'client_ip' => $clientIp,
            ]);

            $ssh = $this->connect();

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
            Log::error('[MikroTikSSH] Error verificando reglas en cliente', [
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
     * Establish SSH connection
     */
    public function connect(): ?SSH2
    {
        $ssh = new SSH2($this->host, $this->port);
        $ssh->setTimeout($this->timeout);

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
                    if ($ssh->login($this->username, $key)) {
                        Log::info('[MikroTikSSH] Conectado con clave SSH');
                        return $ssh;
                    }
                } catch (\TypeError $e) {
                    Log::warning('[MikroTikSSH] TypeError en login con objeto key, intentando string conversion', [
                        'error' => $e->getMessage(),
                        'key_class' => get_class($key)
                    ]);

                    // Fallback: If login expects string, try passing key as string (PEM)
                    // This might work if the underlying library supports it or if it's a version mismatch workaround
                    try {
                        if ($ssh->login($this->username, (string) $key)) {
                            Log::info('[MikroTikSSH] Conectado con clave SSH (convertida a string)');
                            return $ssh;
                        }
                    } catch (\Throwable $ex) {
                        Log::error('[MikroTikSSH] Falló fallback string login', ['error' => $ex->getMessage()]);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[MikroTikSSH] Error con clave SSH, intentando password', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }

        }

        // Fallback to password authentication
        if ($this->password) {
            if ($ssh->login($this->username, $this->password)) {
                Log::info('[MikroTikSSH] Conectado con password');
                return $ssh;
            }
        }

        Log::error('[MikroTikSSH] Falló autenticación', [
            'host' => $this->host,
            'user' => $this->username,
            'hasKey' => file_exists($this->privateKeyPath ?? ''),
            'hasPassword' => !empty($this->password),
        ]);

        return null;
    }

    /**
     * Get current configuration (sanitized)
     */
    private function getConfig(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'auth_method' => file_exists($this->privateKeyPath ?? '') ? 'ssh_key' : 'password',
            'key_path' => $this->privateKeyPath,
            'key_exists' => file_exists($this->privateKeyPath ?? ''),
        ];
    }

    /**
     * MÉTODO TEMPORAL DE PRUEBA
     * Ejecutar comando en cliente específico vía CORE
     * 
     * @param string $clientIp IP del router cliente (VPN IP)
     * @param string $clientUser Usuario del router cliente
     * @param string $clientPass Password del router cliente
     * @param string $command Comando a ejecutar en el cliente
     */
    public function testExecuteOnClient(string $clientIp, string $clientUser, string $clientPass, string $command): array
    {
        try {
            Log::info('[MikroTikSSH] PRUEBA - Ejecutando comando en cliente via CORE', [
                'client_ip' => $clientIp,
                'command' => $command,
            ]);

            // 1. Conectar al CORE
            $ssh = $this->connect();

            if (!$ssh) {
                return [
                    'success' => false,
                    'message' => 'No se pudo conectar al MikroTik CORE',
                ];
            }

            // 2. Verificar conectividad con ping
            $pingCommand = sprintf('/ping address=%s count=2', $clientIp);
            $pingResult = $ssh->exec($pingCommand);

            Log::info('[MikroTikSSH] PRUEBA - Ping result', ['output' => $pingResult]);

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

            Log::info('[MikroTikSSH] PRUEBA - Ejecutando SSH command');
            $output = $ssh->exec($sshCmd);

            Log::info('[MikroTikSSH] PRUEBA - Resultado', ['output' => $output]);

            $ssh->disconnect();

            return [
                'success' => true,
                'message' => 'Comando ejecutado correctamente',
                'command' => $command,
                'output' => $output,
                'client_ip' => $clientIp,
            ];

        } catch (\Throwable $e) {
            Log::error('[MikroTikSSH] PRUEBA - Error ejecutando comando', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }
}
