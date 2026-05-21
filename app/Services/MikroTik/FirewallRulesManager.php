<?php

namespace App\Services\MikroTik;

use Illuminate\Support\Facades\Log;

/**
 * Firewall Rules Manager
 * 
 * Handles ISPWatch firewall rule management on MikroTik routers.
 * Manages address-lists, NAT rules, and filter rules.
 */
class FirewallRulesManager
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
     * Apply block rules to a client router.
     *
     * The ruleset installed (after these fixes) actually blocks suspended clients:
     *
     *   FILTER chain=forward (rules go to top via place-before=0, in REVERSE add order):
     *     [0] accept   src-address-list=ISPWATCH_SUSPENDIDOS dst-address=<portal>   ← portal stays reachable
     *     [1] drop     src-address-list=ISPWATCH_SUSPENDIDOS                          ← unconditional drop, no out-interface dep
     *
     *   NAT chain=dstnat (at top of chain):
     *     [0] dst-nat  src-address-list=ISPWATCH_SUSPENDIDOS proto=tcp dport=80  → <portal>:80
     *     [1] dst-nat  src-address-list=ISPWATCH_SUSPENDIDOS proto=tcp dport=443 → <portal>:443
     *
     * Why each piece matters (lessons from "customer was in the list and never got cut"):
     *   - place-before=0  : original code appended to end-of-chain; pre-existing accept rules
     *                       (established/related/fasttrack) matched FIRST and the drop never ran.
     *   - allow-portal    : without it, after the dst-nat the portal-bound packets would also
     *                       hit the drop rule if the portal is reachable through WAN.
     *   - drop has NO out-interface : the old version required out-interface=<wan_interface>,
     *                       which silently misses everything when the operator picked the wrong
     *                       interface in the DB (e.g. selected ether1 but real WAN is pppoe-out1)
     *                       or when client uses a tunnel as WAN.
     *
     * The caller MUST also flush the suspended IP's active connections after adding it to the
     * address-list, otherwise the stateful conntrack lets in-flight TCP/UDP keep flowing.
     * That is wired up in SuspensionManager::addSuspendedIpViaCore (post-fix).
     */
    public function applyBlockRulesViaCore(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $wanInterface,
        string $portalIp,
        int $apiPort = 8728
    ): array {
        Log::info('[FirewallRulesManager] Aplicando reglas de bloqueo', [
            'client_ip' => $clientIp,
            'portal_ip' => $portalIp,
            'wan_interface' => $wanInterface,
        ]);

        // Try direct API first — MUST go through the SSH tunnel just like InterfaceReader
        // does. The previous code called apiProtocol->connect($clientIp, $apiPort) directly,
        // which silently failed in production (overlay IP unreachable from Laravel) and
        // collapsed into "Error de autenticación" without ever attempting the real network.
        if ($this->connectionManager->tryDirectClientConnection($clientIp, $apiPort)) {
            $tunnelManager = new SshTunnelManager();
            try {
                $tunnel = $tunnelManager->open($clientIp, $apiPort);
            } catch (\Throwable $e) {
                Log::warning('[FirewallRulesManager] Tunnel open failed, falling back to CORE ssh-exec', [
                    'error' => $e->getMessage(),
                ]);
                return $this->applyBlockRulesViaCoreApi($clientIp, $clientUser, $clientPass, $wanInterface, $portalIp);
            }

            try {
                $socket = $this->apiProtocol->connect($tunnel->localHost(), $tunnel->localPort(), 5);
                if ($socket) {
                    Log::info('[FirewallRulesManager] Conexión API directa via túnel exitosa', [
                        'tunnel_local_port' => $tunnel->localPort(),
                    ]);
                    return $this->applyBlockRulesDirectApi($socket, $clientUser, $clientPass, $wanInterface, $portalIp);
                }
                Log::warning('[FirewallRulesManager] Túnel abierto pero la API no respondió, fallback CORE');
            } finally {
                $tunnel->close();
            }
        }

        Log::info('[FirewallRulesManager] API directa no disponible, usando CORE');
        return $this->applyBlockRulesViaCoreApi($clientIp, $clientUser, $clientPass, $wanInterface, $portalIp);
    }

    /**
     * Apply block rules using direct API connection
     */
    private function applyBlockRulesDirectApi(
        $socket,
        string $clientUser,
        string $clientPass,
        string $wanInterface,
        string $portalIp
    ): array {
        try {
            if (!$this->apiProtocol->login($socket, $clientUser, $clientPass)) {
                $this->apiProtocol->close($socket);
                return ['success' => false, 'message' => 'Error de autenticación'];
            }

            $this->applyFirewallRulesViaApi($socket, $wanInterface, $portalIp);
            $this->apiProtocol->close($socket);

            return [
                'success' => true,
                'method' => 'DIRECT_API',
                'message' => 'Reglas de bloqueo aplicadas via API directa',
                'rules_applied' => $this->getRulesAppliedDetails($wanInterface, $portalIp),
            ];

        } catch (\Throwable $e) {
            @$this->apiProtocol->close($socket);
            Log::error('[FirewallRulesManager] Error en API directa', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Apply block rules via CORE ssh-exec
     */
    private function applyBlockRulesViaCoreApi(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $wanInterface,
        string $portalIp
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

            $safePass = str_replace('"', '\\"', $clientPass);

            // Order matters: each rule is added with place-before=0 (jump to top of chain).
            // Adding in REVERSE display order makes the final chain layout match the comment
            // diagram in applyBlockRulesViaCore. Idempotent installs use comment-based find
            // so re-applying does not stack duplicates.
            $commands = [
                // Address-list anchor (so the list exists even before any customer is added).
                ':if ([:len [/ip firewall address-list find list=ISPWATCH_SUSPENDIDOS address=0.0.0.0]] = 0) do={ /ip firewall address-list add list=ISPWATCH_SUSPENDIDOS address=0.0.0.0 comment="ISPWatch placeholder" }',

                // FILTER: drop everything else from suspended (added first → ends up BELOW the allow rule once allow is added next).
                ':if ([:len [/ip firewall filter find comment="ISPWatch-DROP-SUSPENDED"]] = 0) do={ /ip firewall filter add chain=forward src-address-list=ISPWATCH_SUSPENDIDOS action=drop comment="ISPWatch-DROP-SUSPENDED" place-before=0 }',

                // FILTER: allow portal access (added second → ends up at position 0, above the drop).
                ':if ([:len [/ip firewall filter find comment="ISPWatch-ALLOW-PORTAL"]] = 0) do={ /ip firewall filter add chain=forward src-address-list=ISPWATCH_SUSPENDIDOS dst-address=' . $portalIp . ' action=accept comment="ISPWatch-ALLOW-PORTAL" place-before=0 }',

                // NAT: HTTPS redirect (added first → ends up below HTTP).
                ':if ([:len [/ip firewall nat find comment="ISPWatch-NAT-HTTPS"]] = 0) do={ /ip firewall nat add chain=dstnat src-address-list=ISPWATCH_SUSPENDIDOS protocol=tcp dst-port=443 action=dst-nat to-addresses=' . $portalIp . ' to-ports=443 comment="ISPWatch-NAT-HTTPS" place-before=0 }',

                // NAT: HTTP redirect (added second → ends up at position 0).
                ':if ([:len [/ip firewall nat find comment="ISPWatch-NAT-HTTP"]] = 0) do={ /ip firewall nat add chain=dstnat src-address-list=ISPWATCH_SUSPENDIDOS protocol=tcp dst-port=80 action=dst-nat to-addresses=' . $portalIp . ' to-ports=80 comment="ISPWatch-NAT-HTTP" place-before=0 }',
            ];

            $results = [];
            $errors = [];

            foreach ($commands as $index => $cmd) {
                $scriptName = 'ispwatch_temp_' . $index;
                $scriptSource = "/system ssh-exec address={$clientIp} user={$clientUser} password=\"{$safePass}\" command=\"" . addslashes($cmd) . "\"";

                $this->apiProtocol->sendCommand($socket, '/system/script/add', [
                    '=name=' . $scriptName,
                    '=source=' . $scriptSource,
                ]);
                $addError = $this->apiProtocol->readUntilDoneWithError($socket);

                if (!$addError) {
                    $this->apiProtocol->sendCommand($socket, '/system/script/run', [
                        '=number=' . $scriptName,
                    ]);
                    $runError = $this->apiProtocol->readUntilDoneWithError($socket);

                    if ($runError) {
                        $errors[] = "Comando " . ($index + 1) . ": " . $runError;
                    } else {
                        $results[] = "Comando " . ($index + 1) . " ejecutado";
                    }

                    $this->apiProtocol->sendCommand($socket, '/system/script/remove', [
                        '=numbers=' . $scriptName,
                    ]);
                    $this->apiProtocol->readUntilDone($socket);
                } else {
                    $errors[] = "Error creando script " . ($index + 1);
                }

                usleep(300000);
            }

            $this->apiProtocol->close($socket);

            return [
                'success' => true,
                'method' => 'CORE_API_SCRIPT',
                'message' => 'Reglas aplicadas via CORE',
                'rules_applied' => $this->getRulesAppliedDetails($wanInterface, $portalIp),
                'warnings' => $errors,
                'results' => $results,
            ];

        } catch (\Throwable $e) {
            Log::error('[FirewallRulesManager] Error via CORE', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Apply firewall rules using API protocol.
     *
     * See applyBlockRulesViaCore() comment block for the rationale behind each piece
     * (place-before, allow-portal, drop without out-interface, idempotent install).
     * The $wanInterface parameter is kept in the signature for backward compat but
     * is intentionally NOT used — the drop rule is now unconditional on outbound
     * interface so it works regardless of whether the operator picked the right
     * physical port from the dropdown.
     */
    private function applyFirewallRulesViaApi($socket, string $wanInterface, string $portalIp): void
    {
        // Address-list anchor — idempotent: only add the placeholder if not already there.
        if (!$this->ruleExists($socket, '/ip/firewall/address-list/print', '?list=ISPWATCH_SUSPENDIDOS', '?address=0.0.0.0')) {
            $this->apiProtocol->sendCommand($socket, '/ip/firewall/address-list/add', [
                '=list=ISPWATCH_SUSPENDIDOS',
                '=address=0.0.0.0',
                '=comment=ISPWatch placeholder',
            ]);
            $this->apiProtocol->readUntilDone($socket);
        }

        // FILTER: drop rule first (will be position 0 after we push it down with allow).
        // No out-interface dependency: blocks ALL forward traffic from suspended IPs.
        if (!$this->ruleExists($socket, '/ip/firewall/filter/print', '?comment=ISPWatch-DROP-SUSPENDED')) {
            $this->apiProtocol->sendCommand($socket, '/ip/firewall/filter/add', [
                '=chain=forward',
                '=src-address-list=ISPWATCH_SUSPENDIDOS',
                '=action=drop',
                '=comment=ISPWatch-DROP-SUSPENDED',
                '=place-before=0',
            ]);
            $this->apiProtocol->readUntilDone($socket);
        }

        // FILTER: allow-portal — keeps the captive portal reachable; added AFTER drop with
        // place-before=0 so it lands at position 0 and the drop becomes position 1.
        if (!$this->ruleExists($socket, '/ip/firewall/filter/print', '?comment=ISPWatch-ALLOW-PORTAL')) {
            $this->apiProtocol->sendCommand($socket, '/ip/firewall/filter/add', [
                '=chain=forward',
                '=src-address-list=ISPWATCH_SUSPENDIDOS',
                '=dst-address=' . $portalIp,
                '=action=accept',
                '=comment=ISPWatch-ALLOW-PORTAL',
                '=place-before=0',
            ]);
            $this->apiProtocol->readUntilDone($socket);
        }

        // NAT: HTTPS redirect first.
        if (!$this->ruleExists($socket, '/ip/firewall/nat/print', '?comment=ISPWatch-NAT-HTTPS')) {
            $this->apiProtocol->sendCommand($socket, '/ip/firewall/nat/add', [
                '=chain=dstnat',
                '=src-address-list=ISPWATCH_SUSPENDIDOS',
                '=protocol=tcp',
                '=dst-port=443',
                '=action=dst-nat',
                '=to-addresses=' . $portalIp,
                '=to-ports=443',
                '=comment=ISPWatch-NAT-HTTPS',
                '=place-before=0',
            ]);
            $this->apiProtocol->readUntilDone($socket);
        }

        // NAT: HTTP redirect last (ends up at position 0).
        if (!$this->ruleExists($socket, '/ip/firewall/nat/print', '?comment=ISPWatch-NAT-HTTP')) {
            $this->apiProtocol->sendCommand($socket, '/ip/firewall/nat/add', [
                '=chain=dstnat',
                '=src-address-list=ISPWATCH_SUSPENDIDOS',
                '=protocol=tcp',
                '=dst-port=80',
                '=action=dst-nat',
                '=to-addresses=' . $portalIp,
                '=to-ports=80',
                '=comment=ISPWatch-NAT-HTTP',
                '=place-before=0',
            ]);
            $this->apiProtocol->readUntilDone($socket);
        }
    }

    /**
     * Idempotency helper: returns true if a router-side rule already matches the given
     * print query. The MikroTik API accepts multiple ?key=value filters via varargs;
     * we pass them through unchanged.
     */
    private function ruleExists($socket, string $printCmd, string ...$filters): bool
    {
        $this->apiProtocol->sendCommand($socket, $printCmd, $filters);
        $records = $this->apiProtocol->readAllRecords($socket);
        return !empty($records);
    }

    /**
     * Get firewall rules from client router
     */
    public function getFirewallRulesViaCore(
        string $clientIp,
        string $clientUser,
        string $clientPass
    ): array {
        try {
            // Try direct API first
            if ($this->connectionManager->tryDirectClientConnection($clientIp, 8728)) {
                $socket = $this->apiProtocol->connect($clientIp, 8728, 5);
                if ($socket) {
                    return $this->getFirewallRulesDirectApi($socket, $clientUser, $clientPass);
                }
            }

            return $this->getFirewallRulesViaCoreApi($clientIp, $clientUser, $clientPass);

        } catch (\Throwable $e) {
            Log::error('[FirewallRulesManager] Error obteniendo reglas', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Get firewall rules using direct API
     */
    private function getFirewallRulesDirectApi($socket, string $clientUser, string $clientPass): array
    {
        try {
            if (!$this->apiProtocol->login($socket, $clientUser, $clientPass)) {
                $this->apiProtocol->close($socket);
                return ['success' => false, 'message' => 'Error de autenticación'];
            }

            $rules = [
                'address_list' => [],
                'nat' => [],
                'filter' => [],
            ];

            // Get address-list
            $this->apiProtocol->sendCommand($socket, '/ip/firewall/address-list/print', [
                '?list=ISPWATCH_SUSPENDIDOS',
            ]);
            $rules['address_list'] = $this->apiProtocol->readAllRecords($socket);

            // Get NAT rules
            $this->apiProtocol->sendCommand($socket, '/ip/firewall/nat/print', [
                '?comment~ISPWatch',
            ]);
            $rules['nat'] = $this->apiProtocol->readAllRecords($socket);

            // Get filter rules
            $this->apiProtocol->sendCommand($socket, '/ip/firewall/filter/print', [
                '?comment~ISPWatch',
            ]);
            $rules['filter'] = $this->apiProtocol->readAllRecords($socket);

            $this->apiProtocol->close($socket);

            return [
                'success' => true,
                'method' => 'DIRECT_API',
                'rules' => $rules,
                'has_ispwatch_rules' => !empty($rules['address_list']) || !empty($rules['nat']) || !empty($rules['filter']),
            ];

        } catch (\Throwable $e) {
            @$this->apiProtocol->close($socket);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Get firewall rules via CORE (simplified - just checks if configured)
     */
    private function getFirewallRulesViaCoreApi(
        string $clientIp,
        string $clientUser,
        string $clientPass
    ): array {
        // This would require complex ssh-exec parsing
        // For now, return a placeholder indicating CORE check is needed
        return [
            'success' => true,
            'method' => 'CORE_API',
            'message' => 'Verificación via CORE no disponible. Use conexión directa.',
            'rules' => [],
            'has_ispwatch_rules' => null,
        ];
    }

    /**
     * Get standardized rules applied details
     */
    private function getRulesAppliedDetails(string $wanInterface, string $portalIp): array
    {
        return [
            'address_list' => 'ISPWATCH_SUSPENDIDOS',
            'portal_ip' => $portalIp,
            'wan_interface' => $wanInterface,
            'nat_rules' => ['HTTP:80', 'HTTPS:443'],
            'filter_rule' => 'DROP forward to WAN',
        ];
    }
}
