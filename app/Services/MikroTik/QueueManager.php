<?php

namespace App\Services\MikroTik;

use App\Services\MikroTik\Concerns\NormalizesRouterComment;
use Illuminate\Support\Facades\Log;

/**
 * Queue Manager
 * 
 * Handles Simple Queue operations on MikroTik routers.
 * Supports direct API and CORE ssh-exec methods.
 */
class QueueManager
{
    use NormalizesRouterComment;

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
        int $clientPort = 8728,
        ?string $secretName = null,
        ?string $comment = null
    ): array {
        // SECURITY (OWASP A03): targetIp is interpolated unquoted into the
        // RouterOS command (target=<ip>); reject anything that is not an IP so
        // it can never carry a script payload.
        if (filter_var(trim($targetIp), FILTER_VALIDATE_IP) === false) {
            return ['success' => false, 'message' => 'IP del cliente (target) inválida.'];
        }
        $targetIp = trim($targetIp);

        try {
            $fullName = trim("{$customerName} {$customerLastName}");

            Log::info('[QueueManager] Sincronizando Simple Queue', [
                'client_ip' => $clientIp,
                'target_ip' => $targetIp,
                'customer' => $fullName,
                'speed' => "$speedUp/$speedDown",
            ]);

            // Normalize speeds. 0 = unlimited in RouterOS simple queues.
            if (is_numeric($speedUp)) {
                $speedUp = (int) $speedUp === 0 ? 'unlimited' : $speedUp . 'M';
            }
            if (is_numeric($speedDown)) {
                $speedDown = (int) $speedDown === 0 ? 'unlimited' : $speedDown . 'M';
            }

            $maxLimit = "{$speedUp}/{$speedDown}";

            // Queue NAME mirrors the PPPoE secret name when one exists; the
            // COMMENT is the person's full name (operator request). Fallbacks
            // keep IP-only / non-PPPoE routers behaving as before.
            $queueName = ($secretName !== null && trim($secretName) !== '')
                ? trim($secretName)
                : $fullName;
            // COMMENT is the person's full name (operator request); RouterOS
            // doesn't render accents/ñ, so transliterate it to ASCII. The queue
            // NAME is left untouched so `find name=` lookups stay stable.
            $queueComment = $this->normalizeRouterComment($comment, $fullName);

            // 1. Try direct API to the client first.
            if ($this->connectionManager->tryDirectClientConnection($clientIp, $clientPort)) {
                $socket = $this->apiProtocol->connect($clientIp, $clientPort, 5);
                if ($socket) {
                    Log::info('[QueueManager] Conexión API directa al cliente exitosa');
                    $direct = $this->syncQueueDirectApi($socket, $clientUser, $clientPass, $targetIp, $queueName, $maxLimit, $queueComment);
                    if ($direct['success']) {
                        return $direct;
                    }
                    // A direct-API failure must NOT be terminal — clients are
                    // usually behind the L2TP tunnel. Fall through to the CORE
                    // SSH path (mirrors the working profile/secret flow).
                    Log::warning('[QueueManager] API directa falló, usando CORE SSH', [
                        'reason' => $direct['message'] ?? 'unknown',
                    ]);
                }
            }

            // 2. CORE SSH direct: SSH into CORE and ssh-exec into the client —
            //    the SAME proven path profile/secret use (~17s). NOT the CORE
            //    API-script tier, which times out (~60s).
            Log::info('[QueueManager] API directa no disponible, usando CORE SSH');
            return $this->syncQueueViaCoreDirectSsh($clientIp, $clientUser, $clientPass, $targetIp, $queueName, $maxLimit, $queueComment);

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
        string $maxLimit,
        string $comment = 'ISPWatch Auto-Provisioned'
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
                    '=comment=' . $comment,
                ]);
            } else {
                Log::info('[QueueManager] Creando nueva Simple Queue');
                $this->apiProtocol->sendCommand($socket, '/queue/simple/add', [
                    '=name=' . $queueName,
                    '=target=' . $targetIp,
                    '=max-limit=' . $maxLimit,
                    '=comment=' . $comment,
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
     * Sync queue via CORE SSH direct: SSH into the CORE and run a single
     * ssh-exec into the client. Mirrors PppProfileManager::secretViaCoreDirectSsh
     * (the proven ~17s path). Single-level `:do { add } on-error={}; set [find]`
     * command shape — no /system/script (API-script times out ~60s) and no
     * nested on-error / :put / :if (escape-fragile over ssh-exec).
     */
    private function syncQueueViaCoreDirectSsh(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $targetIp,
        string $queueName,
        string $maxLimit,
        string $comment = 'ISPWatch Auto-Provisioned'
    ): array {
        try {
            $clientCommand = $this->buildQueueCommand($targetIp, $queueName, $maxLimit, $comment);
            $safePass      = str_replace('"', '\\"', $clientPass);
            $coreCommand   = "/system ssh-exec address={$clientIp} user={$clientUser} password=\"{$safePass}\" command=\"" . addslashes($clientCommand) . "\"";

            Log::info('[QueueManager] CORE SSH direct: ssh-exec queue', [
                'client_ip' => $clientIp,
                'target'    => $targetIp,
            ]);

            $result = $this->connectionManager->executeSsh($coreCommand);

            if (!$result['success']) {
                return ['success' => false, 'method' => 'CORE_SSH_DIRECT', 'message' => $result['message'] ?? 'No se pudo conectar al CORE via SSH'];
            }

            $output = trim((string) ($result['output'] ?? ''));

            if ($output && preg_match('/\berror\b|\bfailure\b|\bcannot\b|\brefused\b|no such item|match any value/i', $output)) {
                return [
                    'success'    => false,
                    'method'     => 'CORE_SSH_DIRECT',
                    'definitive' => true,
                    'message'    => 'No se pudo crear/actualizar la queue. Detalle del router: ' . $output,
                ];
            }

            Log::info('[QueueManager] Queue creada/actualizada via CORE SSH', ['target' => $targetIp]);

            return [
                'success'    => true,
                'method'     => 'CORE_SSH_DIRECT',
                'action'     => 'upserted',
                'message'    => 'Queue sincronizada via CORE',
                'queue_name' => $queueName,
                'max_limit'  => $maxLimit,
            ];
        } catch (\Throwable $e) {
            Log::error('[QueueManager] CORE SSH queue exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'method' => 'CORE_SSH_DIRECT', 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Build the idempotent /queue simple command in the proven safe shape:
     * try add (ignore if it exists), then unconditionally set [find target].
     */
    private function buildQueueCommand(string $targetIp, string $queueName, string $maxLimit, string $comment = 'ISPWatch Auto-Provisioned'): string
    {
        $name    = $this->escapeRouterOsQuotedValue($queueName);
        $limit   = $this->escapeRouterOsQuotedValue($maxLimit);
        $comm    = $this->escapeRouterOsQuotedValue($comment);
        $args  = ' max-limit="' . $limit . '" comment="' . $comm . '"';

        $addCmd = '/queue simple add name="' . $name . '" target=' . $targetIp . $args;
        $setCmd = '/queue simple set [find target=' . $targetIp . '/32] name="' . $name . '"' . $args;

        return ':do { ' . $addCmd . ' } on-error={}; ' . $setCmd;
    }

    private function escapeRouterOsQuotedValue(string $value): string
    {
        // SECURITY (OWASP A03): strip control chars / newlines, then escape
        // RouterOS quoted-string metacharacters — backslash, double-quote and
        // $ (variable/command substitution inside "...").
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        return addcslashes($value, "\\\"\$");
    }
}
