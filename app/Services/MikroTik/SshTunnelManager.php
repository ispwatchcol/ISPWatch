<?php

namespace App\Services\MikroTik;

use Illuminate\Support\Facades\Log;

/**
 * Opens on-demand SSH local-forward tunnels through the MikroTik CORE so that
 * Laravel can reach client routers whose IPs are only routable from inside the
 * L2TP overlay (typically RFC1918 ranges).
 *
 * Usage:
 *   $tunnel = app(SshTunnelManager::class);
 *   return $tunnel->using($router->ip, 8728, function (string $host, int $port) {
 *       $socket = fsockopen($host, $port, ...);   // talks to client router
 *       ...
 *   });
 *
 * The SSH process is torn down automatically when the callback returns
 * (or throws). Manual lifecycle is also available via open()/close().
 */
class SshTunnelManager
{
    private string $coreHost;
    private int $corePort;
    private string $coreUser;
    private string $privateKeyPath;
    private ?string $keyPassphrase;
    private float $readyTimeoutSeconds;
    private bool $enabled;

    public function __construct()
    {
        // SECURITY FIX (OWASP A02): No hardcoded IPs in source code.
        $this->coreHost       = env('MIKROTIK_CORE_SSH_HOST', '');
        $this->corePort       = (int) env('MIKROTIK_CORE_SSH_PORT', 22);
        $this->coreUser       = env('MIKROTIK_CORE_SSH_USER', 'admin');
        $this->privateKeyPath = env('MIKROTIK_CORE_SSH_KEY_PATH', storage_path('keys/mikrotik_core_id_ed25519'));
        // The CORE deploy key is passphrase-protected. The system `ssh` runs with
        // BatchMode=yes (no prompt) and there is no ssh-agent, so we must hand it
        // a key it can use without a passphrase. When this is set we decrypt the
        // key in memory and write a short-lived passphrase-less copy for ssh -i.
        $this->keyPassphrase = env('MIKROTIK_CORE_SSH_KEY_PASSPHRASE', null) ?: null;
        $this->readyTimeoutSeconds = (float) env('MIKROTIK_TUNNEL_READY_TIMEOUT', 6.0);

        // Tunnel is only needed when the Laravel server cannot reach client
        // router IPs directly (e.g. production behind DO App Platform). Devs
        // running locally on the same network as the routers should set
        // MIKROTIK_USE_CORE_TUNNEL=false in their .env to skip the ssh dance
        // entirely and connect directly to $router->ip.
        $this->enabled = filter_var(
            env('MIKROTIK_USE_CORE_TUNNEL', true),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Open a tunnel, run $callback($localHost, $localPort), then close.
     * When tunneling is disabled, $callback is invoked with the original
     * $clientIp/$clientPort and no ssh subprocess is spawned.
     *
     * @template T
     * @param  callable(string $localHost, int $localPort): T  $callback
     * @return T
     */
    public function using(string $clientIp, int $clientPort, callable $callback)
    {
        $tunnel = $this->open($clientIp, $clientPort);
        try {
            return $callback($tunnel->localHost(), $tunnel->localPort());
        } finally {
            $tunnel->close();
        }
    }

    /**
     * Open a tunnel and return the handle. Caller MUST call close() on it.
     *
     * When MIKROTIK_USE_CORE_TUNNEL=false this returns a passthrough handle
     * whose localHost()/localPort() resolve to the original client endpoint;
     * close() is then a no-op.
     */
    public function open(string $clientIp, int $clientPort): SshTunnel
    {
        if (!$this->enabled) {
            Log::debug('[SshTunnelManager] tunnel disabled — passthrough mode', [
                'clientIp'   => $clientIp,
                'clientPort' => $clientPort,
            ]);
            return SshTunnel::passthrough($clientIp, $clientPort);
        }

        $this->assertSshAvailable();
        $this->assertKeyExists();

        [$effectiveKeyPath, $tempKeyPath] = $this->resolveEffectiveKeyPath();

        $localPort = $this->findFreeLocalPort();
        $cmd       = $this->buildSshCommand($clientIp, $clientPort, $localPort, $effectiveKeyPath);

        Log::debug('[SshTunnelManager] opening tunnel', [
            'clientIp'   => $clientIp,
            'clientPort' => $clientPort,
            'localPort'  => $localPort,
            'core'       => "{$this->coreUser}@{$this->coreHost}:{$this->corePort}",
        ]);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes   = [];
        $process = @proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            if ($tempKeyPath !== null && is_file($tempKeyPath)) {
                @unlink($tempKeyPath);
            }
            throw new \RuntimeException(
                "Failed to spawn ssh subprocess for tunnel to {$clientIp}:{$clientPort}"
            );
        }

        // stdin: not used, close immediately so ssh sees EOF.
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            @fclose($pipes[0]);
            unset($pipes[0]);
        }
        // stdout/stderr: non-blocking so drainStderr() doesn't hang.
        foreach ([1, 2] as $fd) {
            if (isset($pipes[$fd]) && is_resource($pipes[$fd])) {
                stream_set_blocking($pipes[$fd], false);
            }
        }

        $tunnel = new SshTunnel($process, $pipes, $localPort, $clientIp, $clientPort, $tempKeyPath);

        try {
            $this->waitUntilReady($tunnel);
        } catch (\Throwable $e) {
            $tunnel->close(); // also unlinks $tempKeyPath
            throw $e;
        }

        return $tunnel;
    }

    /**
     * Bind to port 0 on the loopback and let the OS hand us an unused port,
     * then release it. The race with `ssh -L` reusing the same port is small
     * (microseconds) and `ExitOnForwardFailure=yes` makes us fail loudly.
     */
    private function findFreeLocalPort(): int
    {
        $errno = 0; $errstr = '';
        $listener = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!$listener) {
            throw new \RuntimeException("Could not allocate local port: {$errstr}");
        }
        $name = stream_socket_get_name($listener, false);
        @fclose($listener);

        $colon = strrpos((string) $name, ':');
        if ($colon === false) {
            throw new \RuntimeException("Could not parse local port from '{$name}'");
        }
        return (int) substr($name, $colon + 1);
    }

    private function buildSshCommand(string $clientIp, int $clientPort, int $localPort, string $keyPath): string
    {
        $forward = '127.0.0.1:' . $localPort . ':' . $clientIp . ':' . $clientPort;

        // /dev/null does not exist on Windows; the equivalent null device is NUL.
        $nullKnownHosts = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

        $parts = [
            'ssh',
            '-i', escapeshellarg($keyPath),
            '-o', escapeshellarg('IdentitiesOnly=yes'),
            '-o', escapeshellarg('StrictHostKeyChecking=no'),
            '-o', escapeshellarg('UserKnownHostsFile=' . $nullKnownHosts),
            '-o', escapeshellarg('BatchMode=yes'),
            '-o', escapeshellarg('ConnectTimeout=5'),
            '-o', escapeshellarg('ServerAliveInterval=10'),
            '-o', escapeshellarg('ServerAliveCountMax=2'),
            '-o', escapeshellarg('ExitOnForwardFailure=yes'),
            '-N',
            '-L', escapeshellarg($forward),
            '-p', (string) $this->corePort,
            escapeshellarg($this->coreUser . '@' . $this->coreHost),
        ];

        return implode(' ', $parts);
    }

    private function waitUntilReady(SshTunnel $tunnel): void
    {
        $deadline   = microtime(true) + $this->readyTimeoutSeconds;
        $lastError  = null;

        while (microtime(true) < $deadline) {
            if (!$tunnel->isRunning()) {
                $stderr = trim($tunnel->drainStderr());
                throw new \RuntimeException(
                    $this->explainSshFailure($stderr, 'el proceso ssh terminó antes de establecer el túnel')
                );
            }

            $errno = 0; $errstr = '';
            $probe = @stream_socket_client(
                "tcp://127.0.0.1:{$tunnel->localPort()}",
                $errno,
                $errstr,
                0.5
            );

            if ($probe) {
                @fclose($probe);
                Log::debug('[SshTunnelManager] tunnel ready', [
                    'localPort' => $tunnel->localPort(),
                    'clientIp'  => $tunnel->clientIp(),
                ]);
                return;
            }

            $lastError = $errstr ?: "errno {$errno}";
            usleep(100000); // 100ms
        }

        $stderr = trim($tunnel->drainStderr());
        throw new \RuntimeException(
            $this->explainSshFailure(
                $stderr,
                "el túnel a {$tunnel->clientIp()}:{$tunnel->clientPort()} no quedó listo en " .
                "{$this->readyTimeoutSeconds}s (último error de sondeo: {$lastError})"
            )
        );
    }

    /**
     * Turn raw ssh stderr into an operator-facing message that says WHICH hop
     * failed, so this stops being misdiagnosed as a router credential / API /
     * client-firewall problem when it is really server→CORE reachability or the
     * CORE deploy key.
     */
    private function explainSshFailure(string $stderr, string $context): string
    {
        $core = "{$this->coreUser}@{$this->coreHost}:{$this->corePort}";
        $low  = strtolower($stderr);

        $unreachable = str_contains($low, 'connection timed out')
            || str_contains($low, 'connection refused')
            || str_contains($low, 'no route to host')
            || str_contains($low, 'network is unreachable')
            || str_contains($low, 'operation timed out')
            || str_contains($low, 'could not resolve hostname');

        $authFailed = str_contains($low, 'permission denied')
            || str_contains($low, 'too many authentication failures')
            || str_contains($low, 'no matching host key');

        $keyProblem = str_contains($low, 'load key')
            || str_contains($low, 'incorrect passphrase')
            || str_contains($low, 'invalid format')
            || str_contains($low, 'bad permissions');

        if ($unreachable) {
            $msg = "No se pudo establecer SSH al CORE ({$core}) desde este servidor: host inalcanzable. " .
                "Esto NO es un problema de credenciales del router cliente, de la API MikroTik ni del " .
                "firewall del router — es alcance servidor→CORE: el firewall del CORE bloquea la IP de " .
                "origen de este servidor (o no está en la lista ALLOWED_MGMT / está en BLACKLIST), o el " .
                "CORE no expone SSH en esa IP/puerto.";
        } elseif ($keyProblem) {
            $msg = "SSH al CORE ({$core}) rechazado por la llave: no se pudo cargar/descifrar la clave " .
                "privada del CORE. Si la llave tiene passphrase, configura MIKROTIK_CORE_SSH_KEY_PASSPHRASE " .
                "(o usa una llave sin passphrase para el túnel). No es un problema del router cliente.";
        } elseif ($authFailed) {
            $msg = "SSH al CORE ({$core}) autenticación rechazada (publickey). La llave de este servidor no " .
                "está autorizada en el CORE (/user ssh-keys), o la passphrase es incorrecta. No es un " .
                "problema de credenciales del router cliente.";
        } else {
            $msg = "No se pudo establecer el túnel SSH a través del CORE ({$core}).";
        }

        $msg .= " [{$context}]";
        if ($stderr !== '') {
            $msg .= " ssh stderr: {$stderr}";
        }
        return $msg;
    }

    private function assertSshAvailable(): void
    {
        static $checked = null;
        if ($checked !== null) {
            if ($checked === false) {
                throw new \RuntimeException($this->sshMissingMessage());
            }
            return;
        }

        // Cross-platform: `where ssh` on Windows, `command -v ssh` on POSIX.
        // exec() gives us the real exit code; shell_exec() with `command -v`
        // silently fails on Windows because `command` is a bash builtin.
        $output = [];
        $returnCode = -1;
        $probe = PHP_OS_FAMILY === 'Windows'
            ? 'where ssh 2>NUL'
            : 'command -v ssh 2>/dev/null';
        @exec($probe, $output, $returnCode);
        $checked = ($returnCode === 0);

        if (!$checked) {
            throw new \RuntimeException($this->sshMissingMessage());
        }
    }

    private function sshMissingMessage(): string
    {
        return "ssh client not found on PATH. " . (PHP_OS_FAMILY === 'Windows'
            ? "On Windows: install OpenSSH Client via Settings > Apps > Optional Features, " .
              "or add C:\\Windows\\System32\\OpenSSH to PATH."
            : "Install OpenSSH client in the runtime image.");
    }

    private function assertKeyExists(): void
    {
        if (!is_file($this->privateKeyPath)) {
            throw new \RuntimeException(
                "MikroTik CORE SSH private key not found at '{$this->privateKeyPath}'. " .
                "Set MIKROTIK_CORE_SSH_KEY_B64 in the App Platform env vars; the run_command " .
                "decodes it into storage/keys/mikrotik_core_id_ed25519 at container start."
            );
        }

        // POSIX-only: ssh refuses world/group-readable keys. On Windows the
        // permission model is ACL-based, fileperms() reports a meaningless
        // 0666-ish value and chmod() is a no-op that just logs "Permission
        // denied" noise — so skip the bit-check there entirely.
        if (PHP_OS_FAMILY !== 'Windows') {
            $perms = @fileperms($this->privateKeyPath);
            if ($perms !== false && ($perms & 0077) !== 0) {
                @chmod($this->privateKeyPath, 0600);
            }
        }
    }

    /**
     * Resolve the key path the system `ssh` should use with `-i`.
     *
     * If MIKROTIK_CORE_SSH_KEY_PASSPHRASE is configured the on-disk key is
     * passphrase-protected, which the BatchMode=yes ssh subprocess cannot
     * decrypt (no prompt, no agent). We copy the key and strip the passphrase
     * with OpenSSH's own `ssh-keygen -p`, returning the temp path as the second
     * element so the caller can delete it when the tunnel closes.
     *
     * NOTE: we deliberately do NOT use phpseclib's toString('OpenSSH') here —
     * for ed25519 it emits a private key the OpenSSH *client* loads but the
     * server then rejects with "Permission denied (publickey)" (verified
     * against the CORE). `ssh-keygen -p` round-trips correctly.
     *
     * On any failure we fall back to the original key path (behaviour is then
     * no worse than before this fix) and return null as the temp path.
     *
     * @return array{0:string,1:?string} [effectiveKeyPath, tempKeyPathToCleanup]
     */
    private function resolveEffectiveKeyPath(): array
    {
        if ($this->keyPassphrase === null) {
            return [$this->privateKeyPath, null];
        }

        try {
            if (!is_file($this->privateKeyPath)) {
                throw new \RuntimeException('could not read key file');
            }

            $tmp = tempnam(sys_get_temp_dir(), 'isptun_');
            if ($tmp === false) {
                throw new \RuntimeException('could not allocate temp key file');
            }

            if (!@copy($this->privateKeyPath, $tmp)) {
                @unlink($tmp);
                throw new \RuntimeException('could not copy key file');
            }

            // ssh refuses bad-perms keys (incl. Windows ACLs) — tighten BEFORE
            // ssh-keygen touches it and before it is ever used by ssh.
            $this->secureKeyFile($tmp);

            // Strip the passphrase in-place with OpenSSH tooling.
            $out = [];
            $rc = -1;
            @exec(
                'ssh-keygen -p -P ' . escapeshellarg($this->keyPassphrase)
                . ' -N "" -f ' . escapeshellarg($tmp) . ' 2>&1',
                $out,
                $rc
            );
            if ($rc !== 0) {
                @unlink($tmp);
                throw new \RuntimeException('ssh-keygen -p failed: ' . trim(implode(' ', $out)));
            }
            // ssh-keygen rewrote the file — re-assert perms.
            $this->secureKeyFile($tmp);

            Log::debug('[SshTunnelManager] using ssh-keygen-stripped passphrase-less key copy');
            return [$tmp, $tmp];
        } catch (\Throwable $e) {
            Log::warning('[SshTunnelManager] could not materialize passphrase-less key, ' .
                'falling back to original (ssh BatchMode will likely fail to auth)', [
                'error' => $e->getMessage(),
            ]);
            return [$this->privateKeyPath, null];
        }
    }

    /**
     * Lock a private-key file down so OpenSSH will accept it.
     *
     * POSIX: chmod 0600. Windows: OpenSSH enforces ACLs (not POSIX bits) and
     * rejects keys "accessible by others" — a temp file under %TEMP% inherits
     * broad ACEs, so we strip inheritance and grant ONLY the current user.
     */
    private function secureKeyFile(string $path): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod($path, 0600);
            return;
        }

        $me = trim((string) @shell_exec('whoami'));
        if ($me === '') {
            $me = (string) (getenv('USERDOMAIN') ? getenv('USERDOMAIN') . '\\' : '') . getenv('USERNAME');
        }

        // /inheritance:r removes inherited ACEs; /grant:r replaces with a single
        // ACE for the current user. Result: owner-only access -> OpenSSH-clean.
        @exec('icacls ' . escapeshellarg($path) . ' /inheritance:r /q 2>&1');
        @exec('icacls ' . escapeshellarg($path) . ' /grant:r ' . escapeshellarg($me . ':F') . ' /q 2>&1');
    }
}
