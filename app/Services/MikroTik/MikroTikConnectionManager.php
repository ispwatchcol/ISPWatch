<?php

namespace App\Services\MikroTik;

use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * MikroTik Connection Manager
 * 
 * Handles SSH and API connections to MikroTik CORE router.
 * Implements dual connection strategy: API first, SSH fallback.
 */
class MikroTikConnectionManager
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

    private int $timeout;
    private MikroTikApiProtocol $apiProtocol;
    private ?SshTunnelManager $tunnelManagerInstance = null;
    private ?SshTunnel $activeClientTunnel = null;

    public function __construct(?MikroTikApiProtocol $apiProtocol = null)
    {
        // SSH Configuration
        $this->sshHost = env('MIKROTIK_CORE_SSH_HOST', env('MIKROTIK_CORE_API_HOST', '167.172.132.234'));
        $this->sshPort = (int) env('MIKROTIK_CORE_SSH_PORT', 22);
        $this->sshUsername = env('MIKROTIK_CORE_SSH_USER', 'admin');
        $this->sshPassword = env('MIKROTIK_CORE_SSH_PASS', null);
        $this->privateKeyPath = env('MIKROTIK_CORE_SSH_KEY_PATH', storage_path('keys/mikrotik_core_id_ed25519'));
        $this->keyPassphrase = env('MIKROTIK_CORE_SSH_KEY_PASSPHRASE', null);

        // API Configuration
        $this->apiHost = env('MIKROTIK_CORE_API_HOST', '167.172.132.234');
        $this->apiPort = (int) env('MIKROTIK_CORE_API_PORT', 8728);
        $this->apiUser = env('MIKROTIK_CORE_API_USER', 'admin');
        $this->apiPass = env('MIKROTIK_CORE_API_PASS', 'Colombia2018');

        $this->timeout = 15;
        $this->apiProtocol = $apiProtocol ?? new MikroTikApiProtocol();
    }

    // ==================== GETTERS ====================

    public function getApiHost(): string
    {
        return $this->apiHost;
    }

    public function getApiPort(): int
    {
        return $this->apiPort;
    }

    public function getApiUser(): string
    {
        return $this->apiUser;
    }

    public function getApiPass(): string
    {
        return $this->apiPass;
    }

    public function getSshHost(): string
    {
        return $this->sshHost;
    }

    public function getSshPort(): int
    {
        return $this->sshPort;
    }

    public function getSshUsername(): string
    {
        return $this->sshUsername;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getApiProtocol(): MikroTikApiProtocol
    {
        return $this->apiProtocol;
    }

    // ==================== CONNECTION TESTING ====================

    /**
     * Test both API and SSH connections to MikroTik CORE
     * Optimized: If API works, SSH test is skipped to avoid long timeouts
     */
    public function testConnection(): array
    {
        $apiResult = $this->testApiConnection();

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

        $sshResult = $this->testSshConnection(10);

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
            $socket = $this->apiProtocol->connect($this->apiHost, $this->apiPort, 10);

            if (!$socket) {
                return [
                    'success' => false,
                    'message' => '❌ No se pudo conectar a API',
                    'host' => $this->apiHost,
                    'port' => $this->apiPort,
                ];
            }

            if (!$this->apiProtocol->login($socket, $this->apiUser, $this->apiPass)) {
                $this->apiProtocol->close($socket);
                return [
                    'success' => false,
                    'message' => '❌ Error de autenticación API',
                ];
            }

            $this->apiProtocol->sendCommand($socket, '/system/identity/print');
            $records = $this->apiProtocol->readAllRecords($socket);
            $identity = $records[0]['name'] ?? 'Unknown';

            $this->apiProtocol->close($socket);

            return [
                'success' => true,
                'message' => '✅ Conexión API al MikroTik CORE exitosa',
                'identity' => $identity,
            ];

        } catch (\Throwable $e) {
            Log::error('[MikroTikConnectionManager] Error testing API connection', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => '❌ Error API: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Test SSH connection to MikroTik CORE
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

            $output = $ssh->exec('/system identity print');
            $ssh->disconnect();

            return [
                'success' => true,
                'message' => '✅ Conexión SSH al MikroTik CORE exitosa',
                'identity' => trim($output),
            ];

        } catch (\Throwable $e) {
            Log::error('[MikroTikConnectionManager] Error testing SSH connection', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => '❌ Error SSH: ' . $e->getMessage(),
            ];
        }
    }

    // ==================== API CONNECTIONS ====================

    /**
     * Connect to CORE via API and login
     * 
     * @return resource|false Socket resource or false on failure
     */
    public function connectApi()
    {
        $socket = $this->apiProtocol->connect($this->apiHost, $this->apiPort, $this->timeout);

        if (!$socket) {
            return false;
        }

        if (!$this->apiProtocol->login($socket, $this->apiUser, $this->apiPass)) {
            $this->apiProtocol->close($socket);
            return false;
        }

        return $socket;
    }

    /**
     * Connect to a CLIENT router via API through an SSH local-forward tunnel.
     *
     * The tunnel is opened on this manager's $activeClientTunnel and lives until
     * closeClientApi() is called (or this object is destroyed). The returned
     * socket points at 127.0.0.1:<tunnel-local-port>, not at the actual client.
     *
     * @return resource|false Socket resource or false on failure
     */
    public function connectClientApi(string $clientIp, int $clientPort, string $clientUser, string $clientPass)
    {
        if ($this->activeClientTunnel !== null) {
            // Don't silently overwrite — the previous tunnel would leak.
            Log::warning('[MikroTikConnectionManager] connectClientApi called with an existing tunnel — closing it first');
            $this->activeClientTunnel->close();
            $this->activeClientTunnel = null;
        }

        try {
            $this->activeClientTunnel = $this->tunnelManager()->open($clientIp, $clientPort);
        } catch (\Throwable $e) {
            Log::error('[MikroTikConnectionManager] tunnel open failed', [
                'client' => "{$clientIp}:{$clientPort}",
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        $socket = $this->apiProtocol->connect(
            $this->activeClientTunnel->localHost(),
            $this->activeClientTunnel->localPort(),
            $this->timeout
        );

        if (!$socket) {
            $this->activeClientTunnel->close();
            $this->activeClientTunnel = null;
            return false;
        }

        if (!$this->apiProtocol->login($socket, $clientUser, $clientPass)) {
            $this->apiProtocol->close($socket);
            $this->activeClientTunnel->close();
            $this->activeClientTunnel = null;
            return false;
        }

        return $socket;
    }

    /**
     * Close a socket opened by connectClientApi() AND tear down its tunnel.
     */
    public function closeClientApi($socket): void
    {
        if ($socket) {
            $this->apiProtocol->close($socket);
        }
        if ($this->activeClientTunnel !== null) {
            $this->activeClientTunnel->close();
            $this->activeClientTunnel = null;
        }
    }

    /**
     * Probe whether a client router is reachable.
     *
     * In production the client router lives behind the L2TP overlay on the CORE,
     * so "reachable" means: we can open an SSH tunnel through the CORE and a
     * TCP connection completes through it on the requested port.
     */
    public function tryDirectClientConnection(string $clientIp, int $clientPort = 8728): bool
    {
        try {
            $tunnel = $this->tunnelManager()->open($clientIp, $clientPort);
        } catch (\Throwable $e) {
            Log::debug('[MikroTikConnectionManager] probe: tunnel open failed', [
                'client' => "{$clientIp}:{$clientPort}",
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        try {
            $errno = 0; $errstr = '';
            $probe = @fsockopen($tunnel->localHost(), $tunnel->localPort(), $errno, $errstr, 3);
            if (!$probe) {
                Log::debug('[MikroTikConnectionManager] probe: tunnel up but client TCP refused', [
                    'client' => "{$clientIp}:{$clientPort}",
                    'errno' => $errno,
                    'errstr' => $errstr,
                ]);
                return false;
            }
            @fclose($probe);
            return true;
        } finally {
            $tunnel->close();
        }
    }

    private function tunnelManager(): SshTunnelManager
    {
        if ($this->tunnelManagerInstance === null) {
            $this->tunnelManagerInstance = new SshTunnelManager();
        }
        return $this->tunnelManagerInstance;
    }

    // ==================== SSH CONNECTIONS ====================

    /**
     * Establish SSH connection to CORE
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
                            Log::info('[MikroTikConnectionManager] Conectado con clave SSH');
                            return $ssh;
                        }
                    } catch (\TypeError $e) {
                        Log::warning('[MikroTikConnectionManager] TypeError en login con objeto key', [
                            'error' => $e->getMessage(),
                        ]);

                        try {
                            if ($ssh->login($this->sshUsername, (string) $key)) {
                                Log::info('[MikroTikConnectionManager] Conectado con clave SSH (string)');
                                return $ssh;
                            }
                        } catch (\Throwable $ex) {
                            Log::error('[MikroTikConnectionManager] Falló fallback string login', ['error' => $ex->getMessage()]);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('[MikroTikConnectionManager] Error con clave SSH, intentando password', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Fallback to password authentication
            if ($this->sshPassword) {
                if ($ssh->login($this->sshUsername, $this->sshPassword)) {
                    Log::info('[MikroTikConnectionManager] Conectado con password');
                    return $ssh;
                }
            }

            Log::error('[MikroTikConnectionManager] Falló autenticación SSH', [
                'host' => $this->sshHost,
                'user' => $this->sshUsername,
                'hasKey' => file_exists($this->privateKeyPath ?? ''),
                'hasPassword' => !empty($this->sshPassword),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error('[MikroTikConnectionManager] SSH connection exception', ['error' => $e->getMessage()]);
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

    /**
     * Execute command on MikroTik CORE via SSH
     */
    public function executeSsh(string $command): array
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
            Log::error('[MikroTikConnectionManager] Error executing SSH command', [
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

    // ==================== CONFIGURATION ====================

    /**
     * Get current configuration (sanitized - no passwords)
     */
    public function getConfig(): array
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
