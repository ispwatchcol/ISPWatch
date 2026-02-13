<?php

namespace App\Services\MikroTik;

use Illuminate\Support\Facades\Log;

/**
 * Queue Manager
 * 
 * Handles Simple Queue operations on MikroTik routers.
 * Supports direct API and CORE ssh-exec methods.
 */
class QueueManager
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

    /**
     * Sync Simple Queue on client router via API
     */
    public function syncQueueViaCore(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $targetIp,
        string $customerName,
        string $customerLastName,
        string $speedUp,
        string $speedDown,
        int $clientPort = 8728
    ): array {
        try {
            Log::info('[QueueManager] Sincronizando Simple Queue', [
                'client_ip' => $clientIp,
                'target_ip' => $targetIp,
                'customer' => "$customerName $customerLastName",
                'speed' => "$speedUp/$speedDown",
            ]);

            // Normalize speeds
            if (is_numeric($speedUp)) {
                $speedUp = $speedUp . 'M';
            }
            if (is_numeric($speedDown)) {
                $speedDown = $speedDown . 'M';
            }

            $maxLimit = "{$speedUp}/{$speedDown}";
            $queueName = "{$customerName} {$customerLastName}";

            // Try direct API first
            if ($this->connectionManager->tryDirectClientConnection($clientIp, $clientPort)) {
                $socket = $this->apiProtocol->connect($clientIp, $clientPort, 5);
                if ($socket) {
                    Log::info('[QueueManager] Conexión API directa al cliente exitosa');
                    return $this->syncQueueDirectApi($socket, $clientUser, $clientPass, $targetIp, $queueName, $maxLimit);
                }
            }

            Log::info('[QueueManager] API directa no disponible, usando CORE');
            return $this->syncQueueViaCoreNetwork($clientIp, $clientPort, $clientUser, $clientPass, $targetIp, $queueName, $maxLimit);

        } catch (\Throwable $e) {
            Log::error('[QueueManager] Error sincronizando queue', [
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Sync queue using direct API connection
     */
    private function syncQueueDirectApi(
        $socket,
        string $clientUser,
        string $clientPass,
        string $targetIp,
        string $queueName,
        string $maxLimit
    ): array {
        try {
            if (!$this->apiProtocol->login($socket, $clientUser, $clientPass)) {
                $this->apiProtocol->close($socket);
                return ['success' => false, 'message' => 'Error de autenticación en router cliente'];
            }

            // Search for existing queue by target IP
            $this->apiProtocol->sendCommand($socket, '/queue/simple/print', [
                '?target=' . $targetIp . '/32',
                '=.proplist=.id,name,target,max-limit',
            ]);
            $records = $this->apiProtocol->readAllRecords($socket);

            $existingId = null;
            if (!empty($records)) {
                $existingId = $records[0]['.id'] ?? null;
            } else {
                // Try by name
                $this->apiProtocol->sendCommand($socket, '/queue/simple/print', [
                    '?name=' . $queueName,
                    '=.proplist=.id,name,target,max-limit',
                ]);
                $records = $this->apiProtocol->readAllRecords($socket);
                if (!empty($records)) {
                    $existingId = $records[0]['.id'] ?? null;
                }
            }

            if ($existingId) {
                Log::info('[QueueManager] Actualizando Simple Queue existente', ['id' => $existingId]);
                $this->apiProtocol->sendCommand($socket, '/queue/simple/set', [
                    '=.id=' . $existingId,
                    '=name=' . $queueName,
                    '=target=' . $targetIp,
                    '=max-limit=' . $maxLimit,
                    '=comment=ISPWatch Auto-Provisioned',
                ]);
            } else {
                Log::info('[QueueManager] Creando nueva Simple Queue');
                $this->apiProtocol->sendCommand($socket, '/queue/simple/add', [
                    '=name=' . $queueName,
                    '=target=' . $targetIp,
                    '=max-limit=' . $maxLimit,
                    '=comment=ISPWatch Auto-Provisioned',
                ]);
            }

            $error = $this->apiProtocol->readUntilDoneWithError($socket);
            $this->apiProtocol->close($socket);

            if ($error) {
                return ['success' => false, 'message' => 'Error al crear/actualizar queue: ' . $error];
            }

            return [
                'success' => true,
                'method' => 'DIRECT_API',
                'message' => 'Queue sincronizada correctamente',
                'action' => $existingId ? 'updated' : 'created',
                'queue_name' => $queueName,
                'target' => $targetIp,
                'max_limit' => $maxLimit,
            ];

        } catch (\Throwable $e) {
            @$this->apiProtocol->close($socket);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Sync queue via CORE ssh-exec
     */
    private function syncQueueViaCoreNetwork(
        string $clientIp,
        int $clientPort,
        string $clientUser,
        string $clientPass,
        string $targetIp,
        string $queueName,
        string $maxLimit
    ): array {
        try {
            $socket = $this->apiProtocol->connect(
                $this->connectionManager->getApiHost(),
                $this->connectionManager->getApiPort(),
                10
            );

            if (!$socket) {
                return ['success' => false, 'message' => 'No se pudo conectar al CORE'];
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

            // Build command
            $safePass = str_replace('"', '\\"', $clientPass);
            $safeQueueName = str_replace('"', '\\"', $queueName);

            // First remove existing queue, then add new one
            $removeCmd = "/queue simple remove [find target={$targetIp}/32]";
            $addCmd = "/queue simple add name=\"{$safeQueueName}\" target={$targetIp} max-limit={$maxLimit} comment=\"ISPWatch Auto-Provisioned\"";

            // Execute remove
            $scriptName = 'ispwatch_q_' . substr(uniqid(), -6);
            $scriptSource = "/system ssh-exec address={$clientIp} user={$clientUser} password=\"{$safePass}\" command=\"{$removeCmd}\"";

            $this->apiProtocol->sendCommand($socket, '/system/script/add', [
                '=name=' . $scriptName,
                '=source=' . $scriptSource,
            ]);
            $this->apiProtocol->readUntilDone($socket);

            $this->apiProtocol->sendCommand($socket, '/system/script/run', [
                '=number=' . $scriptName,
            ]);
            $this->apiProtocol->readUntilDone($socket);

            $this->apiProtocol->sendCommand($socket, '/system/script/remove', [
                '=numbers=' . $scriptName,
            ]);
            $this->apiProtocol->readUntilDone($socket);

            // Execute add
            $scriptName2 = 'ispwatch_qa_' . substr(uniqid(), -6);
            $scriptSource2 = "/system ssh-exec address={$clientIp} user={$clientUser} password=\"{$safePass}\" command=\"" . addslashes($addCmd) . "\"";

            $this->apiProtocol->sendCommand($socket, '/system/script/add', [
                '=name=' . $scriptName2,
                '=source=' . $scriptSource2,
            ]);
            $addError = $this->apiProtocol->readUntilDoneWithError($socket);

            if ($addError) {
                $this->apiProtocol->close($socket);
                return ['success' => false, 'message' => 'Error creando script: ' . $addError];
            }

            $this->apiProtocol->sendCommand($socket, '/system/script/run', [
                '=number=' . $scriptName2,
            ]);
            $runError = $this->apiProtocol->readUntilDoneWithError($socket);

            $this->apiProtocol->sendCommand($socket, '/system/script/remove', [
                '=numbers=' . $scriptName2,
            ]);
            $this->apiProtocol->readUntilDone($socket);

            $this->apiProtocol->close($socket);

            if ($runError && !str_contains($runError, 'no such item')) {
                return ['success' => false, 'method' => 'CORE_SSH_EXEC', 'message' => 'Error: ' . $runError];
            }

            return [
                'success' => true,
                'method' => 'CORE_SSH_EXEC',
                'message' => 'Queue sincronizada via CORE',
                'queue_name' => $queueName,
                'max_limit' => $maxLimit,
            ];

        } catch (\Throwable $e) {
            Log::error('[QueueManager] Error en syncQueueViaCoreNetwork', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
}
