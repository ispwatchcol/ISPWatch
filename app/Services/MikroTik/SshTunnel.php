<?php

namespace App\Services\MikroTik;

use Illuminate\Support\Facades\Log;

/**
 * Handle to a running `ssh -L` subprocess that forwards 127.0.0.1:$localPort
 * to $clientIp:$clientPort through the MikroTik CORE.
 *
 * Always close via close() or by letting the handle go out of scope —
 * the destructor reaps the subprocess so we don't leak ssh processes.
 */
class SshTunnel
{
    /** @var resource|null proc_open process */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    private int $localPort;
    private string $clientIp;
    private int $clientPort;
    private bool $closed = false;

    public function __construct($process, array $pipes, int $localPort, string $clientIp, int $clientPort)
    {
        $this->process    = $process;
        $this->pipes      = $pipes;
        $this->localPort  = $localPort;
        $this->clientIp   = $clientIp;
        $this->clientPort = $clientPort;
    }

    public function __destruct()
    {
        if (!$this->closed) {
            try {
                $this->close();
            } catch (\Throwable $e) {
                // Destructors must not throw.
            }
        }
    }

    public function localHost(): string
    {
        return '127.0.0.1';
    }

    public function localPort(): int
    {
        return $this->localPort;
    }

    public function clientIp(): string
    {
        return $this->clientIp;
    }

    public function clientPort(): int
    {
        return $this->clientPort;
    }

    public function isRunning(): bool
    {
        if (!is_resource($this->process)) {
            return false;
        }
        $status = @proc_get_status($this->process);
        return is_array($status) && !empty($status['running']);
    }

    public function status(): array
    {
        if (!is_resource($this->process)) {
            return ['running' => false, 'exitcode' => -1, 'pid' => null];
        }
        return proc_get_status($this->process);
    }

    /**
     * Read and return whatever ssh has written to stderr so far.
     * Non-blocking. Useful for surfacing the real reason a tunnel failed
     * (auth rejected, host unreachable, port already in use, etc.).
     */
    public function drainStderr(): string
    {
        if (!isset($this->pipes[2]) || !is_resource($this->pipes[2])) {
            return '';
        }
        $data = '';
        while (true) {
            $chunk = @fread($this->pipes[2], 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        return $data;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        $stderr = $this->drainStderr();
        if ($stderr !== '') {
            Log::debug('[SshTunnel] stderr on close', [
                'localPort' => $this->localPort,
                'clientIp'  => $this->clientIp,
                'stderr'    => trim($stderr),
            ]);
        }

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        $this->pipes = [];

        if (!is_resource($this->process)) {
            return;
        }

        $status = @proc_get_status($this->process);
        if (is_array($status) && !empty($status['running'])) {
            @proc_terminate($this->process, 15); // SIGTERM

            // Wait up to 500ms for a clean exit.
            for ($i = 0; $i < 25; $i++) {
                $s = @proc_get_status($this->process);
                if (!is_array($s) || empty($s['running'])) {
                    break;
                }
                usleep(20000);
            }

            $s = @proc_get_status($this->process);
            if (is_array($s) && !empty($s['running'])) {
                @proc_terminate($this->process, 9); // SIGKILL
                if (function_exists('posix_kill') && !empty($s['pid'])) {
                    @posix_kill($s['pid'], 9);
                }
            }
        }

        @proc_close($this->process);
        $this->process = null;

        Log::debug('[SshTunnel] closed', [
            'localPort' => $this->localPort,
            'clientIp'  => $this->clientIp,
        ]);
    }
}
