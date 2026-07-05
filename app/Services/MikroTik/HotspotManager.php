<?php

namespace App\Services\MikroTik;

use App\Services\MikroTik\Concerns\DetectsSshExecFailures;
use App\Services\MikroTik\Concerns\NormalizesRouterComment;
use Illuminate\Support\Facades\Log;

/**
 * HotSpot Manager
 *
 * Handles HotSpot provisioning on client MikroTik routers:
 *  - per-plan:   /ip hotspot user profile  (rate-limit, shared-users, timeouts)
 *  - per-client: /ip hotspot user          (login bound to a profile)
 *
 * Mirrors the proven try-direct-API → CORE ssh-exec pattern used by
 * QueueManager / PppProfileManager. Commands are identical on RouterOS v6/v7,
 * so no firmware-version branching is required (same as queue/secret).
 */
class HotspotManager
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

    // ==================== PER-CLIENT: HOTSPOT USER ====================

    /**
     * Create or update a /ip hotspot user on the client router.
     */
    public function ensureHotspotUserOnRouter(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $username,
        string $password,
        string $profile = 'default',
        int $clientPort = 8728,
        ?string $address = null,
        ?string $comment = null
    ): array {
        $comment = $this->normalizeRouterComment($comment);

        if (trim($username) === '') {
            return ['success' => false, 'message' => 'El usuario HotSpot está vacío.'];
        }
        if ($address !== null && trim($address) !== '' && filter_var(trim($address), FILTER_VALIDATE_IP) === false) {
            return ['success' => false, 'message' => 'La IP del usuario HotSpot (address) es inválida.'];
        }

        try {
            Log::info('[HotspotManager] Ensuring HotSpot user on router', [
                'client_ip' => $clientIp,
                'username'  => $username,
                'profile'   => $profile,
            ]);

            // 1. Try direct API to the client THROUGH the CORE SSH tunnel.
            $socket = $this->connectionManager->connectClientApi($clientIp, $clientPort, $clientUser, $clientPass);
            if ($socket) {
                $direct = $this->ensureUserDirectApi($socket, $username, $password, $profile, $address, $comment);
                if ($direct['success']) {
                    return $direct;
                }
                Log::warning('[HotspotManager] Direct API user failed, using CORE SSH', [
                    'reason' => $direct['message'] ?? 'unknown',
                ]);
            }

            // 2. CORE SSH direct (proven path for clients behind the L2TP tunnel).
            $clientCommand = $this->buildUserCommand($username, $password, $profile, $address, $comment);
            return $this->runViaCore($clientIp, $clientUser, $clientPass, $clientCommand, 'HotSpot user');
        } catch (\Throwable $e) {
            Log::error('[HotspotManager] Error ensuring HotSpot user', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    private function ensureUserDirectApi(
        $socket,
        string $username,
        string $password,
        string $profile,
        ?string $address,
        string $comment
    ): array {
        try {
            // Socket is already authenticated by connectClientApi — go straight to operations.
            $this->apiProtocol->sendCommand($socket, '/ip/hotspot/user/print', ['?name=' . $username, '=.proplist=.id']);
            $existing = $this->apiProtocol->readAllRecords($socket);

            $params = [
                '=password=' . $password,
                '=profile='  . $profile,
                '=comment='  . $comment,
            ];
            if ($address !== null && trim($address) !== '') {
                $params[] = '=address=' . trim($address);
            }

            $existingId = $existing[0]['.id'] ?? null;
            if ($existingId) {
                array_unshift($params, '=.id=' . $existingId);
                $this->apiProtocol->sendCommand($socket, '/ip/hotspot/user/set', $params);
            } else {
                array_unshift($params, '=name=' . $username);
                $this->apiProtocol->sendCommand($socket, '/ip/hotspot/user/add', $params);
            }

            $error = $this->apiProtocol->readUntilDoneWithError($socket);
            $this->connectionManager->closeClientApi($socket);

            if ($error) {
                return ['success' => false, 'method' => 'DIRECT_API', 'message' => 'Error al crear/actualizar usuario HotSpot: ' . $error];
            }

            return [
                'success'  => true,
                'method'   => 'DIRECT_API',
                'action'   => $existingId ? 'updated' : 'created',
                'message'  => 'Usuario HotSpot sincronizado correctamente',
                'username' => $username,
            ];
        } catch (\Throwable $e) {
            $this->connectionManager->closeClientApi($socket);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    private function buildUserCommand(string $username, string $password, string $profile, ?string $address, string $comment): string
    {
        $u = $this->escapeRouterOsQuotedValue($username);
        $p = $this->escapeRouterOsQuotedValue($password);
        $pr = $this->escapeRouterOsQuotedValue($profile);
        $c = $this->escapeRouterOsQuotedValue($comment);
        $addrAttr = ($address !== null && trim($address) !== '')
            ? ' address=' . trim($address)
            : '';

        $args   = ' password="' . $p . '" profile="' . $pr . '"' . $addrAttr . ' comment="' . $c . '"';
        $addCmd = '/ip hotspot user add name="' . $u . '"' . $args;
        $setCmd = '/ip hotspot user set [find name="' . $u . '"]' . $args;

        return ':do { ' . $addCmd . ' } on-error={}; ' . $setCmd;
    }

    // ==================== PER-PLAN: HOTSPOT USER PROFILE ====================

    /**
     * Create or update a /ip hotspot user profile on the client router.
     */
    public function syncHotspotUserProfile(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $profileName,
        string $speedUp,
        string $speedDown,
        ?int $sharedUsers = null,
        ?string $sessionTimeout = null,
        ?string $idleTimeout = null,
        int $clientPort = 8728
    ): array {
        $rateLimit = $this->buildRateLimit($speedUp, $speedDown);

        try {
            Log::info('[HotspotManager] Syncing HotSpot user profile', [
                'client_ip' => $clientIp,
                'profile'   => $profileName,
                'rate'      => $rateLimit,
            ]);

            // 1. Try direct API to the client THROUGH the CORE SSH tunnel.
            $socket = $this->connectionManager->connectClientApi($clientIp, $clientPort, $clientUser, $clientPass);
            if ($socket) {
                $direct = $this->ensureProfileDirectApi($socket, $profileName, $rateLimit, $sharedUsers, $sessionTimeout, $idleTimeout);
                if ($direct['success']) {
                    return $direct;
                }
                Log::warning('[HotspotManager] Direct API profile failed, using CORE SSH', [
                    'reason' => $direct['message'] ?? 'unknown',
                ]);
            }

            // 2. CORE SSH direct.
            $clientCommand = $this->buildProfileCommand($profileName, $rateLimit, $sharedUsers, $sessionTimeout, $idleTimeout);
            return $this->runViaCore($clientIp, $clientUser, $clientPass, $clientCommand, 'HotSpot profile');
        } catch (\Throwable $e) {
            Log::error('[HotspotManager] Error syncing HotSpot profile', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    private function ensureProfileDirectApi(
        $socket,
        string $profileName,
        string $rateLimit,
        ?int $sharedUsers,
        ?string $sessionTimeout,
        ?string $idleTimeout
    ): array {
        try {
            // Socket is already authenticated by connectClientApi — go straight to operations.
            $this->apiProtocol->sendCommand($socket, '/ip/hotspot/user/profile/print', ['?name=' . $profileName, '=.proplist=.id']);
            $existing = $this->apiProtocol->readAllRecords($socket);

            // /ip hotspot user profile has no `comment` property — sending it
            // makes the API call fail. Identified by name (= plan name) instead.
            $params = [
                '=rate-limit=' . $rateLimit,
            ];
            if ($sharedUsers !== null && $sharedUsers > 0) {
                $params[] = '=shared-users=' . $sharedUsers;
            }
            if ($sessionTimeout !== null && trim($sessionTimeout) !== '') {
                $params[] = '=session-timeout=' . trim($sessionTimeout);
            }
            if ($idleTimeout !== null && trim($idleTimeout) !== '') {
                $params[] = '=idle-timeout=' . trim($idleTimeout);
            }

            $existingId = $existing[0]['.id'] ?? null;
            if ($existingId) {
                array_unshift($params, '=.id=' . $existingId);
                $this->apiProtocol->sendCommand($socket, '/ip/hotspot/user/profile/set', $params);
            } else {
                array_unshift($params, '=name=' . $profileName);
                $this->apiProtocol->sendCommand($socket, '/ip/hotspot/user/profile/add', $params);
            }

            $error = $this->apiProtocol->readUntilDoneWithError($socket);
            $this->connectionManager->closeClientApi($socket);

            if ($error) {
                return ['success' => false, 'method' => 'DIRECT_API', 'message' => 'Error al crear/actualizar perfil HotSpot: ' . $error];
            }

            return [
                'success'      => true,
                'method'       => 'DIRECT_API',
                'action'       => $existingId ? 'updated' : 'created',
                'message'      => 'Perfil HotSpot sincronizado correctamente',
                'profile_name' => $profileName,
                'rate_limit'   => $rateLimit,
            ];
        } catch (\Throwable $e) {
            $this->connectionManager->closeClientApi($socket);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    private function buildProfileCommand(
        string $profileName,
        string $rateLimit,
        ?int $sharedUsers,
        ?string $sessionTimeout,
        ?string $idleTimeout
    ): string {
        $name = $this->escapeRouterOsQuotedValue($profileName);
        $args = ' rate-limit="' . $this->escapeRouterOsQuotedValue($rateLimit) . '"';
        if ($sharedUsers !== null && $sharedUsers > 0) {
            $args .= ' shared-users=' . (int) $sharedUsers;
        }
        if ($sessionTimeout !== null && trim($sessionTimeout) !== '') {
            $args .= ' session-timeout="' . $this->escapeRouterOsQuotedValue(trim($sessionTimeout)) . '"';
        }
        if ($idleTimeout !== null && trim($idleTimeout) !== '') {
            $args .= ' idle-timeout="' . $this->escapeRouterOsQuotedValue(trim($idleTimeout)) . '"';
        }
        // NOTE: /ip hotspot user profile has NO `comment` property on RouterOS
        // (unlike /ppp profile). Sending comment= makes the whole add/set fail
        // with "expected end of command" — silently, because ssh-exec swallows
        // it. The profile is identified by its name (= plan name), so the
        // comment is unnecessary anyway.

        $addCmd = '/ip hotspot user profile add name="' . $name . '"' . $args;
        $setCmd = '/ip hotspot user profile set [find name="' . $name . '"]' . $args;

        return ':do { ' . $addCmd . ' } on-error={}; ' . $setCmd;
    }

    // ==================== SHARED CORE SSH RUNNER ====================

    /**
     * SSH into the CORE and run a single ssh-exec into the client — the proven
     * ~17s path. Single-level `:do { add } on-error={}; set [find]` shape only.
     */
    private function runViaCore(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $clientCommand,
        string $what
    ): array {
        try {
            $safePass    = str_replace('"', '\\"', $clientPass);
            $coreCommand = "/system ssh-exec address={$clientIp} user={$clientUser} password=\"{$safePass}\" command=\"" . addslashes($clientCommand) . "\"";

            Log::info('[HotspotManager] CORE SSH direct: ssh-exec', ['client_ip' => $clientIp, 'what' => $what]);

            $result = $this->connectionManager->executeSsh($coreCommand);

            if (!$result['success']) {
                return ['success' => false, 'method' => 'CORE_SSH_DIRECT', 'message' => $result['message'] ?? 'No se pudo conectar al CORE via SSH'];
            }

            $output = trim((string) ($result['output'] ?? ''));

            // CORE→client SSH connection failure — nothing ran on the client.
            if ($output && $this->isSshExecConnectionFailure($output)) {
                return [
                    'success' => false,
                    'method'  => 'CORE_SSH_DIRECT',
                    'message' => $this->sshExecConnectionFailureMessage($clientIp, $output),
                ];
            }

            // `/system ssh-exec` returns "exit-code: N ... output: ...". A
            // non-zero exit code means the CLIENT router rejected the command,
            // even when the error text (e.g. "expected end of command") is not
            // one of our keywords. Without the exit-code check we reported false
            // success on parser errors (the hotspot `comment=` bug went unseen).
            $failed = (bool) preg_match('/exit-code:\s*[1-9]/i', $output)
                || (bool) preg_match('/\berror\b|\bfailure\b|\bcannot\b|\brefused\b|no such item|match any value|expected end of command|expected command name|syntax error|unknown (?:parameter|argument)/i', $output);

            if ($output && $failed) {
                return [
                    'success'    => false,
                    'method'     => 'CORE_SSH_DIRECT',
                    'definitive' => true,
                    'message'    => "No se pudo sincronizar {$what}. Detalle del router: " . $output,
                ];
            }

            Log::info('[HotspotManager] CORE SSH direct: success', ['what' => $what]);

            return [
                'success' => true,
                'method'  => 'CORE_SSH_DIRECT',
                'action'  => 'upserted',
                'message' => "{$what} sincronizado via CORE",
            ];
        } catch (\Throwable $e) {
            Log::error('[HotspotManager] CORE SSH exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'method' => 'CORE_SSH_DIRECT', 'message' => 'Error: ' . $e->getMessage()];
        }
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
        // Strip control chars / newlines, then escape RouterOS quoted-string
        // metacharacters — backslash, double-quote and $ (substitution).
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        return addcslashes($value, "\\\"\$");
    }
}
