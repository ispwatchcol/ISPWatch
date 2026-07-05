<?php

namespace App\Services\MikroTik;

use App\Services\MikroTik\Concerns\DetectsSshExecFailures;
use App\Services\MikroTik\Concerns\NormalizesRouterComment;
use Illuminate\Support\Facades\Log;

/**
 * PPP Profile Manager
 *
 * Handles creating or updating PPP profiles on client MikroTik routers.
 * Supports direct API first and CORE ssh-exec as fallback.
 */
class PppProfileManager
{
    use NormalizesRouterComment;
    use DetectsSshExecFailures;

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
     * Create or update a PPP profile on the client router.
     */
    public function syncPppoeProfile(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $profileName,
        string $speedUp,
        string $speedDown,
        ?string $localAddress = null,
        ?string $remoteAddress = null,
        int $clientPort = 8728
    ): array {
        try {
            $rateLimit = $this->buildRateLimit($speedUp, $speedDown);

            Log::info('[PppProfileManager] Syncing PPP profile', [
                'client_ip'     => $clientIp,
                'profile'       => $profileName,
                'rate_limit'    => $rateLimit,
                'local_address' => $localAddress,
                'remote_address'=> $remoteAddress,
            ]);

            // 1. Try direct API to the client THROUGH the CORE SSH tunnel.
            //    connectClientApi() opens the local-forward tunnel AND logs in,
            //    returning an authenticated socket. The old code probed with
            //    tryDirectClientConnection() and then connect($clientIp,...)
            //    DIRECTLY — which never completes in production because the
            //    overlay IP isn't routable from the app server, so this API tier
            //    was dead weight and every push fell back to CORE ssh-exec.
            $socket = $this->connectionManager->connectClientApi($clientIp, $clientPort, $clientUser, $clientPass);
            if ($socket) {
                Log::info('[PppProfileManager] Direct API connection available (via CORE tunnel)');
                $result = $this->syncDirectApi($socket, $profileName, $rateLimit, $localAddress, $remoteAddress);
                if ($result['success']) {
                    return $result;
                }
                Log::warning('[PppProfileManager] Direct API failed', ['reason' => $result['message'] ?? 'unknown']);
            }

            // 2. Try SSH directly on CORE (simpler and more reliable than API-script approach)
            Log::info('[PppProfileManager] Direct API unavailable, trying CORE SSH');
            $sshResult = $this->syncViaCoreDirectSsh($clientIp, $clientUser, $clientPass, $profileName, $rateLimit, $localAddress, $remoteAddress);
            if ($sshResult['success']) {
                return $sshResult;
            }
            Log::warning('[PppProfileManager] CORE SSH failed, trying CORE API script fallback', ['reason' => $sshResult['message'] ?? 'unknown']);

            // 3. Last resort: CORE API script with ssh-exec
            return $this->syncViaCore($clientIp, $clientUser, $clientPass, $profileName, $rateLimit, $localAddress, $remoteAddress);

        } catch (\Throwable $e) {
            Log::error('[PppProfileManager] Error syncing PPP profile', [
                'profile' => $profileName,
                'error'   => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    private function syncDirectApi(
        $socket,
        string $profileName,
        string $rateLimit,
        ?string $localAddress,
        ?string $remoteAddress
    ): array {
        try {
            // Socket is already authenticated by connectClientApi — go straight to operations.
            $this->apiProtocol->sendCommand($socket, '/ppp/profile/print', [
                '?name=' . $profileName,
                '=.proplist=.id,name',
            ]);
            $records = $this->apiProtocol->readAllRecords($socket);

            $existingId = $records[0]['.id'] ?? null;
            $params = $this->buildApiParams($profileName, $rateLimit, $localAddress, $remoteAddress);

            if ($existingId) {
                Log::info('[PppProfileManager] Updating existing PPP profile', [
                    'profile' => $profileName,
                    'id' => $existingId,
                ]);

                array_unshift($params, '=.id=' . $existingId);
                $this->apiProtocol->sendCommand($socket, '/ppp/profile/set', $params);
            } else {
                Log::info('[PppProfileManager] Creating new PPP profile', [
                    'profile' => $profileName,
                ]);

                $this->apiProtocol->sendCommand($socket, '/ppp/profile/add', $params);
            }

            $error = $this->apiProtocol->readUntilDoneWithError($socket);
            $this->connectionManager->closeClientApi($socket);

            if ($error) {
                return [
                    'success' => false,
                    'message' => 'Error al crear/actualizar perfil PPPoE: ' . $error,
                    'method' => 'DIRECT_API',
                ];
            }

            return [
                'success' => true,
                'method' => 'DIRECT_API',
                'action' => $existingId ? 'updated' : 'created',
                'message' => 'Perfil PPPoE sincronizado correctamente',
                'profile_name' => $profileName,
                'rate_limit' => $rateLimit,
            ];
        } catch (\Throwable $e) {
            $this->connectionManager->closeClientApi($socket);

            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Sync via CORE: SSH directly into CORE and run ssh-exec from there.
     * Simpler than the API-script approach — no persistent scripts left on CORE.
     */
    private function syncViaCoreDirectSsh(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $profileName,
        string $rateLimit,
        ?string $localAddress,
        ?string $remoteAddress
    ): array {
        try {
            $clientCommand = $this->buildRouterCommand($profileName, $rateLimit, $localAddress, $remoteAddress);
            $safePass      = str_replace('"', '\\"', $clientPass);
            $coreCommand   = "/system ssh-exec address={$clientIp} user={$clientUser} password=\"{$safePass}\" command=\"" . addslashes($clientCommand) . "\"";

            Log::info('[PppProfileManager] CORE SSH direct: executing ssh-exec', [
                'client_ip' => $clientIp,
                'profile'   => $profileName,
            ]);

            $result = $this->connectionManager->executeSsh($coreCommand);

            if (!$result['success']) {
                return [
                    'success' => false,
                    'method'  => 'CORE_SSH_DIRECT',
                    'message' => $result['message'] ?? 'No se pudo conectar al CORE via SSH',
                ];
            }

            $output = trim((string) ($result['output'] ?? ''));

            // The CORE could not open SSH to the client at all (TCP/handshake) —
            // not a command failure. Say so precisely instead of "error en router
            // cliente", which points at the wrong thing.
            if ($output && $this->isSshExecConnectionFailure($output)) {
                Log::warning('[PppProfileManager] CORE SSH direct: connection to client failed', ['output' => $output]);
                return [
                    'success' => false,
                    'method'  => 'CORE_SSH_DIRECT',
                    'message' => $this->sshExecConnectionFailureMessage($clientIp, $output),
                ];
            }

            if ($output && preg_match('/\berror\b|\bfailure\b|\bcannot\b|\brefused\b|\bunknown\b/i', $output)) {
                Log::warning('[PppProfileManager] CORE SSH direct: error in output', ['output' => $output]);
                return [
                    'success' => false,
                    'method'  => 'CORE_SSH_DIRECT',
                    'message' => 'Error en router cliente: ' . $output,
                ];
            }

            Log::info('[PppProfileManager] CORE SSH direct: success', [
                'profile' => $profileName,
                'output'  => $output ?: '(sin output)',
            ]);

            return [
                'success'      => true,
                'method'       => 'CORE_SSH_DIRECT',
                'action'       => 'upserted',
                'message'      => 'Perfil PPPoE sincronizado via CORE SSH',
                'profile_name' => $profileName,
                'rate_limit'   => $rateLimit,
            ];

        } catch (\Throwable $e) {
            Log::error('[PppProfileManager] CORE SSH direct exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'method' => 'CORE_SSH_DIRECT', 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    private function syncViaCore(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $profileName,
        string $rateLimit,
        ?string $localAddress,
        ?string $remoteAddress
    ): array {
        try {
            $socket = $this->apiProtocol->connect(
                $this->connectionManager->getApiHost(),
                $this->connectionManager->getApiPort(),
                10
            );

            if (!$socket) {
                return [
                    'success' => false,
                    'message' => 'No se pudo conectar al CORE',
                ];
            }

            if (
                !$this->apiProtocol->login(
                    $socket,
                    $this->connectionManager->getApiUser(),
                    $this->connectionManager->getApiPass()
                )
            ) {
                $this->apiProtocol->close($socket);

                return [
                    'success' => false,
                    'message' => 'Error de autenticacion al CORE',
                ];
            }

            $safePass = str_replace('"', '\\"', $clientPass);
            $scriptName = 'ispwatch_ppp_' . substr(uniqid(), -6);
            $clientCommand = $this->buildRouterCommand($profileName, $rateLimit, $localAddress, $remoteAddress);
            $scriptSource = "/system ssh-exec address={$clientIp} user={$clientUser} password=\"{$safePass}\" command=\"" . addslashes($clientCommand) . "\"";

            $this->apiProtocol->sendCommand($socket, '/system/script/add', [
                '=name=' . $scriptName,
                '=source=' . $scriptSource,
            ]);
            $addError = $this->apiProtocol->readUntilDoneWithError($socket);

            if ($addError) {
                $this->apiProtocol->close($socket);

                return [
                    'success' => false,
                    'message' => 'Error creando script: ' . $addError,
                ];
            }

            $this->apiProtocol->sendCommand($socket, '/system/script/run', [
                '=number=' . $scriptName,
            ]);
            $runError = $this->apiProtocol->readUntilDoneWithError($socket);

            $this->apiProtocol->sendCommand($socket, '/system/script/remove', [
                '=numbers=' . $scriptName,
            ]);
            $this->apiProtocol->readUntilDone($socket);

            $this->apiProtocol->close($socket);

            if ($runError) {
                return [
                    'success' => false,
                    'method' => 'CORE_SSH_EXEC',
                    'message' => 'Error: ' . $runError,
                ];
            }

            return [
                'success' => true,
                'method' => 'CORE_SSH_EXEC',
                'action' => 'upserted',
                'message' => 'Perfil PPPoE sincronizado via CORE',
                'profile_name' => $profileName,
                'rate_limit' => $rateLimit,
            ];
        } catch (\Throwable $e) {
            Log::error('[PppProfileManager] CORE sync failed', [
                'profile' => $profileName,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }

    private function buildApiParams(
        string $profileName,
        string $rateLimit,
        ?string $localAddress,
        ?string $remoteAddress
    ): array {
        $params = [
            '=name=' . $profileName,
            '=rate-limit=' . $rateLimit,
            '=comment=ISPWatch - ' . $profileName,
        ];

        if ($localAddress !== null && trim($localAddress) !== '') {
            $params[] = '=local-address=' . trim($localAddress);
        }

        if ($remoteAddress !== null && trim($remoteAddress) !== '') {
            $params[] = '=remote-address=' . trim($remoteAddress);
        }

        return $params;
    }

    private function buildRouterCommand(
        string $profileName,
        string $rateLimit,
        ?string $localAddress,
        ?string $remoteAddress
    ): string {
        $escapedName = $this->escapeRouterOsQuotedValue($profileName);
        $args = ' rate-limit="' . $this->escapeRouterOsQuotedValue($rateLimit) . '"'
            . ($localAddress !== null && trim($localAddress) !== ''
                ? ' local-address="' . $this->escapeRouterOsQuotedValue(trim($localAddress)) . '"' : '')
            . ($remoteAddress !== null && trim($remoteAddress) !== ''
                ? ' remote-address="' . $this->escapeRouterOsQuotedValue(trim($remoteAddress)) . '"' : '')
            . ' comment="ISPWatch - ' . $escapedName . '"';

        // Try to add (ignore if already exists), then always set to apply latest params.
        // This avoids :local/:if conditionals which can be unreliable inside ssh-exec command strings.
        return ':do { /ppp profile add name="' . $escapedName . '"' . $args
            . ' } on-error={}; /ppp profile set [find name="' . $escapedName . '"]' . $args;
    }

    // ==================== PPPoE SECRET MANAGEMENT ON CLIENT ROUTER ====================

    /**
     * Create or update a PPPoE /ppp secret on a client router.
     * Mirrors the same try-direct-API / CORE-SSH-exec pattern as syncPppoeProfile.
     */
    public function ensurePppoeSecretOnRouter(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $username,
        string $password,
        string $profile = 'default',
        string $service = 'pppoe',
        int $clientPort = 8728,
        ?string $remoteAddress = null,
        ?string $localAddress = null,
        ?string $comment = null
    ): array {
        // RouterOS comments don't render accents/ñ; transliterate the customer
        // name once here so both the direct-API and CORE-SSH paths below push
        // the same clean ASCII label.
        $comment = $this->normalizeRouterComment($comment);
        try {
            Log::info('[PppProfileManager] Ensuring PPPoE secret on router', [
                'client_ip'      => $clientIp,
                'username'       => $username,
                'profile'        => $profile,
                'remote_address' => $remoteAddress,
                'local_address'  => $localAddress,
            ]);

            // 1. Try direct API to the client THROUGH the CORE SSH tunnel.
            //    connectClientApi() opens the local-forward tunnel AND logs in.
            //    (Was tryDirectClientConnection() + connect($clientIp,...) direct,
            //    which never completes in production — overlay IP not routable
            //    from the app server — so the API tier never actually ran.)
            $socket = $this->connectionManager->connectClientApi($clientIp, $clientPort, $clientUser, $clientPass);
            if ($socket) {
                $result = $this->ensureSecretDirectApi($socket, $username, $password, $service, $profile, $remoteAddress, $localAddress, $comment);
                if ($result['success']) {
                    return $result;
                }
                Log::warning('[PppProfileManager] Direct API secret failed', ['reason' => $result['message'] ?? 'unknown']);
            }

            // 2. CORE SSH direct: SSH into CORE and run ssh-exec from there.
            //    This is the proven fast path (same mechanism the working PPP
            //    profile sync uses). Return its result directly — NO extra
            //    CORE API-script tier: stacking another SSH/API round-trip on
            //    top of the queue made bulkProvision exceed the gateway
            //    timeout (HTTP 504).
            return $this->secretViaCoreDirectSsh(
                $clientIp, $clientUser, $clientPass,
                $username, $password, $service, $profile, $remoteAddress, $localAddress, $comment
            );

        } catch (\Throwable $e) {
            Log::error('[PppProfileManager] Error ensuring PPPoE secret on router', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * CORE SSH direct: SSH into CORE and run ssh-exec from there for the secret.
     * Mirrors syncViaCoreDirectSsh (PPP profile) so the secret loads the same way.
     */
    private function secretViaCoreDirectSsh(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $username,
        string $password,
        string $service,
        string $profile,
        ?string $remoteAddress = null,
        ?string $localAddress = null,
        ?string $comment = null
    ): array {
        try {
            $clientCommand = $this->buildSecretCommand($username, $password, $service, $profile, $remoteAddress, $localAddress, $comment);
            $safePass      = str_replace('"', '\\"', $clientPass);
            $coreCommand   = "/system ssh-exec address={$clientIp} user={$clientUser} password=\"{$safePass}\" command=\"" . addslashes($clientCommand) . "\"";

            Log::info('[PppProfileManager] CORE SSH direct: executing ssh-exec for secret', [
                'client_ip' => $clientIp,
                'username'  => $username,
            ]);

            $result = $this->connectionManager->executeSsh($coreCommand);

            if (!$result['success']) {
                return ['success' => false, 'method' => 'CORE_SSH_DIRECT', 'message' => $result['message'] ?? 'No se pudo conectar al CORE via SSH'];
            }

            $output = trim((string) ($result['output'] ?? ''));

            // FIRST: distinguish a CORE→client SSH *connection* failure (e.g.
            // `<connection failed> ...:22`) from a command rejection. On a
            // connection failure NOTHING ran on the client, so blaming the
            // plan/profile below is wrong — it sends the operator to reload the
            // plan when the real fix is the client's SSH service / firewall.
            if ($output && $this->isSshExecConnectionFailure($output)) {
                return [
                    'success' => false,
                    'method'  => 'CORE_SSH_DIRECT',
                    'message' => $this->sshExecConnectionFailureMessage($clientIp, $output),
                ];
            }

            // The trailing `/ppp secret set [find ...]` errors (and prints to
            // stdout) when the secret could not be created — most often because
            // the plan's PPP profile isn't loaded on THIS router, or the mgmt
            // user lacks write perms on /ppp secret.
            if ($output && preg_match('/\berror\b|\bfailure\b|\bcannot\b|\brefused\b|no such item|match any value/i', $output)) {
                return [
                    'success'    => false,
                    'method'     => 'CORE_SSH_DIRECT',
                    'definitive' => true,
                    'message'    => 'No se pudo crear el secret PPPoE. Causa más probable: el plan/perfil "' . $profile . '" NO está cargado en ESE router — cárgalo en Planes → cargar a la RB y reintenta. (Si el perfil ya estaba, revisa permisos del usuario de gestión sobre /ppp secret.) Detalle del router: ' . $output,
                ];
            }

            Log::info('[PppProfileManager] PPPoE secret created/updated via CORE SSH', ['username' => $username]);

            return [
                'success'  => true,
                'method'   => 'CORE_SSH_DIRECT',
                'action'   => 'upserted',
                'message'  => 'PPPoE secret sincronizado en router',
                'username' => $username,
            ];
        } catch (\Throwable $e) {
            Log::error('[PppProfileManager] CORE SSH secret exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'method' => 'CORE_SSH_DIRECT', 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    private function ensureSecretDirectApi(
        $socket,
        string $username,
        string $password,
        string $service,
        string $profile,
        ?string $remoteAddress = null,
        ?string $localAddress = null,
        ?string $comment = null
    ): array {
        $comment = ($comment !== null && trim($comment) !== '') ? trim($comment) : 'ISPWatch Auto';
        try {
            // Socket is already authenticated by connectClientApi — go straight to operations.
            $this->apiProtocol->sendCommand($socket, '/ppp/secret/print', ['?name=' . $username]);
            $existing = $this->apiProtocol->readAllRecords($socket);

            $setParams = [
                '=password='       . $password,
                '=service='        . $service,
                '=profile='        . $profile,
                '=comment='        . $comment,
            ];
            if ($remoteAddress && trim($remoteAddress) !== '') {
                $setParams[] = '=remote-address=' . trim($remoteAddress);
            }
            if ($localAddress && trim($localAddress) !== '') {
                $setParams[] = '=local-address=' . trim($localAddress);
            }

            if (!empty($existing)) {
                $secretId = $existing[0]['.id'] ?? null;
                if ($secretId) {
                    array_unshift($setParams, '=.id=' . $secretId);
                    $this->apiProtocol->sendCommand($socket, '/ppp/secret/set', $setParams);
                    $error = $this->apiProtocol->readUntilDoneWithError($socket);
                    $this->connectionManager->closeClientApi($socket);
                    if ($error) {
                        return ['success' => false, 'method' => 'DIRECT_API', 'message' => 'Error updating secret: ' . $error];
                    }
                    return ['success' => true, 'method' => 'DIRECT_API', 'action' => 'updated', 'message' => 'Secret actualizado en router', 'username' => $username];
                }
            }

            $addParams = array_merge([
                '=name='     . $username,
            ], $setParams);
            $this->apiProtocol->sendCommand($socket, '/ppp/secret/add', $addParams);
            $error = $this->apiProtocol->readUntilDoneWithError($socket);
            $this->connectionManager->closeClientApi($socket);

            if ($error) {
                return ['success' => false, 'method' => 'DIRECT_API', 'message' => 'Error creating secret: ' . $error];
            }

            return ['success' => true, 'method' => 'DIRECT_API', 'action' => 'created', 'message' => 'Secret creado en router', 'username' => $username];

        } catch (\Throwable $e) {
            $this->connectionManager->closeClientApi($socket);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    private function buildSecretCommand(string $username, string $password, string $service, string $profile, ?string $remoteAddress = null, ?string $localAddress = null, ?string $comment = null): string
    {
        $escapedUser    = $this->escapeRouterOsQuotedValue($username);
        $escapedPass    = $this->escapeRouterOsQuotedValue($password);
        $escapedProfile = $this->escapeRouterOsQuotedValue($profile);
        $escapedComment = $this->escapeRouterOsQuotedValue(
            ($comment !== null && trim($comment) !== '') ? trim($comment) : 'ISPWatch Auto'
        );
        $remoteAttr     = ($remoteAddress && trim($remoteAddress) !== '')
            ? ' remote-address="' . $this->escapeRouterOsQuotedValue(trim($remoteAddress)) . '"'
            : '';
        $localAttr      = ($localAddress && trim($localAddress) !== '')
            ? ' local-address="' . $this->escapeRouterOsQuotedValue(trim($localAddress)) . '"'
            : '';

        $addCmd = '/ppp secret add name="' . $escapedUser . '" password="' . $escapedPass
            . '" service=' . $service . ' profile="' . $escapedProfile . '"' . $remoteAttr . $localAttr
            . ' comment="' . $escapedComment . '"';
        $setCmd = '/ppp secret set [find name="' . $escapedUser . '"] password="' . $escapedPass
            . '" service=' . $service . ' profile="' . $escapedProfile . '"' . $remoteAttr . $localAttr
            . ' comment="' . $escapedComment . '"';

        // Mirror EXACTLY the proven (fast) PPP-profile command shape:
        // single-level `:do { add } on-error={}` then an unconditional `set`.
        // No nested on-error and no :if/:len/:put expressions — those are
        // escape-fragile over ssh-exec and made the remote command hang until
        // the gateway timed out (504). If the secret can't be created the
        // trailing `set [find ...]` errors and prints to stdout, which the PHP
        // side detects below.
        return ':do { ' . $addCmd . ' } on-error={}; ' . $setCmd;
    }

    private function buildRateLimit(string $speedUp, string $speedDown): string
    {
        return $this->normalizeSpeed($speedUp) . '/' . $this->normalizeSpeed($speedDown);
    }

    private function normalizeSpeed(string $speed): string
    {
        $speed = trim($speed);

        if (is_numeric($speed)) {
            return $speed . 'M';
        }

        return strtoupper($speed);
    }

    private function escapeRouterOsQuotedValue(string $value): string
    {
        return addcslashes($value, "\\\"");
    }
}
