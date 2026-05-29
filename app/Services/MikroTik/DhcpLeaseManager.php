<?php

namespace App\Services\MikroTik;

use Illuminate\Support\Facades\Log;

/**
 * DHCP Lease Manager
 *
 * Creates/updates a static /ip dhcp-server lease (address + MAC) with an
 * optional rate-limit on the client router. Mirrors the proven try-direct-API
 * → CORE ssh-exec pattern. Same syntax on RouterOS v6/v7.
 */
class DhcpLeaseManager
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
     * Create or update a static DHCP lease binding the IP to the client MAC.
     */
    public function ensureLeaseOnRouter(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $targetIp,
        string $mac,
        string $speedUp,
        string $speedDown,
        int $clientPort = 8728,
        ?string $comment = null
    ): array {
        // SECURITY (OWASP A03): address and mac-address are interpolated
        // unquoted; reject anything that is not a valid IP / MAC.
        if (filter_var(trim($targetIp), FILTER_VALIDATE_IP) === false) {
            return ['success' => false, 'message' => 'IP del cliente inválida para el lease DHCP.'];
        }
        $mac = strtoupper(trim($mac));
        if (!preg_match('/^([0-9A-F]{2}[:-]){5}[0-9A-F]{2}$/', $mac)) {
            return ['success' => false, 'message' => 'MAC del cliente inválida (formato AA:BB:CC:DD:EE:FF).'];
        }
        $mac      = str_replace('-', ':', $mac);
        $targetIp = trim($targetIp);
        $comment  = ($comment !== null && trim($comment) !== '') ? trim($comment) : 'ISPWatch Auto';
        $rateLimit = $this->buildRateLimit($speedUp, $speedDown);

        try {
            Log::info('[DhcpLeaseManager] Ensuring DHCP lease', [
                'client_ip' => $clientIp,
                'target'    => $targetIp,
                'mac'       => $mac,
            ]);

            // 1. Try direct API to the client first.
            if ($this->connectionManager->tryDirectClientConnection($clientIp, $clientPort)) {
                $socket = $this->apiProtocol->connect($clientIp, $clientPort, 3);
                if ($socket) {
                    $direct = $this->ensureLeaseDirectApi($socket, $clientUser, $clientPass, $targetIp, $mac, $rateLimit, $comment);
                    if ($direct['success']) {
                        return $direct;
                    }
                    Log::warning('[DhcpLeaseManager] Direct API lease failed, using CORE SSH', [
                        'reason' => $direct['message'] ?? 'unknown',
                    ]);
                }
            }

            // 2. CORE SSH direct.
            $clientCommand = $this->buildLeaseCommand($targetIp, $mac, $rateLimit, $comment);
            return $this->runViaCore($clientIp, $clientUser, $clientPass, $clientCommand, 'lease DHCP');
        } catch (\Throwable $e) {
            Log::error('[DhcpLeaseManager] Error ensuring DHCP lease', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    private function ensureLeaseDirectApi(
        $socket,
        string $clientUser,
        string $clientPass,
        string $targetIp,
        string $mac,
        string $rateLimit,
        string $comment
    ): array {
        try {
            if (!$this->apiProtocol->login($socket, $clientUser, $clientPass)) {
                $this->apiProtocol->close($socket);
                return ['success' => false, 'message' => 'Error de autenticación en router cliente'];
            }

            $this->apiProtocol->sendCommand($socket, '/ip/dhcp-server/lease/print', ['?address=' . $targetIp, '=.proplist=.id']);
            $existing = $this->apiProtocol->readAllRecords($socket);
            $existingId = $existing[0]['.id'] ?? null;

            $params = [
                '=mac-address=' . $mac,
                '=rate-limit='  . $rateLimit,
                '=comment='     . $comment,
            ];

            if ($existingId) {
                array_unshift($params, '=.id=' . $existingId);
                $this->apiProtocol->sendCommand($socket, '/ip/dhcp-server/lease/set', $params);
            } else {
                array_unshift($params, '=address=' . $targetIp);
                $this->apiProtocol->sendCommand($socket, '/ip/dhcp-server/lease/add', $params);
            }

            $error = $this->apiProtocol->readUntilDoneWithError($socket);
            $this->apiProtocol->close($socket);

            if ($error) {
                return ['success' => false, 'method' => 'DIRECT_API', 'message' => 'Error en lease DHCP: ' . $error];
            }

            return [
                'success' => true,
                'method'  => 'DIRECT_API',
                'action'  => $existingId ? 'updated' : 'created',
                'message' => 'Lease DHCP sincronizado correctamente',
                'target'  => $targetIp,
                'mac'     => $mac,
            ];
        } catch (\Throwable $e) {
            @$this->apiProtocol->close($socket);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    private function buildLeaseCommand(string $targetIp, string $mac, string $rateLimit, string $comment): string
    {
        $limit = $this->escapeRouterOsQuotedValue($rateLimit);
        $comm  = $this->escapeRouterOsQuotedValue($comment);
        $args  = ' mac-address=' . $mac . ' rate-limit="' . $limit . '" comment="' . $comm . '"';

        $addCmd = '/ip dhcp-server lease add address=' . $targetIp . $args;
        $setCmd = '/ip dhcp-server lease set [find address=' . $targetIp . ']' . $args;

        return ':do { ' . $addCmd . ' } on-error={}; ' . $setCmd;
    }

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

            Log::info('[DhcpLeaseManager] CORE SSH direct: ssh-exec', ['client_ip' => $clientIp, 'what' => $what]);

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
                    'message'    => "No se pudo sincronizar el {$what}. Detalle del router: " . $output,
                ];
            }

            Log::info('[DhcpLeaseManager] CORE SSH direct: success', ['what' => $what]);

            return [
                'success' => true,
                'method'  => 'CORE_SSH_DIRECT',
                'action'  => 'upserted',
                'message' => ucfirst($what) . ' sincronizado via CORE',
            ];
        } catch (\Throwable $e) {
            Log::error('[DhcpLeaseManager] CORE SSH exception', ['error' => $e->getMessage()]);
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
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        return addcslashes($value, "\\\"\$");
    }
}
