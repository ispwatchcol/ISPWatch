<?php

namespace App\Services\MikroTik;

use Illuminate\Support\Facades\Log;

/**
 * Suspension Manager
 * 
 * Handles client suspension/activation via IP address-lists on MikroTik routers.
 * Supports direct API and CORE ssh-exec methods.
 */
class SuspensionManager
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

    // ==================== CORE ROUTER OPERATIONS ====================

    /**
     * Add IP to suspended address-list on CORE
     */
    public function addSuspendedIp(string $ip, string $comment = ''): array
    {
        $cmd = sprintf(
            '/ip firewall address-list add list=ISPWATCH_SUSPENDIDOS address=%s comment="%s"',
            escapeshellarg($ip),
            addslashes($comment)
        );

        return $this->connectionManager->executeSsh($cmd);
    }

    /**
     * Remove IP from suspended address-list on CORE
     */
    public function removeSuspendedIp(string $ip): array
    {
        $removeCmd = sprintf(
            '/ip firewall address-list remove [find list=ISPWATCH_SUSPENDIDOS address=%s]',
            escapeshellarg($ip)
        );

        return $this->connectionManager->executeSsh($removeCmd);
    }

    // ==================== CLIENT ROUTER OPERATIONS ====================

    /**
     * Add IP to suspended address-list on CLIENT router via CORE
     */
    public function addSuspendedIpViaCore(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $suspendedIp,
        string $customerName,
        int $clientPort = 8728
    ): array {
        try {
            Log::info('[SuspensionManager] Agregando IP a lista de suspendidos en router cliente', [
                'client_ip' => $clientIp,
                'suspended_ip' => $suspendedIp,
                'customer' => $customerName,
            ]);

            // Try direct API first
            if ($this->connectionManager->tryDirectClientConnection($clientIp, $clientPort)) {
                $socket = $this->apiProtocol->connect($clientIp, $clientPort, 5);
                if ($socket) {
                    Log::info('[SuspensionManager] Conexión API directa al cliente exitosa');
                    return $this->addSuspendedIpDirectApi($socket, $clientUser, $clientPass, $suspendedIp, $customerName);
                }
            }

            Log::info('[SuspensionManager] API directa no disponible, usando CORE ssh-exec');
            return $this->addSuspendedIpViaCoreNetwork($clientIp, $clientUser, $clientPass, $suspendedIp, $customerName);

        } catch (\Throwable $e) {
            Log::error('[SuspensionManager] Error agregando IP suspendida via CORE', [
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Remove IP from suspended address-list on CLIENT router via CORE
     */
    public function removeSuspendedIpViaCore(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $suspendedIp,
        int $clientPort = 8728
    ): array {
        try {
            Log::info('[SuspensionManager] Removiendo IP de lista de suspendidos', [
                'client_ip' => $clientIp,
                'suspended_ip' => $suspendedIp,
            ]);

            // Try direct API first
            if ($this->connectionManager->tryDirectClientConnection($clientIp, $clientPort)) {
                $socket = $this->apiProtocol->connect($clientIp, $clientPort, 5);
                if ($socket) {
                    Log::info('[SuspensionManager] Conexión API directa al cliente exitosa');
                    return $this->removeSuspendedIpDirectApi($socket, $clientUser, $clientPass, $suspendedIp);
                }
            }

            Log::info('[SuspensionManager] API directa no disponible, usando CORE ssh-exec');
            return $this->removeSuspendedIpViaCoreNetwork($clientIp, $clientUser, $clientPass, $suspendedIp);

        } catch (\Throwable $e) {
            Log::error('[SuspensionManager] Error removiendo IP suspendida via CORE', [
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    // ==================== DIRECT API METHODS ====================

    private function addSuspendedIpDirectApi(
        $socket,
        string $clientUser,
        string $clientPass,
        string $suspendedIp,
        string $customerName
    ): array {
        try {
            if (!$this->apiProtocol->login($socket, $clientUser, $clientPass)) {
                $this->apiProtocol->close($socket);
                return ['success' => false, 'message' => 'Error de autenticación en router cliente'];
            }

            $this->apiProtocol->sendCommand($socket, '/ip/firewall/address-list/add', [
                '=list=ISPWATCH_SUSPENDIDOS',
                '=address=' . $suspendedIp,
                '=comment=Cliente: ' . $customerName,
            ]);
            $error = $this->apiProtocol->readUntilDoneWithError($socket);

            $this->apiProtocol->close($socket);

            if ($error && !str_contains($error, 'already have')) {
                return ['success' => false, 'message' => 'Error al agregar IP: ' . $error];
            }

            return [
                'success' => true,
                'method' => 'DIRECT_API',
                'message' => 'Cliente suspendido - IP agregada',
                'details' => ['ip' => $suspendedIp, 'customer' => $customerName],
            ];

        } catch (\Throwable $e) {
            @$this->apiProtocol->close($socket);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    private function removeSuspendedIpDirectApi(
        $socket,
        string $clientUser,
        string $clientPass,
        string $suspendedIp
    ): array {
        try {
            if (!$this->apiProtocol->login($socket, $clientUser, $clientPass)) {
                $this->apiProtocol->close($socket);
                return ['success' => false, 'message' => 'Error de autenticación en router cliente'];
            }

            // Find the entry
            $this->apiProtocol->sendCommand($socket, '/ip/firewall/address-list/print', [
                '?list=ISPWATCH_SUSPENDIDOS',
                '?address=' . $suspendedIp,
            ]);
            $records = $this->apiProtocol->readAllRecords($socket);

            if (empty($records)) {
                $this->apiProtocol->close($socket);
                return [
                    'success' => true,
                    'method' => 'DIRECT_API',
                    'message' => 'Cliente ya estaba activo (IP no en lista)',
                ];
            }

            // Remove each entry
            foreach ($records as $record) {
                $id = $record['.id'] ?? null;
                if ($id) {
                    $this->apiProtocol->sendCommand($socket, '/ip/firewall/address-list/remove', [
                        '=.id=' . $id,
                    ]);
                    $this->apiProtocol->readUntilDone($socket);
                }
            }

            $this->apiProtocol->close($socket);

            return [
                'success' => true,
                'method' => 'DIRECT_API',
                'message' => 'Cliente activado - IP removida',
                'details' => ['ip' => $suspendedIp],
            ];

        } catch (\Throwable $e) {
            @$this->apiProtocol->close($socket);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    // ==================== CORE SSH-EXEC METHODS ====================

    private function addSuspendedIpViaCoreNetwork(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $suspendedIp,
        string $customerName
    ): array {
        return $this->executeSshExecViaCore(
            $clientIp,
            $clientUser,
            $clientPass,
            "/ip firewall address-list add list=ISPWATCH_SUSPENDIDOS address={$suspendedIp} comment=\"Cliente: {$customerName}\"",
            'ispwatch_s_',
            'suspend',
            $suspendedIp
        );
    }

    private function removeSuspendedIpViaCoreNetwork(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $suspendedIp
    ): array {
        return $this->executeSshExecViaCore(
            $clientIp,
            $clientUser,
            $clientPass,
            "/ip firewall address-list remove [find list=ISPWATCH_SUSPENDIDOS address={$suspendedIp}]",
            'ispwatch_a_',
            'activate',
            $suspendedIp
        );
    }

    /**
     * Execute a command on client router via CORE ssh-exec
     */
    private function executeSshExecViaCore(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $command,
        string $scriptPrefix,
        string $action,
        string $targetIp
    ): array {
        try {
            $socket = $this->apiProtocol->connect(
                $this->connectionManager->getApiHost(),
                $this->connectionManager->getApiPort(),
                10
            );

            if (!$socket) {
                return ['success' => false, 'message' => 'No se pudo conectar al CORE via API'];
            }

            if (
                !$this->apiProtocol->login(
                    $socket,
                    $this->connectionManager->getApiUser(),
                    $this->connectionManager->getApiPass()
                )
            ) {
                $this->apiProtocol->close($socket);
                return ['success' => false, 'message' => 'Error de autenticación al CORE'];
            }

            // Build ssh-exec script
            $safePass = str_replace('"', '\\"', $clientPass);
            $scriptName = $scriptPrefix . substr(uniqid(), -6);
            $scriptSource = "/system ssh-exec address={$clientIp} user={$clientUser} password=\"{$safePass}\" command=\"" . addslashes($command) . "\"";

            Log::info("[SuspensionManager] ssh-exec {$action} command", [
                'script_name' => $scriptName,
                'client_ip' => $clientIp,
            ]);

            // Create script
            $this->apiProtocol->sendCommand($socket, '/system/script/add', [
                '=name=' . $scriptName,
                '=source=' . $scriptSource,
            ]);
            $addError = $this->apiProtocol->readUntilDoneWithError($socket);

            if ($addError) {
                $this->apiProtocol->close($socket);
                return ['success' => false, 'message' => 'Error creando script: ' . $addError];
            }

            // Run script
            $this->apiProtocol->sendCommand($socket, '/system/script/run', [
                '=number=' . $scriptName,
            ]);
            $runError = $this->apiProtocol->readUntilDoneWithError($socket);

            // Cleanup script
            $this->apiProtocol->sendCommand($socket, '/system/script/remove', [
                '=numbers=' . $scriptName,
            ]);
            $this->apiProtocol->readUntilDone($socket);

            $this->apiProtocol->close($socket);

            // Handle acceptable errors
            if ($runError && !str_contains($runError, 'already have') && !str_contains($runError, 'no such item')) {
                return [
                    'success' => false,
                    'method' => 'CORE_SSH_EXEC',
                    'message' => 'Error: ' . $runError,
                ];
            }

            return [
                'success' => true,
                'method' => 'CORE_SSH_EXEC',
                'message' => "Operación {$action} exitosa via CORE",
                'details' => ['ip' => $targetIp],
            ];

        } catch (\Throwable $e) {
            Log::error("[SuspensionManager] Error en {$action} via CORE", ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
}
