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
     * Apply block rules to a client router via SSH from CORE
     * Ejecuta comandos de firewall en el router cliente a través del CORE
     * 
     * @param string $clientIp IP del router cliente (IP VPN asignada)
     * @param string $clientUser Usuario del router cliente
     * @param string $clientPass Password del router cliente
     * @param string $wanInterface Interfaz WAN del router cliente
     * @param string $portalIp IP del portal de redirección
     */
    public function applyBlockRulesViaCore(string $clientIp, string $clientUser, string $clientPass, string $wanInterface, string $portalIp): array
    {
        try {
            Log::info('[MikroTikSSH] Aplicando reglas de bloqueo en router cliente via CORE', [
                'client_ip' => $clientIp,
                'client_user' => $clientUser,
                'wan_interface' => $wanInterface,
                'portal_ip' => $portalIp,
            ]);

            $ssh = $this->connect();

            if (!$ssh) {
                return [
                    'success' => false,
                    'message' => 'No se pudo conectar al MikroTik CORE',
                ];
            }

            // Primero verificar conectividad con ping
            $pingCommand = sprintf('/ping address=%s count=1', $clientIp);
            $pingResult = $ssh->exec($pingCommand);

            if (str_contains($pingResult, 'timeout') || str_contains($pingResult, '0 received')) {
                $ssh->disconnect();
                return [
                    'success' => false,
                    'message' => 'El router cliente no responde. Verifica que la VPN esté activa.',
                ];
            }

            Log::info('[MikroTikSSH] Router cliente accesible, ejecutando comandos remotos via SSH');

            // Ejecutar cada comando por separado usando SSH desde CORE hacia cliente
            // Importante: El password puede tener caracteres especiales, lo escapamos
            $escapedPass = addslashes($clientPass);

            // 1. Crear address-list
            $cmd1 = sprintf(
                '/system ssh address=%s user=%s password="%s" command="/ip firewall address-list add list=ISPWATCH_SUSPENDIDOS address=0.0.0.0 comment=Control-ISPWatch"',
                $clientIp,
                $clientUser,
                $escapedPass
            );
            Log::debug('[MikroTikSSH] Ejecutando cmd1', ['cmd' => $cmd1]);
            $result1 = $ssh->exec($cmd1);
            Log::info('[MikroTikSSH] Address-list resultado', ['output' => $result1]);
            sleep(1); // Esperar entre comandos

            // 2. Regla NAT HTTP
            $cmd2 = sprintf(
                '/system ssh address=%s user=%s password="%s" command="/ip firewall nat add chain=dstnat src-address-list=ISPWATCH_SUSPENDIDOS protocol=tcp dst-port=80 action=dst-nat to-addresses=%s to-ports=80 comment=ISPWatch-Portal-HTTP"',
                $clientIp,
                $clientUser,
                $escapedPass,
                $portalIp
            );
            Log::debug('[MikroTikSSH] Ejecutando cmd2');
            $result2 = $ssh->exec($cmd2);
            Log::info('[MikroTikSSH] NAT HTTP resultado', ['output' => $result2]);
            sleep(1);

            // 3. Regla NAT HTTPS
            $cmd3 = sprintf(
                '/system ssh address=%s user=%s password="%s" command="/ip firewall nat add chain=dstnat src-address-list=ISPWATCH_SUSPENDIDOS protocol=tcp dst-port=443 action=dst-nat to-addresses=%s to-ports=443 comment=ISPWatch-Portal-HTTPS"',
                $clientIp,
                $clientUser,
                $escapedPass,
                $portalIp
            );
            Log::debug('[MikroTikSSH] Ejecutando cmd3');
            $result3 = $ssh->exec($cmd3);
            Log::info('[MikroTikSSH] NAT HTTPS resultado', ['output' => $result3]);
            sleep(1);

            // 4. Regla FILTER
            $cmd4 = sprintf(
                '/system ssh address=%s user=%s password="%s" command="/ip firewall filter add chain=forward src-address-list=ISPWATCH_SUSPENDIDOS out-interface=%s action=drop comment=ISPWatch-Bloqueo-General"',
                $clientIp,
                $clientUser,
                $escapedPass,
                $wanInterface
            );
            Log::debug('[MikroTikSSH] Ejecutando cmd4');
            $result4 = $ssh->exec($cmd4);
            Log::info('[MikroTikSSH] FILTER resultado', ['output' => $result4]);

            $ssh->disconnect();

            return [
                'success' => true,
                'message' => 'Reglas de bloqueo aplicadas correctamente en el router cliente',
                'rules_applied' => [
                    'address_list' => 'ISPWATCH_SUSPENDIDOS',
                    'portal_ip' => $portalIp,
                    'wan_interface' => $wanInterface,
                    'nat_rules' => ['HTTP:80', 'HTTPS:443'],
                    'filter_rule' => 'DROP forward to WAN',
                ],
            ];

        } catch (\Throwable $e) {
            Log::error('[MikroTikSSH] Error aplicando reglas en cliente via CORE', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
    private function connect(): ?SSH2
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
}
