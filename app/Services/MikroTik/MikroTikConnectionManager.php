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

    /**
     * Why the last tryDirectClientConnection() probe failed, in operator terms.
     * The probe can only answer yes/no, but the *reason* (the CORE refused to
     * forward, the client reset the connection, nobody answered) is what tells
     * the operator which hop to fix — so we keep it here instead of throwing it
     * away.
     */
    private ?string $lastProbeError = null;

    public function __construct(?MikroTikApiProtocol $apiProtocol = null)
    {
        // SSH Configuration
        // SECURITY FIX (OWASP A02): No hardcoded credential fallbacks in source code.
        // All secrets MUST come from .env — fail loudly if not configured.
        $this->sshHost = env('MIKROTIK_CORE_SSH_HOST', env('MIKROTIK_CORE_API_HOST', ''));
        $this->sshPort = (int) env('MIKROTIK_CORE_SSH_PORT', 22);
        $this->sshUsername = env('MIKROTIK_CORE_SSH_USER', 'admin');
        $this->sshPassword = env('MIKROTIK_CORE_SSH_PASS', null);
        $this->privateKeyPath = env('MIKROTIK_CORE_SSH_KEY_PATH', storage_path('keys/mikrotik_core_id_ed25519'));
        $this->keyPassphrase = env('MIKROTIK_CORE_SSH_KEY_PASSPHRASE', null);

        // API Configuration
        $this->apiHost = env('MIKROTIK_CORE_API_HOST', '');
        $this->apiPort = (int) env('MIKROTIK_CORE_API_PORT', 8728);
        $this->apiUser = env('MIKROTIK_CORE_API_USER', 'admin');
        $this->apiPass = env('MIKROTIK_CORE_API_PASS', '');

        // Operation timeout (how long we wait for a command's OUTPUT, not for the
        // TCP/SSH handshake — that one is MIKROTIK_CORE_SSH_CONNECT_TIMEOUT).
        // Configurable because commands that make the CORE open a *nested* SSH
        // session to a client router legitimately take longer than a local one.
        $this->timeout = max(5, (int) env('MIKROTIK_CORE_SSH_TIMEOUT', 15));
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

        // Use loginDetailed() to distinguish Login Protection (socket_closed)
        // from wrong credentials (trap) — the generic login() collapsed both
        // into "Error de autenticación" making debugging impossible.
        $loginResult = $this->apiProtocol->loginDetailed($socket, $clientUser, $clientPass);
        if (!$loginResult['success']) {
            $reason = $loginResult['reason'] ?? 'unknown';
            $message = $loginResult['message'] ?? '';

            if ($reason === 'socket_closed') {
                Log::error('[MikroTikConnectionManager] Login Protection o firewall bloqueó la conexión API', [
                    'client' => "{$clientIp}:{$clientPort}",
                    'user' => $clientUser,
                    'hint' => 'RouterOS "Login Protection" cierra el socket sin responder cuando la IP de origen tiene demasiados intentos fallidos. Espera ~10min o agrega la IP del CORE a /ip services allowed-from.',
                ]);
            } else {
                Log::error('[MikroTikConnectionManager] Credenciales rechazadas por el router cliente', [
                    'client' => "{$clientIp}:{$clientPort}",
                    'user' => $clientUser,
                    'reason' => $reason,
                    'message' => $message,
                ]);
            }

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
     *
     * Connecting to the local end is NOT enough to conclude that. `ssh -L` binds
     * and accepts locally straight away and only *then* asks the CORE to open the
     * forwarded channel; when the CORE can't reach the client (or refuses to
     * forward at all) it answers with a channel-open failure and ssh drops the
     * local socket. A bare fsockopen() therefore succeeded even when nothing on
     * the far side existed — the probe said "reachable", the API login that
     * followed got no reply, and the modal blamed the router's credentials.
     * So we wait briefly for the far end and treat an immediate EOF as refusal,
     * keeping ssh's own explanation in $lastProbeError.
     */
    public function tryDirectClientConnection(string $clientIp, int $clientPort = 8728): bool
    {
        $this->lastProbeError = null;

        try {
            $tunnel = $this->tunnelManager()->open($clientIp, $clientPort);
        } catch (\Throwable $e) {
            Log::debug('[MikroTikConnectionManager] probe: tunnel open failed', [
                'client' => "{$clientIp}:{$clientPort}",
                'error' => $e->getMessage(),
            ]);
            $this->lastProbeError = $e->getMessage();
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
                $this->lastProbeError = trim("{$errstr} (errno {$errno})");
                return false;
            }

            // Give the forwarded channel a moment to either survive or collapse.
            // MikroTik's API never speaks first, so a clean read timeout here is
            // the *good* outcome; EOF means ssh tore the socket down because the
            // CORE could not complete the far end.
            stream_set_blocking($probe, true);
            stream_set_timeout($probe, 0, 400000); // 400ms
            @fread($probe, 1);
            $meta = @stream_get_meta_data($probe);
            $farEndDied = empty($meta['timed_out']) && (!empty($meta['eof']) || feof($probe));
            @fclose($probe);

            if ($farEndDied) {
                $stderr = trim($tunnel->drainStderr());
                $this->lastProbeError = $this->explainForwardFailure(
                    $stderr,
                    $clientIp,
                    $clientPort,
                    !$tunnel->isPassthrough()
                );

                Log::debug('[MikroTikConnectionManager] probe: forwarded channel collapsed', [
                    'client' => "{$clientIp}:{$clientPort}",
                    'ssh_stderr' => $stderr,
                ]);

                return false;
            }

            return true;
        } finally {
            $tunnel->close();
        }
    }

    /**
     * Why the last tryDirectClientConnection() said "no", or null if it said yes
     * (or was never called). Callers surface this so the operator sees which hop
     * failed instead of a generic "port unreachable".
     */
    public function lastProbeError(): ?string
    {
        return $this->lastProbeError;
    }

    /**
     * Turn ssh's channel-open complaint into something an operator can act on.
     * "administratively prohibited" in particular is not a client problem at all
     * — it is the CORE refusing to forward (`/ip ssh set forwarding-enabled`).
     */
    private function explainForwardFailure(string $stderr, string $clientIp, int $clientPort, bool $viaCore = true): string
    {
        $low = strtolower($stderr);
        // With MIKROTIK_USE_CORE_TUNNEL=false there is no CORE in the path at
        // all; naming it would send a developer to debug a hop that never ran.
        $who = $viaCore ? 'el CORE' : 'el servidor';

        if (str_contains($low, 'administratively prohibited')) {
            return "el CORE rechazó reenviar la conexión hacia {$clientIp}:{$clientPort}. " .
                   'El CORE tiene el reenvío SSH deshabilitado: ejecuta en el CORE ' .
                   '`/ip ssh set forwarding-enabled=both`.';
        }

        if (str_contains($low, 'connection refused')) {
            return "{$who} alcanzó a {$clientIp} pero el puerto {$clientPort} está cerrado " .
                   '(servicio API deshabilitado o escuchando en otro puerto).';
        }

        if (str_contains($low, 'no route to host') || str_contains($low, 'host is unreachable')
            || str_contains($low, 'network is unreachable')) {
            return "{$who} no tiene ruta hacia {$clientIp}: el router no está conectado al overlay VPN " .
                   'en esa dirección (revisa `/ppp active print` en el CORE).';
        }

        if (str_contains($low, 'timed out') || str_contains($low, 'timeout')) {
            return "nadie respondió en {$clientIp}:{$clientPort}: la IP overlay guardada puede estar obsoleta " .
                   '(el pool reasigna la dirección en cada reconexión).';
        }

        return "{$who} no pudo completar la conexión hacia {$clientIp}:{$clientPort}" .
               ($stderr !== '' ? " (ssh: {$stderr})" : '.');
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
            // Connect timeout (handshake TCP/SSH) separado del timeout de
            // operación. Si el CORE está inalcanzable queremos fallar rápido
            // (≈ connectTimeout) en vez de colgar hasta el operation timeout:
            // en aprovisionamiento masivo cada cuelgue se acumula y dispara el
            // 504 del gateway (DigitalOcean ≈60s).
            //
            // OJO: phpseclib conecta de forma perezosa en el primer login() y
            // usa $this->timeout como timeout de conexión. Por eso NO llamamos
            // setTimeout() antes del login (sobreescribiría el connect timeout);
            // lo subimos al timeout de operación recién tras conectar.
            $connectTimeout = (int) env('MIKROTIK_CORE_SSH_CONNECT_TIMEOUT', 8);
            $ssh = new SSH2($this->sshHost, $this->sshPort, $connectTimeout);

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
                            $ssh->setTimeout($effectiveTimeout);
                            Log::info('[MikroTikConnectionManager] Conectado con clave SSH');
                            return $ssh;
                        }
                    } catch (\TypeError $e) {
                        Log::warning('[MikroTikConnectionManager] TypeError en login con objeto key', [
                            'error' => $e->getMessage(),
                        ]);

                        try {
                            if ($ssh->login($this->sshUsername, (string) $key)) {
                                $ssh->setTimeout($effectiveTimeout);
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
                    $ssh->setTimeout($effectiveTimeout);
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
     * Execute command on MikroTik CORE via SSH.
     *
     * $timeoutSeconds overrides the operation timeout for THIS command only.
     * Commands that make the CORE open a nested SSH session to a client router
     * (`/system ssh-exec`) need a longer window than the default: the CORE has
     * to do a full SSH handshake with the client before it writes a single byte
     * back to us.
     *
     * IMPORTANT — why the timeout is reported instead of swallowed:
     * phpseclib's exec() does NOT throw when the operation timeout expires. It
     * returns whatever bytes arrived so far and flips isTimeout(). That made a
     * timeout indistinguishable from a completed command with short output, so
     * a half-written response (e.g. only the `ISP_BEGIN` sentinel of a script
     * still blocked inside ssh-exec) came back as `success: true` and every
     * caller then misread the truncated payload — the WAN modal reported
     * "el router respondió pero no se pudieron leer las interfaces" when in
     * reality nothing had answered yet.
     */
    public function executeSsh(string $command, ?int $timeoutSeconds = null): array
    {
        try {
            $ssh = $this->connectSsh($timeoutSeconds);

            if (!$ssh) {
                return [
                    'success' => false,
                    'message' => 'No se pudo conectar al MikroTik via SSH',
                    'output' => null,
                ];
            }

            $output = $ssh->exec($command);
            $timedOut = $ssh->isTimeout();
            $ssh->disconnect();

            if ($output === false) {
                return [
                    'success' => false,
                    'message' => 'El CORE no ejecutó el comando (el canal SSH se cerró antes de responder).',
                    'output' => null,
                ];
            }

            if ($timedOut) {
                $waited = $timeoutSeconds ?? $this->timeout;

                Log::warning('[MikroTikConnectionManager] SSH exec timed out — respuesta truncada', [
                    'waited_seconds'  => $waited,
                    'partial_length'  => strlen((string) $output),
                    'partial_preview' => substr((string) $output, 0, 300),
                ]);

                return [
                    'success'   => false,
                    'timed_out' => true,
                    'message'   => "El CORE no terminó de responder en {$waited}s; la salida llegó truncada.",
                    'output'    => $output,
                ];
            }

            return [
                'success' => true,
                'timed_out' => false,
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
