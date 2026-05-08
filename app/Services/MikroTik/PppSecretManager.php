<?php

namespace App\Services\MikroTik;

use Illuminate\Support\Facades\Log;

/**
 * PPP Secret Manager
 * 
 * Handles VPN user management: creating/updating PPP secrets,
 * checking active connections, and verifying VPN status.
 */
class PppSecretManager
{
    private MikroTikConnectionManager $connectionManager;
    private MikroTikApiProtocol $apiProtocol;

    public function __construct(
        ?MikroTikConnectionManager $connectionManager = null,
        ?MikroTikApiProtocol $apiProtocol = null
    ) {
        $this->connectionManager = $connectionManager ?? new MikroTikConnectionManager();
        $this->apiProtocol = $apiProtocol ?? $this->connectionManager->getApiProtocol();
    }

    // ==================== PPP ACTIVE CONNECTIONS ====================

    /**
     * Get PPP active connections (VPN status)
     * Uses API first, SSH as fallback
     */
    public function getPppActive(): array
    {
        $apiResult = $this->getPppActiveViaApi();
        if ($apiResult['success']) {
            return $apiResult;
        }

        Log::info('[PppSecretManager] API failed for getPppActive, trying SSH', [
            'api_error' => $apiResult['message'] ?? 'unknown'
        ]);

        return $this->getPppActiveViaSsh();
    }

    /**
     * Get PPP active connections via API
     */
    private function getPppActiveViaApi(): array
    {
        try {
            $socket = $this->apiProtocol->connect(
                $this->connectionManager->getApiHost(),
                $this->connectionManager->getApiPort(),
                10
            );

            if (!$socket) {
                return ['success' => false, 'message' => 'API connection failed'];
            }

            if (
                !$this->apiProtocol->login(
                    $socket,
                    $this->connectionManager->getApiUser(),
                    $this->connectionManager->getApiPass()
                )
            ) {
                $this->apiProtocol->close($socket);
                return ['success' => false, 'message' => 'API authentication failed'];
            }

            $this->apiProtocol->sendCommand($socket, '/ppp/active/print');
            $records = $this->apiProtocol->readAllRecords($socket);

            $this->apiProtocol->close($socket);

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
        $result = $this->connectionManager->executeSsh('/ppp active print');

        if (!$result['success']) {
            return $result;
        }

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
    public function ensurePppSecret(
        string $username,
        string $password,
        string $service = 'l2tp',
        string $profile = 'default',
        string $comment = 'ISPWatch Auto'
    ): array {
        $apiResult = $this->ensurePppSecretViaApi($username, $password, $service, $profile, $comment);

        if (!$apiResult['success']) {
            Log::warning('[PppSecretManager] API failed for ensurePppSecret', [
                'api_error' => $apiResult['message'] ?? 'unknown',
                'username' => $username,
            ]);
        }

        return $apiResult;
    }

    /**
     * Create or Update PPP secret via API
     */
    private function ensurePppSecretViaApi(
        string $username,
        string $password,
        string $service,
        string $profile,
        string $comment = 'ISPWatch Auto'
    ): array {
        try {
            Log::info('[PppSecretManager] Intentando crear/actualizar secret via API', [
                'username' => $username,
            ]);

            $socket = $this->apiProtocol->connect(
                $this->connectionManager->getApiHost(),
                $this->connectionManager->getApiPort(),
                10
            );

            if (!$socket) {
                return ['success' => false, 'message' => 'API connection failed'];
            }

            if (
                !$this->apiProtocol->login(
                    $socket,
                    $this->connectionManager->getApiUser(),
                    $this->connectionManager->getApiPass()
                )
            ) {
                $this->apiProtocol->close($socket);
                return ['success' => false, 'message' => 'API authentication failed'];
            }

            // Check if secret exists
            $this->apiProtocol->sendCommand($socket, '/ppp/secret/print', [
                '?name=' . $username,
            ]);
            $existing = $this->apiProtocol->readAllRecords($socket);

            $action = 'unknown';
            $apiError = null;

            if (!empty($existing)) {
                // Update existing
                $secretId = $existing[0]['.id'] ?? null;
                if ($secretId) {
                    Log::info('[PppSecretManager] Actualizando secret existente', ['id' => $secretId]);
                    $this->apiProtocol->sendCommand($socket, '/ppp/secret/set', [
                        '=.id=' . $secretId,
                        '=password=' . $password,
                        '=service=' . $service,
                        '=profile=' . $profile,
                    ]);
                    $apiError = $this->apiProtocol->readUntilDoneWithError($socket);
                    $action = 'updated';
                }
            } else {
                // Create new
                Log::info('[PppSecretManager] Creando nuevo secret', ['username' => $username]);
                $this->apiProtocol->sendCommand($socket, '/ppp/secret/add', [
                    '=name=' . $username,
                    '=password=' . $password,
                    '=service=' . $service,
                    '=comment=' . $comment,
                ]);
                $apiError = $this->apiProtocol->readUntilDoneWithError($socket);
                $action = 'created';
            }

            if ($apiError) {
                $this->apiProtocol->close($socket);
                return [
                    'success' => false,
                    'message' => "API error: $apiError",
                    'action' => $action,
                ];
            }

            usleep(100000); // 100ms pause

            // Verify creation
            $this->apiProtocol->sendCommand($socket, '/ppp/secret/print', [
                '?name=' . $username,
            ]);
            $verification = $this->apiProtocol->readAllRecords($socket);

            $this->apiProtocol->close($socket);

            if (empty($verification)) {
                return [
                    'success' => false,
                    'message' => 'Secret no encontrado después de creación/actualización',
                    'action' => $action,
                ];
            }

            Log::info('[PppSecretManager] PPP secret gestionado exitosamente', [
                'username' => $username,
                'action' => $action,
            ]);

            return [
                'success' => true,
                'method' => 'API',
                'action' => $action,
                'message' => "Secret {$action} successfully via API",
                'verified' => true,
            ];

        } catch (\Throwable $e) {
            Log::error('[PppSecretManager] Exception managing PPP secret', [
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Create or Update PPP secret via SSH (fallback method)
     */
    public function ensurePppSecretViaSsh(
        string $username,
        string $password,
        string $service,
        string $profile,
        string $comment = 'ISPWatch Auto'
    ): array {
        $current = $this->getPppSecret($username);

        if (!$current['success']) {
            return $current;
        }

        if ($current['found']) {
            $cmd = sprintf(
                '/ppp secret set [find name=%s] password=%s service=%s profile=%s',
                escapeshellarg($username),
                escapeshellarg($password),
                escapeshellarg($service),
                escapeshellarg($profile)
            );
            $action = "updated";
        } else {
            $cmd = sprintf(
                '/ppp secret add name=%s password=%s service=%s profile=%s comment="%s"',
                escapeshellarg($username),
                escapeshellarg($password),
                escapeshellarg($service),
                escapeshellarg($profile),
                addslashes($comment)
            );
            $action = "created";
        }

        $result = $this->connectionManager->executeSsh($cmd);

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
        $result = $this->connectionManager->executeSsh($cmd);

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

    /**
     * Create PPP secret for new router (Legacy wrapper)
     */
    public function createPppSecret(string $username, string $password): array
    {
        return $this->ensurePppSecret($username, $password);
    }
}
