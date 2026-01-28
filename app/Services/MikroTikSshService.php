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
            if (preg_match('/(\S+)\s+l2tp\s+(\S+)\s+(\S+)/', $line, $matches)) {
                $connections[] = [
                    'name' => $matches[1],
                    'service' => 'l2tp',
                    'address' => $matches[2] ?? null,
                    'uptime' => $matches[3] ?? null,
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
     * Create PPP secret for new router
     */
    public function createPppSecret(string $username, string $password): array
    {
        $cmd = sprintf(
            '/ppp secret add name=%s password=%s service=l2tp profile=vpn-profile',
            escapeshellarg($username),
            escapeshellarg($password)
        );

        return $this->execute($cmd);
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

                if ($ssh->login($this->username, $key)) {
                    Log::info('[MikroTikSSH] Conectado con clave SSH');
                    return $ssh;
                }
            } catch (\Throwable $e) {
                Log::warning('[MikroTikSSH] Error con clave SSH, intentando password', [
                    'error' => $e->getMessage(),
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
