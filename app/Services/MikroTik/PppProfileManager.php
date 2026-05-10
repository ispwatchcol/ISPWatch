<?php

namespace App\Services\MikroTik;

use Illuminate\Support\Facades\Log;

/**
 * PPP Profile Manager
 *
 * Handles creating or updating PPP profiles on client MikroTik routers.
 * Supports direct API first and CORE ssh-exec as fallback.
 */
class PppProfileManager
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
                'client_ip' => $clientIp,
                'profile' => $profileName,
                'rate_limit' => $rateLimit,
                'local_address' => $localAddress,
                'remote_address' => $remoteAddress,
            ]);

            if ($this->connectionManager->tryDirectClientConnection($clientIp, $clientPort)) {
                $socket = $this->apiProtocol->connect($clientIp, $clientPort, 5);

                if ($socket) {
                    Log::info('[PppProfileManager] Direct API connection available');
                    return $this->syncDirectApi(
                        $socket,
                        $clientUser,
                        $clientPass,
                        $profileName,
                        $rateLimit,
                        $localAddress,
                        $remoteAddress,
                    );
                }
            }

            Log::info('[PppProfileManager] Direct API unavailable, using CORE fallback');

            return $this->syncViaCore(
                $clientIp,
                $clientUser,
                $clientPass,
                $profileName,
                $rateLimit,
                $localAddress,
                $remoteAddress,
            );
        } catch (\Throwable $e) {
            Log::error('[PppProfileManager] Error syncing PPP profile', [
                'profile' => $profileName,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }

    private function syncDirectApi(
        $socket,
        string $clientUser,
        string $clientPass,
        string $profileName,
        string $rateLimit,
        ?string $localAddress,
        ?string $remoteAddress
    ): array {
        try {
            if (!$this->apiProtocol->login($socket, $clientUser, $clientPass)) {
                $this->apiProtocol->close($socket);

                return [
                    'success' => false,
                    'message' => 'Error de autenticacion en router cliente',
                ];
            }

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
            $this->apiProtocol->close($socket);

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
            @$this->apiProtocol->close($socket);

            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
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
            '=comment=ISPWatch Auto-Profile',
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
        $findExpression = '[/ppp profile find where name="' . $this->escapeRouterOsQuotedValue($profileName) . '"]';
        $localStatement = $localAddress !== null && trim($localAddress) !== ''
            ? ' local-address="' . $this->escapeRouterOsQuotedValue(trim($localAddress)) . '"'
            : '';
        $remoteStatement = $remoteAddress !== null && trim($remoteAddress) !== ''
            ? ' remote-address="' . $this->escapeRouterOsQuotedValue(trim($remoteAddress)) . '"'
            : '';
        $commonArgs = ' name="' . $this->escapeRouterOsQuotedValue($profileName) . '"'
            . ' rate-limit="' . $this->escapeRouterOsQuotedValue($rateLimit) . '"'
            . $localStatement
            . $remoteStatement
            . ' comment="ISPWatch Auto-Profile"';

        return ':local pid ' . $findExpression
            . '; :if ([:len $pid] > 0) do={ /ppp profile set $pid' . $commonArgs
            . ' } else={ /ppp profile add' . $commonArgs . ' }';
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
