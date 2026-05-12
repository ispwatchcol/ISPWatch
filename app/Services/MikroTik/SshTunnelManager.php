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
    private float $readyTimeoutSeconds;
    private bool $enabled;

    public function __construct()
    {
        $this->coreHost       = env('MIKROTIK_CORE_SSH_HOST', '167.172.132.234');
        $this->corePort       = (int) env('MIKROTIK_CORE_SSH_PORT', 22);
        $this->coreUser       = env('MIKROTIK_CORE_SSH_USER', 'admin');
        $this->privateKeyPath = env('MIKROTIK_CORE_SSH_KEY_PATH', storage_path('keys/mikrotik_core_id_ed25519'));
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

        $localPort = $this->findFreeLocalPort();
        $cmd       = $this->buildSshCommand($clientIp, $clientPort, $localPort);

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

        $tunnel = new SshTunnel($process, $pipes, $localPort, $clientIp, $clientPort);

        try {
            $this->waitUntilReady($tunnel);
        } catch (\Throwable $e) {
            $tunnel->close();
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

    private function buildSshCommand(string $clientIp, int $clientPort, int $localPort): string
    {
        $forward = '127.0.0.1:' . $localPort . ':' . $clientIp . ':' . $clientPort;

        $parts = [
            'ssh',
            '-i', escapeshellarg($this->privateKeyPath),
            '-o', escapeshellarg('StrictHostKeyChecking=no'),
            '-o', escapeshellarg('UserKnownHostsFile=/dev/null'),
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
                    "SSH tunnel process exited before becoming ready" .
                    ($stderr !== '' ? ". stderr: {$stderr}" : '')
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
            "SSH tunnel to {$tunnel->clientIp()}:{$tunnel->clientPort()} " .
            "did not become ready within {$this->readyTimeoutSeconds}s on " .
            "127.0.0.1:{$tunnel->localPort()}. Last probe error: {$lastError}" .
            ($stderr !== '' ? ". ssh stderr: {$stderr}" : '')
        );
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

        $perms = @fileperms($this->privateKeyPath);
        if ($perms !== false && ($perms & 0077) !== 0) {
            // ssh refuses world/group-readable keys. Try to fix on the fly.
            @chmod($this->privateKeyPath, 0600);
        }
    }
}
