<?php

namespace App\Services\MikroTik;

use App\Services\MikroTik\Concerns\DetectsSshExecFailures;
use App\Services\MikroTik\Concerns\NormalizesRouterComment;
use App\Services\MikroTik\Concerns\VerifiesRouterOsObjectState;
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
    use DetectsSshExecFailures;
    use VerifiesRouterOsObjectState;

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

            // 1. Try direct API to the client THROUGH the CORE SSH tunnel.
            //    connectClientApi() opens the local-forward tunnel AND logs in;
            //    the old tryDirectClientConnection() + connect($clientIp,...)
            //    direct never completed in production (overlay IP not routable).
            $socket = $this->connectionManager->connectClientApi($clientIp, $clientPort, $clientUser, $clientPass);
            if ($socket) {
                Log::info('[QueueManager] Conexión API directa al cliente exitosa (via túnel CORE)');
                $direct = $this->syncQueueDirectApi($socket, $targetIp, $queueName, $maxLimit, $queueComment);
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
        string $targetIp,
        string $queueName,
        string $maxLimit,
        string $comment = 'ISPWatch Auto-Provisioned'
    ): array {
        try {
            // Socket is already authenticated by connectClientApi — go straight to operations.
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
            $this->connectionManager->closeClientApi($socket);

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
            $this->connectionManager->closeClientApi($socket);
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
        $stepStart = microtime(true);
        try {
            $clientCommand = $this->buildQueueCommand($targetIp, $queueName, $maxLimit, $comment);
            $safePass      = str_replace('"', '\\"', $clientPass);
            $coreCommand   = "/system ssh-exec address={$clientIp} user={$clientUser} password=\"{$safePass}\" command=\"" . addslashes($clientCommand) . "\"";

            $this->logProvisionStep('QueueManager', 'queue_add_set_start', [
                'client_ip' => $clientIp,
                'target'    => $targetIp,
                'name'      => $queueName,
            ]);

            $result = $this->connectionManager->executeSsh($coreCommand);

            if (!$result['success']) {
                $this->logProvisionStep('QueueManager', 'queue_add_set_end', ['target' => $targetIp, 'outcome' => 'core_ssh_failed'], $stepStart);
                return ['success' => false, 'method' => 'CORE_SSH_DIRECT', 'message' => $result['message'] ?? 'No se pudo conectar al CORE via SSH'];
            }

            $output = trim((string) ($result['output'] ?? ''));

            // CORE→client SSH connection failure — nothing ran on the client.
            if ($output && $this->isSshExecConnectionFailure($output)) {
                $this->logProvisionStep('QueueManager', 'queue_add_set_end', ['target' => $targetIp, 'outcome' => 'connection_failure'], $stepStart);
                return [
                    'success' => false,
                    'method'  => 'CORE_SSH_DIRECT',
                    'message' => $this->sshExecConnectionFailureMessage($clientIp, $output),
                ];
            }

            if ($output && preg_match('/\berror\b|\bfailure\b|\bcannot\b|\brefused\b|no such item|match any value/i', $output)) {
                $this->logProvisionStep('QueueManager', 'queue_add_set_end', ['target' => $targetIp, 'outcome' => 'router_error'], $stepStart);
                return [
                    'success'    => false,
                    'method'     => 'CORE_SSH_DIRECT',
                    'definitive' => true,
                    'message'    => 'No se pudo crear/actualizar la queue. Detalle del router: ' . $output,
                ];
            }

            $this->logProvisionStep('QueueManager', 'queue_add_set_end', ['target' => $targetIp, 'outcome' => 'no_error_output'], $stepStart);

            // Sin salida de error no significa éxito: el `add` puede haber
            // fallado por colisión de NOMBRE (on-error={} lo traga) y el `set
            // [find target=...]` no encuentra nada que tocar porque este
            // target es nuevo — un no-op silencioso que antes se reportaba
            // como éxito. Confirmamos con un `print count-only` aparte.
            $verifyStart = microtime(true);
            $this->logProvisionStep('QueueManager', 'queue_verify_start', ['target' => $targetIp, 'name' => $queueName]);

            $verification = $this->verifyQueueExists($clientIp, $clientUser, $clientPass, $targetIp, $queueName);

            $this->logProvisionStep('QueueManager', 'queue_verify_end', [
                'target' => $targetIp,
                'outcome' => $verification['success'] ? 'confirmed' : 'not_confirmed',
            ], $verifyStart);

            return $verification + ['queue_name' => $queueName, 'max_limit' => $maxLimit];
        } catch (\Throwable $e) {
            Log::error('[QueueManager] CORE SSH queue exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'method' => 'CORE_SSH_DIRECT', 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Confirma que la queue realmente existe con el NOMBRE y el TARGET
     * esperados tras el add+set. Si no, distingue "colisión de nombre con
     * otro target" (mensaje accionable) de "no se creó ni se encontró nada"
     * (falla silenciosa genérica) y de "no se pudo verificar" (el router dejó
     * de responder justo entre el set y el print).
     */
    private function verifyQueueExists(string $clientIp, string $clientUser, string $clientPass, string $targetIp, string $queueName): array
    {
        $escapedName = $this->escapeRouterOsQuotedValue($queueName);

        $byTargetAndName = $this->verifyRouterOsObject(
            $clientIp, $clientUser, $clientPass,
            '/queue simple print count-only where target="' . $targetIp . '/32" and name="' . $escapedName . '"'
        );

        if (!$byTargetAndName['connection_ok']) {
            return ['success' => false, 'method' => 'CORE_SSH_DIRECT', 'message' => $byTargetAndName['message']];
        }

        if ($this->parseCountOnly($byTargetAndName['output']) >= 1) {
            return ['success' => true, 'method' => 'CORE_SSH_DIRECT', 'action' => 'upserted', 'message' => 'Queue sincronizada via CORE'];
        }

        // No existe con ese target+nombre. ¿Existe el nombre en OTRO target?
        // (colisión) o no existe en absoluto (falla silenciosa genérica).
        $byNameOnly = $this->verifyRouterOsObject(
            $clientIp, $clientUser, $clientPass,
            '/queue simple print count-only where name="' . $escapedName . '"'
        );

        if (!$byNameOnly['connection_ok']) {
            return ['success' => false, 'method' => 'CORE_SSH_DIRECT', 'message' => $byNameOnly['message']];
        }

        if ($this->parseCountOnly($byNameOnly['output']) >= 1) {
            return [
                'success'    => false,
                'method'     => 'CORE_SSH_DIRECT',
                'definitive' => true,
                'message'    => "Ya existe una queue con el nombre \"{$queueName}\" asociada a OTRO destino IP en este router — colisión de nombre. Revisa manualmente en el router (probable pppoe_username duplicado).",
            ];
        }

        return [
            'success'    => false,
            'method'     => 'CORE_SSH_DIRECT',
            'definitive' => true,
            'message'    => "No se pudo crear ni encontrar la queue \"{$queueName}\" tras el intento — falla silenciosa del comando. Revisa permisos del usuario de gestión sobre /queue simple o la versión de RouterOS.",
        ];
    }

    private function parseCountOnly(?string $output): int
    {
        if ($output === null || $output === '') {
            return 0;
        }
        return (int) trim(preg_replace('/\D/', '', $output) ?? '0');
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
