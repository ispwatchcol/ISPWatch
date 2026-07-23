<?php

namespace App\Services\MikroTik;

use App\Services\MikroTik\Concerns\BuildsCoreSshExec;
use Illuminate\Support\Facades\Log;

/**
 * Suspension Manager
 * 
 * Handles client suspension/activation via IP address-lists on MikroTik routers.
 * Supports direct API and CORE ssh-exec methods.
 */
class SuspensionManager
{
    use BuildsCoreSshExec;

    private MikroTikConnectionManager $connectionManager;
    private MikroTikApiProtocol $apiProtocol;

    public function __construct(
        ?MikroTikConnectionManager $connectionManager = null,
        ?MikroTikApiProtocol $apiProtocol = null
    ) {
        $this->connectionManager = $connectionManager ?? new MikroTikConnectionManager();
        $this->apiProtocol = $apiProtocol ?? $this->connectionManager->getApiProtocol();
    }

    // ==================== INPUT HARDENING ====================

    /**
     * SECURITY (OWASP A03): customer-controlled values flow into RouterOS
     * commands and /system/script sources. Reject anything that is not a
     * real IP so an address/target field can never carry script payload.
     */
    private function isValidIp(string $ip): bool
    {
        return filter_var(trim($ip), FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Strip characters that have meaning inside a RouterOS quoted string /
     * script ("$ ; { } [ ] \ " backtick, control chars). The comment is a
     * cosmetic label, so this neutralises injection without changing
     * provisioning behaviour.
     */
    private function sanitizeRouterComment(string $value): string
    {
        $value = preg_replace('/[^\p{L}\p{N}\s._\-:@#]/u', '', $value) ?? '';
        return mb_substr(trim($value), 0, 60);
    }

    // ==================== CORE ROUTER OPERATIONS ====================

    /**
     * Add IP to suspended address-list on CORE
     */
    public function addSuspendedIp(string $ip, string $comment = ''): array
    {
        if (!$this->isValidIp($ip)) {
            return ['success' => false, 'message' => 'IP inválida.'];
        }

        $cmd = sprintf(
            '/ip firewall address-list add list=ISPWATCH_SUSPENDIDOS address=%s comment="%s"',
            trim($ip),
            $this->sanitizeRouterComment($comment)
        );

        return $this->connectionManager->executeSsh($cmd);
    }

    /**
     * Remove IP from suspended address-list on CORE
     */
    public function removeSuspendedIp(string $ip): array
    {
        if (!$this->isValidIp($ip)) {
            return ['success' => false, 'message' => 'IP inválida.'];
        }

        $removeCmd = sprintf(
            '/ip firewall address-list remove [find list=ISPWATCH_SUSPENDIDOS address=%s]',
            trim($ip)
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
        int $clientPort = 8728,
        ?int $clientSshPort = null
    ): array {
        if (!$this->isValidIp($suspendedIp)) {
            return ['success' => false, 'message' => 'IP del cliente inválida.'];
        }
        // Neutralise RouterOS script metacharacters before the name reaches
        // any command/script source (OWASP A03 — authenticated RCE path).
        $customerName = $this->sanitizeRouterComment($customerName);

        try {
            Log::info('[SuspensionManager] Agregando IP a lista de suspendidos en router cliente', [
                'client_ip' => $clientIp,
                'suspended_ip' => $suspendedIp,
                'customer' => $customerName,
            ]);

            // Tunnelled API — connectClientApi() handles SSH local-forward + login.
            // The old code called apiProtocol->connect($clientIp, ...) directly,
            // which silently failed in production (overlay IPs aren't routable).
            $socket = $this->connectionManager->connectClientApi($clientIp, $clientPort, $clientUser, $clientPass);
            if ($socket) {
                Log::info('[SuspensionManager] Conexión API directa al cliente via túnel exitosa');
                return $this->addSuspendedIpDirectApi($socket, $clientUser, $clientPass, $suspendedIp, $customerName);
            }

            Log::info('[SuspensionManager] API directa no disponible, usando CORE ssh-exec');
            return $this->addSuspendedIpViaCoreNetwork($clientIp, $clientUser, $clientPass, $suspendedIp, $customerName, $clientSshPort);

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
        int $clientPort = 8728,
        ?int $clientSshPort = null
    ): array {
        if (!$this->isValidIp($suspendedIp)) {
            return ['success' => false, 'message' => 'IP del cliente inválida.'];
        }

        try {
            Log::info('[SuspensionManager] Removiendo IP de lista de suspendidos', [
                'client_ip' => $clientIp,
                'suspended_ip' => $suspendedIp,
            ]);

            // Tunnelled API — connectClientApi() handles SSH local-forward + login.
            $socket = $this->connectionManager->connectClientApi($clientIp, $clientPort, $clientUser, $clientPass);
            if ($socket) {
                Log::info('[SuspensionManager] Conexión API directa al cliente via túnel exitosa');
                return $this->removeSuspendedIpDirectApi($socket, $clientUser, $clientPass, $suspendedIp);
            }

            Log::info('[SuspensionManager] API directa no disponible, usando CORE ssh-exec');
            return $this->removeSuspendedIpViaCoreNetwork($clientIp, $clientUser, $clientPass, $suspendedIp, $clientSshPort);

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
            // Socket is already authenticated by connectClientApi — go straight to operations.

            $this->apiProtocol->sendCommand($socket, '/ip/firewall/address-list/add', [
                '=list=ISPWATCH_SUSPENDIDOS',
                '=address=' . $suspendedIp,
                '=comment=ISPWatch - Cliente: ' . $customerName,
            ]);
            $error = $this->apiProtocol->readUntilDoneWithError($socket);

            // CRITICAL: even with the IP now in the list, the stateful conntrack lets every
            // in-flight TCP/UDP keep flowing (matched by accept established,related at the
            // top of the chain). Without flushing, a customer "blocked" mid-session keeps
            // browsing on existing flows until they idle out — minutes or hours. Flush every
            // connection whose src OR dst is the suspended IP so the next packet has to
            // pass the freshly-installed drop rule.
            $connectionsKilled = $this->flushConnectionsForIpDirectApi($socket, $suspendedIp);

            $this->connectionManager->closeClientApi($socket);

            if ($error && !str_contains($error, 'already have')) {
                return ['success' => false, 'message' => 'Error al agregar IP: ' . $error];
            }

            return [
                'success' => true,
                'method' => 'DIRECT_API',
                'message' => 'Cliente suspendido - IP agregada y conexiones activas cerradas',
                'details' => [
                    'ip' => $suspendedIp,
                    'customer' => $customerName,
                    'connections_killed' => $connectionsKilled,
                ],
            ];

        } catch (\Throwable $e) {
            $this->connectionManager->closeClientApi($socket);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Flush every active connection-tracking entry whose src or dst is the given IP.
     *
     * Returns the number of entries removed (best-effort — we don't fail the suspend
     * if conntrack is empty or the API rejects a delete, the drop rule will catch the
     * next packet anyway).
     */
    private function flushConnectionsForIpDirectApi($socket, string $ip): int
    {
        $killed = 0;

        try {
            // Find connections involving the IP (src OR dst). RouterOS API filters are AND,
            // so we issue two queries and dedupe by .id.
            $ids = [];
            foreach (['src-address', 'dst-address'] as $field) {
                $this->apiProtocol->sendCommand($socket, '/ip/firewall/connection/print', [
                    '?' . $field . '~' . $ip,
                    '.proplist=.id',
                ]);
                foreach ($this->apiProtocol->readAllRecords($socket) as $rec) {
                    if (!empty($rec['.id'])) {
                        $ids[$rec['.id']] = true;
                    }
                }
            }

            foreach (array_keys($ids) as $id) {
                $this->apiProtocol->sendCommand($socket, '/ip/firewall/connection/remove', [
                    '=.id=' . $id,
                ]);
                $err = $this->apiProtocol->readUntilDoneWithError($socket);
                if (!$err) {
                    $killed++;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[SuspensionManager] flush connections failed (non-fatal)', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('[SuspensionManager] flushed conntrack entries', ['ip' => $ip, 'killed' => $killed]);
        return $killed;
    }

    private function removeSuspendedIpDirectApi(
        $socket,
        string $clientUser,
        string $clientPass,
        string $suspendedIp
    ): array {
        try {
            // Socket is already authenticated by connectClientApi — go straight to operations.

            // List ALL entries in ISPWATCH_SUSPENDIDOS (no address filter) and compare
            // in PHP. RouterOS stores address-list entries inconsistently across
            // versions: a value added as `192.168.1.5` may come back as `192.168.1.5`
            // OR `192.168.1.5/32` depending on the API view, so `?address=X` exact
            // filtering would miss the entry and leave the client suspended.
            $this->apiProtocol->sendCommand($socket, '/ip/firewall/address-list/print', [
                '?list=ISPWATCH_SUSPENDIDOS',
                '.proplist=.id,address',
            ]);
            $records = $this->apiProtocol->readAllRecords($socket);

            $target = $this->normalizeAddressListAddress($suspendedIp);
            $idsToRemove = [];
            foreach ($records as $record) {
                if (empty($record['.id'])) {
                    continue;
                }
                if ($this->normalizeAddressListAddress($record['address'] ?? '') === $target) {
                    $idsToRemove[] = $record['.id'];
                }
            }

            if ($idsToRemove === []) {
                $this->connectionManager->closeClientApi($socket);
                return [
                    'success' => true,
                    'method' => 'DIRECT_API',
                    'message' => 'Cliente ya estaba activo (IP no en lista)',
                ];
            }

            foreach ($idsToRemove as $id) {
                $this->apiProtocol->sendCommand($socket, '/ip/firewall/address-list/remove', [
                    '=.id=' . $id,
                ]);
                $this->apiProtocol->readUntilDone($socket);
            }

            $this->connectionManager->closeClientApi($socket);

            return [
                'success' => true,
                'method' => 'DIRECT_API',
                'message' => 'Cliente activado - IP removida',
                'details' => [
                    'ip' => $suspendedIp,
                    'entries_removed' => count($idsToRemove),
                ],
            ];

        } catch (\Throwable $e) {
            $this->connectionManager->closeClientApi($socket);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Drop a `/32` suffix and whitespace so the same address compares equal
     * regardless of how RouterOS surfaced it back through the API.
     */
    private function normalizeAddressListAddress(string $value): string
    {
        $trimmed = trim($value);
        if (str_ends_with($trimmed, '/32')) {
            $trimmed = substr($trimmed, 0, -3);
        }
        return $trimmed;
    }

    // ==================== CORE SSH-EXEC METHODS ====================

    private function addSuspendedIpViaCoreNetwork(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $suspendedIp,
        string $customerName,
        ?int $clientSshPort = null
    ): array {
        // Two RouterOS statements in one script:
        //   1. add the IP to the suspended list (idempotent — ignore "already have")
        //   2. remove every conntrack entry whose src/dst is that IP so in-flight TCP/UDP
        //      can't keep flowing past the freshly-installed drop rule.
        // Wrapped in :do/on-error so a benign "already have" on step 1 doesn't skip step 2.
        // Quotes here MUST be plain `"`, not `\"`. The whole compound is later
        // run through addslashes() inside executeSshExecViaCore(), which adds the
        // backslash needed to survive the CORE-side RouterOS script parser. Using
        // `\"` in the PHP source double-escapes and the client router receives a
        // bare backslash before each quote — `add` then aborts silently inside the
        // :do/on-error wrapper, and the IP never reaches the address-list.
        $compoundCmd =
            ':do { /ip firewall address-list add list=ISPWATCH_SUSPENDIDOS address=' . $suspendedIp .
            ' comment="Cliente: ' . $customerName . '" } on-error={}; ' .
            ':do { /ip firewall connection remove [find where src-address~"' . $suspendedIp .
            '" or dst-address~"' . $suspendedIp . '"] } on-error={}';

        return $this->executeSshExecViaCore(
            $clientIp,
            $clientUser,
            $clientPass,
            $compoundCmd,
            'ispwatch_s_',
            'suspend',
            $suspendedIp,
            $clientSshPort
        );
    }

    private function removeSuspendedIpViaCoreNetwork(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $suspendedIp,
        ?int $clientSshPort = null
    ): array {
        // Address-list entries may come back as `X.X.X.X` or `X.X.X.X/32` depending
        // on the RouterOS build. `[find address=X]` with a single literal misses
        // the other form and the entry stays in the list, leaving the customer
        // suspended forever. Match against both, and wrap each in :do/on-error so
        // an empty find doesn't abort the script.
        $cmd =
            ':do { /ip firewall address-list remove [find list=ISPWATCH_SUSPENDIDOS address="' . $suspendedIp . '"] } on-error={}; ' .
            ':do { /ip firewall address-list remove [find list=ISPWATCH_SUSPENDIDOS address="' . $suspendedIp . '/32"] } on-error={}';

        return $this->executeSshExecViaCore(
            $clientIp,
            $clientUser,
            $clientPass,
            $cmd,
            'ispwatch_a_',
            'activate',
            $suspendedIp,
            $clientSshPort
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
        string $targetIp,
        ?int $clientSshPort = null
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
            $scriptName = $scriptPrefix . substr(uniqid(), -6);
            $scriptSource = $this->coreSshExecCommand($clientIp, $clientUser, $clientPass, $command, $clientSshPort);

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
