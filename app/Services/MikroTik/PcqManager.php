<?php

namespace App\Services\MikroTik;

use Illuminate\Support\Facades\Log;

/**
 * PCQ + Address-list Manager
 *
 * Two responsibilities:
 *  - per-client: add the client IP to the plan's firewall address-list.
 *  - per-plan:   build the PCQ "engine" once on the router — pcq queue types,
 *                mangle rules that mark traffic of the address-list, and the
 *                queue trees that apply the pcq types to those packet-marks.
 *
 * Mirrors the proven try-direct-API → CORE ssh-exec pattern. Commands are the
 * same on RouterOS v6/v7. The per-plan engine is multi-statement so it always
 * goes through the single CORE ssh-exec (one round-trip), using only
 * sequential `:do { ... } on-error={}; <add>` shapes (no nested on-error,
 * no :if/:put — those are escape-fragile over ssh-exec).
 */
class PcqManager
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

    // ==================== PER-CLIENT: ADDRESS-LIST ENTRY ====================

    /**
     * Add (idempotently) the client IP to the plan's firewall address-list.
     */
    public function ensureClientInAddressList(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $listName,
        string $targetIp,
        int $clientPort = 8728,
        ?string $comment = null
    ): array {
        // SECURITY (OWASP A03): targetIp is interpolated unquoted (address=<ip>);
        // reject anything that is not an IP so it can never carry a payload.
        if (filter_var(trim($targetIp), FILTER_VALIDATE_IP) === false) {
            return ['success' => false, 'message' => 'IP del cliente inválida para address-list.'];
        }
        $targetIp = trim($targetIp);
        $comment  = ($comment !== null && trim($comment) !== '') ? trim($comment) : 'ISPWatch Auto';

        try {
            Log::info('[PcqManager] Ensuring client in address-list', [
                'client_ip' => $clientIp,
                'list'      => $listName,
                'target'    => $targetIp,
            ]);

            // 1. Try direct API to the client first.
            if ($this->connectionManager->tryDirectClientConnection($clientIp, $clientPort)) {
                $socket = $this->apiProtocol->connect($clientIp, $clientPort, 3);
                if ($socket) {
                    $direct = $this->ensureEntryDirectApi($socket, $clientUser, $clientPass, $listName, $targetIp, $comment);
                    if ($direct['success']) {
                        return $direct;
                    }
                    Log::warning('[PcqManager] Direct API address-list failed, using CORE SSH', [
                        'reason' => $direct['message'] ?? 'unknown',
                    ]);
                }
            }

            // 2. CORE SSH direct.
            $clientCommand = $this->buildAddressListCommand($listName, $targetIp, $comment);
            return $this->runViaCore($clientIp, $clientUser, $clientPass, $clientCommand, 'entrada address-list');
        } catch (\Throwable $e) {
            Log::error('[PcqManager] Error ensuring address-list entry', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    private function ensureEntryDirectApi(
        $socket,
        string $clientUser,
        string $clientPass,
        string $listName,
        string $targetIp,
        string $comment
    ): array {
        try {
            if (!$this->apiProtocol->login($socket, $clientUser, $clientPass)) {
                $this->apiProtocol->close($socket);
                return ['success' => false, 'message' => 'Error de autenticación en router cliente'];
            }

            // Find any existing entry for this list+address to update its comment.
            $this->apiProtocol->sendCommand($socket, '/ip/firewall/address-list/print', [
                '?list=' . $listName,
                '?address=' . $targetIp,
                '=.proplist=.id',
            ]);
            $existing = $this->apiProtocol->readAllRecords($socket);
            $existingId = $existing[0]['.id'] ?? null;

            if ($existingId) {
                $this->apiProtocol->sendCommand($socket, '/ip/firewall/address-list/set', [
                    '=.id=' . $existingId,
                    '=comment=' . $comment,
                ]);
            } else {
                $this->apiProtocol->sendCommand($socket, '/ip/firewall/address-list/add', [
                    '=list=' . $listName,
                    '=address=' . $targetIp,
                    '=comment=' . $comment,
                ]);
            }

            $error = $this->apiProtocol->readUntilDoneWithError($socket);
            $this->apiProtocol->close($socket);

            if ($error) {
                return ['success' => false, 'method' => 'DIRECT_API', 'message' => 'Error en address-list: ' . $error];
            }

            return [
                'success' => true,
                'method'  => 'DIRECT_API',
                'action'  => $existingId ? 'updated' : 'created',
                'message' => 'Cliente agregado al address-list',
                'list'    => $listName,
                'target'  => $targetIp,
            ];
        } catch (\Throwable $e) {
            @$this->apiProtocol->close($socket);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    private function buildAddressListCommand(string $listName, string $targetIp, string $comment): string
    {
        $list = $this->escapeRouterOsQuotedValue($listName);
        $comm = $this->escapeRouterOsQuotedValue($comment);

        // Remove any prior entry for this list+address (no-op if none), then add
        // it fresh so the comment is always current and we never duplicate.
        $remove = '/ip firewall address-list remove [find list="' . $list . '" address=' . $targetIp . ']';
        $add    = '/ip firewall address-list add list="' . $list . '" address=' . $targetIp . ' comment="' . $comm . '"';

        return ':do { ' . $remove . ' } on-error={}; ' . $add;
    }

    // ==================== PER-PLAN: PCQ ENGINE ====================

    /**
     * Build/refresh the PCQ engine for a plan on the client router: pcq queue
     * types (per-client cap), mangle rules marking the address-list traffic,
     * and the queue trees that apply the pcq types. Idempotent: existing
     * objects (matched by name/comment) are removed then re-added.
     */
    public function syncPcqEngine(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $planName,
        string $speedUp,
        string $speedDown,
        ?string $pcqRate = null,
        ?string $addressMask = null,
        int $clientPort = 8728
    ): array {
        try {
            $clientCommand = $this->buildPcqEngineCommand($planName, $speedUp, $speedDown, $pcqRate, $addressMask);

            Log::info('[PcqManager] Syncing PCQ engine', [
                'client_ip' => $clientIp,
                'plan'      => $planName,
            ]);

            return $this->runViaCore($clientIp, $clientUser, $clientPass, $clientCommand, 'motor PCQ');
        } catch (\Throwable $e) {
            Log::error('[PcqManager] Error syncing PCQ engine', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    private function buildPcqEngineCommand(
        string $planName,
        string $speedUp,
        string $speedDown,
        ?string $pcqRate,
        ?string $addressMask
    ): string {
        $plan      = $this->escapeRouterOsQuotedValue($planName);
        $downRate  = $this->normalizeSpeed(($pcqRate !== null && trim($pcqRate) !== '') ? $pcqRate : $speedDown);
        $upRate    = $this->normalizeSpeed(($pcqRate !== null && trim($pcqRate) !== '') ? $pcqRate : $speedUp);
        $mask      = ($addressMask !== null && trim($addressMask) !== '') ? (int) $addressMask : 32;

        $typeDown  = 'pcq-down-' . $plan;
        $typeUp    = 'pcq-up-' . $plan;
        $markDown  = $plan . '-down';
        $markUp    = $plan . '-up';
        $mangleCom = 'ISPWatch-PCQ-' . $plan;

        $stmts = [];

        // 1. Tear down (order matters: trees use types, so trees go first).
        $stmts[] = ':do { /queue tree remove [find name="' . $markDown . '"] } on-error={}';
        $stmts[] = ':do { /queue tree remove [find name="' . $markUp . '"] } on-error={}';
        $stmts[] = ':do { /ip firewall mangle remove [find comment="' . $mangleCom . '"] } on-error={}';
        $stmts[] = ':do { /queue type remove [find name="' . $typeDown . '"] } on-error={}';
        $stmts[] = ':do { /queue type remove [find name="' . $typeUp . '"] } on-error={}';

        // 2. Re-create (errors here surface in stdout and are detected).
        $stmts[] = '/queue type add name="' . $typeDown . '" kind=pcq pcq-rate=' . $downRate
            . ' pcq-classifier=dst-address pcq-dst-address-mask=' . $mask;
        $stmts[] = '/queue type add name="' . $typeUp . '" kind=pcq pcq-rate=' . $upRate
            . ' pcq-classifier=src-address pcq-src-address-mask=' . $mask;

        $stmts[] = '/ip firewall mangle add chain=forward dst-address-list="' . $plan . '"'
            . ' action=mark-packet new-packet-mark="' . $markDown . '" passthrough=no comment="' . $mangleCom . '"';
        $stmts[] = '/ip firewall mangle add chain=forward src-address-list="' . $plan . '"'
            . ' action=mark-packet new-packet-mark="' . $markUp . '" passthrough=no comment="' . $mangleCom . '"';

        $stmts[] = '/queue tree add name="' . $markDown . '" parent=global packet-mark="' . $markDown . '"'
            . ' queue="' . $typeDown . '"';
        $stmts[] = '/queue tree add name="' . $markUp . '" parent=global packet-mark="' . $markUp . '"'
            . ' queue="' . $typeUp . '"';

        return implode('; ', $stmts);
    }

    // ==================== SHARED CORE SSH RUNNER ====================

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

            Log::info('[PcqManager] CORE SSH direct: ssh-exec', ['client_ip' => $clientIp, 'what' => $what]);

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
                    'message'    => "No se pudo sincronizar {$what}. Detalle del router: " . $output,
                ];
            }

            Log::info('[PcqManager] CORE SSH direct: success', ['what' => $what]);

            return [
                'success' => true,
                'method'  => 'CORE_SSH_DIRECT',
                'action'  => 'upserted',
                'message' => "{$what} sincronizado via CORE",
            ];
        } catch (\Throwable $e) {
            Log::error('[PcqManager] CORE SSH exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'method' => 'CORE_SSH_DIRECT', 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    private function normalizeSpeed(string $speed): string
    {
        $speed = trim($speed);
        if (is_numeric($speed)) {
            // pcq-rate=0 means unlimited in RouterOS; keep 0 without unit suffix.
            return (int) $speed === 0 ? '0' : $speed . 'M';
        }
        return strtoupper($speed);
    }

    private function escapeRouterOsQuotedValue(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        return addcslashes($value, "\\\"\$");
    }
}
