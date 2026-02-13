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
     * Apply block rules to a client router
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
        ]);

        // Try direct API first
        if ($this->connectionManager->tryDirectClientConnection($clientIp, $apiPort)) {
            $socket = $this->apiProtocol->connect($clientIp, $apiPort, 5);
            if ($socket) {
                Log::info('[FirewallRulesManager] Conexión API directa exitosa');
                return $this->applyBlockRulesDirectApi($socket, $clientUser, $clientPass, $wanInterface, $portalIp);
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

            $commands = [
                "/ip firewall address-list add list=ISPWATCH_SUSPENDIDOS address=0.0.0.0 comment=\"Control ISPWatch\"",
                "/ip firewall nat add chain=dstnat src-address-list=ISPWATCH_SUSPENDIDOS protocol=tcp dst-port=80 action=dst-nat to-addresses={$portalIp} to-ports=80 comment=\"ISPWatch Portal HTTP\"",
                "/ip firewall nat add chain=dstnat src-address-list=ISPWATCH_SUSPENDIDOS protocol=tcp dst-port=443 action=dst-nat to-addresses={$portalIp} to-ports=443 comment=\"ISPWatch Portal HTTPS\"",
                "/ip firewall filter add chain=forward src-address-list=ISPWATCH_SUSPENDIDOS out-interface={$wanInterface} action=drop comment=\"ISPWatch - Bloqueo general\"",
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
     * Apply firewall rules using API protocol
     */
    private function applyFirewallRulesViaApi($socket, string $wanInterface, string $portalIp): void
    {
        // Address-list
        $this->apiProtocol->sendCommand($socket, '/ip/firewall/address-list/add', [
            '=list=ISPWATCH_SUSPENDIDOS',
            '=address=0.0.0.0',
            '=comment=Control ISPWatch',
        ]);
        $this->apiProtocol->readUntilDone($socket);

        // NAT HTTP
        $this->apiProtocol->sendCommand($socket, '/ip/firewall/nat/add', [
            '=chain=dstnat',
            '=src-address-list=ISPWATCH_SUSPENDIDOS',
            '=protocol=tcp',
            '=dst-port=80',
            '=action=dst-nat',
            '=to-addresses=' . $portalIp,
            '=to-ports=80',
            '=comment=ISPWatch Portal HTTP',
        ]);
        $this->apiProtocol->readUntilDone($socket);

        // NAT HTTPS
        $this->apiProtocol->sendCommand($socket, '/ip/firewall/nat/add', [
            '=chain=dstnat',
            '=src-address-list=ISPWATCH_SUSPENDIDOS',
            '=protocol=tcp',
            '=dst-port=443',
            '=action=dst-nat',
            '=to-addresses=' . $portalIp,
            '=to-ports=443',
            '=comment=ISPWatch Portal HTTPS',
        ]);
        $this->apiProtocol->readUntilDone($socket);

        // Filter DROP
        $this->apiProtocol->sendCommand($socket, '/ip/firewall/filter/add', [
            '=chain=forward',
            '=src-address-list=ISPWATCH_SUSPENDIDOS',
            '=out-interface=' . $wanInterface,
            '=action=drop',
            '=comment=ISPWatch - Bloqueo general',
        ]);
        $this->apiProtocol->readUntilDone($socket);
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
