<?php

namespace App\Services\MikroTik;

use App\Services\MikroTik\Concerns\BuildsCoreSshExec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles ISPWatch firewall rule management on MikroTik routers.
 */
class FirewallRulesManager
{
    use BuildsCoreSshExec;

    private const ADDRESS_LIST_NAME = 'ISPWATCH_SUSPENDIDOS';
    private const ADDRESS_LIST_PLACEHOLDER = '0.0.0.0';
    private const ADDRESS_LIST_COMMENT = 'ISPWatch placeholder';

    private const FILTER_ALLOW_COMMENT = 'ISPWatch-ALLOW-PORTAL';
    private const FILTER_DROP_COMMENT = 'ISPWatch-DROP-SUSPENDED';
    private const NAT_HTTP_COMMENT = 'ISPWatch-NAT-HTTP';
    private const NAT_HTTPS_COMMENT = 'ISPWatch-NAT-HTTPS';

    /** @var array<string, string|null> */
    private static array $firmwareVersionCache = [];

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
     * Rules are installed in the filter/nat chains that exist on both RouterOS v6 and v7:
     *   - filter/forward: allow portal, then drop suspended traffic
     *   - nat/dstnat: redirect tcp/80 and tcp/443 to the payment portal
     *
     * Version-awareness matters here mostly for the transport/fallback path:
     *   - some tenants store firmware_version as the raw string ("6.49.10", "7.15.3")
     *   - others store the FK/id from script_version
     *   - CORE ssh-exec behaves differently across RouterOS generations, so we resolve the
     *     real version label and use it to choose the first ssh-exec parsing strategy.
     *
     * The caller still passes $wanInterface for backwards compatibility, but the rules
     * intentionally do NOT match on out-interface. That old shape broke on multi-WAN,
     * PPPoE uplinks, wrong DB picks, and RouterOS v7.14+ VRF/interface semantics.
     */
    public function applyBlockRulesViaCore(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $wanInterface,
        string $portalIp,
        int $apiPort = 8728,
        ?string $clientFirmwareVersion = null,
        ?int $clientSshPort = null
    ): array {
        $resolvedFirmware = $this->resolveFirmwareVersionLabel($clientFirmwareVersion);
        $firmwareFamily = $this->detectRouterOsFamily($clientFirmwareVersion);

        Log::info('[FirewallRulesManager] Aplicando reglas de bloqueo', [
            'client_ip' => $clientIp,
            'portal_ip' => $portalIp,
            'wan_interface' => $wanInterface,
            'firmware_raw' => $clientFirmwareVersion,
            'firmware_resolved' => $resolvedFirmware,
            'firmware_family' => $firmwareFamily,
        ]);

        // Try tunneled direct API first. This is version-agnostic and lets us verify the
        // resulting rule set immediately on the client.
        $socket = $this->connectionManager->connectClientApi($clientIp, $apiPort, $clientUser, $clientPass);
        if ($socket) {
            Log::info('[FirewallRulesManager] Conexión API directa via túnel exitosa');

            try {
                $this->applyFirewallRulesViaApi($socket, $portalIp);
                $this->connectionManager->closeClientApi($socket);

                return [
                    'success' => true,
                    'method' => 'DIRECT_API',
                    'message' => 'Reglas de bloqueo aplicadas via API directa',
                    'rules_applied' => $this->getRulesAppliedDetails($portalIp, $firmwareFamily, $resolvedFirmware),
                ];
            } catch (\Throwable $e) {
                $this->connectionManager->closeClientApi($socket);
                Log::error('[FirewallRulesManager] Error aplicando reglas via API directa, fallback CORE', [
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            Log::info('[FirewallRulesManager] API directa no disponible, usando fallback via CORE');
        }

        $sshResult = $this->applyBlockRulesViaCoreDirectSsh(
            $clientIp,
            $clientUser,
            $clientPass,
            $portalIp,
            $clientFirmwareVersion,
            $clientSshPort
        );
        if ($sshResult['success']) {
            return $sshResult;
        }

        Log::warning('[FirewallRulesManager] CORE SSH directo no confirmó la instalación, usando API-script legacy', [
            'client_ip' => $clientIp,
            'reason' => $sshResult['message'] ?? 'unknown',
        ]);

        return $this->applyBlockRulesViaCoreApi(
            $clientIp,
            $clientUser,
            $clientPass,
            $portalIp,
            $clientFirmwareVersion,
            $clientSshPort
        );
    }

    /**
     * Apply block rules by SSHing to the CORE and using /system ssh-exec.
     *
     * RouterOS documents that ssh-exec returns "exit-code" and "output" when used with
     * as-value. We try the RouterOS 7.x field-extraction form first for v7 routers and the
     * more forgiving :tostr form first for v6/unknown routers, then fall back if needed.
     */
    private function applyBlockRulesViaCoreDirectSsh(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $portalIp,
        ?string $clientFirmwareVersion = null,
        ?int $clientSshPort = null
    ): array {
        try {
            $clientCommand = $this->buildFirewallSyncCommand($portalIp);
            $firmwareFamily = $this->detectRouterOsFamily($clientFirmwareVersion);
            $resolvedFirmware = $this->resolveFirmwareVersionLabel($clientFirmwareVersion);
            $variants = $this->buildCoreFirewallCommandVariants(
                $clientIp,
                $clientUser,
                $clientPass,
                $clientCommand,
                $firmwareFamily,
                $clientSshPort
            );

            $attempts = [];

            foreach ($variants as $variantName => $coreCommand) {
                $sshResult = $this->connectionManager->executeSsh($coreCommand);

                if (!($sshResult['success'] ?? false)) {
                    return [
                        'success' => false,
                        'method' => 'CORE_SSH_DIRECT',
                        'message' => $sshResult['message'] ?? 'No se pudo conectar al CORE via SSH',
                    ];
                }

                $rawOutput = (string) ($sshResult['output'] ?? '');
                $parsed = $this->parseSshExecEnvelope($rawOutput);

                if ($parsed['state'] === 'on_error') {
                    $attempts[] = [
                        'variant' => $variantName,
                        'error' => 'El CORE no pudo ejecutar ssh-exec contra el router cliente.',
                    ];
                    continue;
                }

                $status = $this->extractSshExecStatus($parsed['output'], $parsed['exit_code']);
                $exitCode = $status['exit_code'];
                $output = $status['output'];

                if ($exitCode !== null && $exitCode !== 0) {
                    $attempts[] = [
                        'variant' => $variantName,
                        'error' => $output !== '' ? $output : "exit-code={$exitCode}",
                    ];
                    continue;
                }

                if ($this->isRouterOsErrorOutput($output)) {
                    $attempts[] = [
                        'variant' => $variantName,
                        'error' => $output,
                    ];
                    continue;
                }

                Log::info('[FirewallRulesManager] Reglas aplicadas via CORE SSH directo', [
                    'client_ip' => $clientIp,
                    'variant' => $variantName,
                    'firmware_family' => $firmwareFamily,
                    'firmware_resolved' => $resolvedFirmware,
                ]);

                return [
                    'success' => true,
                    'method' => 'CORE_SSH_DIRECT',
                    'message' => 'Reglas de bloqueo aplicadas via CORE SSH',
                    'variant' => $variantName,
                    'rules_applied' => $this->getRulesAppliedDetails($portalIp, $firmwareFamily, $resolvedFirmware),
                ];
            }

            $lastAttempt = end($attempts);
            return [
                'success' => false,
                'method' => 'CORE_SSH_DIRECT',
                'message' => 'No se pudo confirmar la instalación de reglas via CORE SSH'
                    . ($lastAttempt ? ': ' . $lastAttempt['error'] : '.'),
                'attempts' => $attempts,
            ];
        } catch (\Throwable $e) {
            Log::error('[FirewallRulesManager] CORE SSH direct exception', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'method' => 'CORE_SSH_DIRECT',
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Last-resort fallback: CORE API creates a temporary script that performs the same
     * single RouterOS command we use in the validated SSH path.
     */
    private function applyBlockRulesViaCoreApi(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $portalIp,
        ?string $clientFirmwareVersion = null,
        ?int $clientSshPort = null
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

            $clientCommand = $this->buildFirewallSyncCommand($portalIp);
            $safePass = $this->escapeRouterOsQuotedValue($clientPass);
            $safeClientIp = $this->escapeRouterOsQuotedValue($clientIp);
            $safeClientUser = $this->escapeRouterOsQuotedValue($clientUser);
            $safeClientCommand = $this->escapeRouterOsQuotedValue($clientCommand);

            $scriptSource = '/system ssh-exec address="' . $safeClientIp . '"'
                . $this->sshExecPortArg($clientSshPort)
                . ' user="' . $safeClientUser . '"'
                . ' password="' . $safePass . '"'
                . ' command="' . $safeClientCommand . '"';

            $this->runRouterScriptViaApi($socket, $scriptSource, 'ispwatch_fw_api_');
            $this->apiProtocol->close($socket);

            return [
                'success' => true,
                'method' => 'CORE_API_SCRIPT',
                'message' => 'Reglas aplicadas via CORE API/script',
                'rules_applied' => $this->getRulesAppliedDetails(
                    $portalIp,
                    $this->detectRouterOsFamily($clientFirmwareVersion),
                    $this->resolveFirmwareVersionLabel($clientFirmwareVersion)
                ),
            ];
        } catch (\Throwable $e) {
            Log::error('[FirewallRulesManager] Error via CORE API/script', [
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Apply rules on the client directly via API.
     *
     * Instead of only "add if missing", we run a corrective RouterOS script that:
     *   - keeps/creates the address-list anchor
     *   - removes the old legacy-comment rules
     *   - ensures the canonical rules exist
     *   - sets the current desired properties (portal IP, ports, action, disabled=no)
     *   - moves them back to the top so re-applying actually fixes broken order
     */
    private function applyFirewallRulesViaApi($socket, string $portalIp): void
    {
        $this->runRouterScriptViaApi($socket, $this->buildFirewallSyncCommand($portalIp), 'ispwatch_fw_cli_');
        $this->assertManagedRulesPresentViaApi($socket, $portalIp);
    }

    private function runRouterScriptViaApi($socket, string $scriptSource, string $scriptPrefix): void
    {
        $scriptName = str_starts_with($scriptPrefix, 'ispwatch_')
            ? $scriptPrefix . substr(uniqid('', false), -6)
            : 'ispwatch_' . $scriptPrefix . substr(uniqid('', false), -6);

        $created = false;

        try {
            $this->apiProtocol->sendCommand($socket, '/system/script/add', [
                '=name=' . $scriptName,
                '=source=' . $scriptSource,
            ]);
            $addError = $this->apiProtocol->readUntilDoneWithError($socket);

            if ($addError) {
                throw new \RuntimeException('Error creando script: ' . $addError);
            }

            $created = true;

            $this->apiProtocol->sendCommand($socket, '/system/script/run', [
                '=number=' . $scriptName,
            ]);
            $runError = $this->apiProtocol->readUntilDoneWithError($socket);

            if ($runError) {
                throw new \RuntimeException('Error ejecutando script: ' . $runError);
            }
        } finally {
            if ($created) {
                $this->apiProtocol->sendCommand($socket, '/system/script/remove', [
                    '=numbers=' . $scriptName,
                ]);
                $this->apiProtocol->readUntilDone($socket);
            }
        }
    }

    private function assertManagedRulesPresentViaApi($socket, string $portalIp): void
    {
        $checks = [
            'address-list anchor' => $this->ruleExists(
                $socket,
                '/ip/firewall/address-list/print',
                '?list=' . self::ADDRESS_LIST_NAME,
                '?address=' . self::ADDRESS_LIST_PLACEHOLDER
            ),
            'drop filter' => $this->ruleExists(
                $socket,
                '/ip/firewall/filter/print',
                '?comment=' . self::FILTER_DROP_COMMENT,
                '?chain=forward',
                '?action=drop'
            ),
            'allow filter' => $this->ruleExists(
                $socket,
                '/ip/firewall/filter/print',
                '?comment=' . self::FILTER_ALLOW_COMMENT,
                '?chain=forward',
                '?action=accept',
                '?dst-address=' . $portalIp
            ),
            'nat https' => $this->ruleExists(
                $socket,
                '/ip/firewall/nat/print',
                '?comment=' . self::NAT_HTTPS_COMMENT,
                '?chain=dstnat',
                '?dst-port=443',
                '?to-addresses=' . $portalIp
            ),
            'nat http' => $this->ruleExists(
                $socket,
                '/ip/firewall/nat/print',
                '?comment=' . self::NAT_HTTP_COMMENT,
                '?chain=dstnat',
                '?dst-port=80',
                '?to-addresses=' . $portalIp
            ),
        ];

        $missing = array_keys(array_filter($checks, static fn (bool $exists): bool => !$exists));

        if ($missing !== []) {
            throw new \RuntimeException(
                'No se pudieron verificar todas las reglas en el router cliente. Faltan: ' . implode(', ', $missing)
            );
        }
    }

    /**
     * Idempotency helper: returns true if a router-side rule already matches the given
     * print query. The MikroTik API accepts multiple ?key=value filters via varargs.
     */
    private function ruleExists($socket, string $printCmd, string ...$filters): bool
    {
        $this->apiProtocol->sendCommand($socket, $printCmd, $filters);
        $records = $this->apiProtocol->readAllRecords($socket);
        return !empty($records);
    }

    public function getFirewallRulesViaCore(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        ?int $clientSshPort = null
    ): array {
        // $clientSshPort is accepted for signature parity with the write path;
        // this read only uses the API tunnel (no ssh-exec), so it is unused here.
        try {
            $socket = $this->connectionManager->connectClientApi($clientIp, 8728, $clientUser, $clientPass);
            if ($socket) {
                return $this->getFirewallRulesDirectApi($socket);
            }

            return $this->getFirewallRulesViaCoreApi($clientIp, $clientUser, $clientPass);
        } catch (\Throwable $e) {
            Log::error('[FirewallRulesManager] Error obteniendo reglas', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    private function getFirewallRulesDirectApi($socket): array
    {
        try {
            $rules = [
                'address_list' => [],
                'nat' => [],
                'filter' => [],
            ];

            $this->apiProtocol->sendCommand($socket, '/ip/firewall/address-list/print', [
                '?list=' . self::ADDRESS_LIST_NAME,
            ]);
            $rules['address_list'] = $this->apiProtocol->readAllRecords($socket);

            $this->apiProtocol->sendCommand($socket, '/ip/firewall/nat/print', [
                '?comment~ISPWatch',
            ]);
            $rules['nat'] = $this->apiProtocol->readAllRecords($socket);

            $this->apiProtocol->sendCommand($socket, '/ip/firewall/filter/print', [
                '?comment~ISPWatch',
            ]);
            $rules['filter'] = $this->apiProtocol->readAllRecords($socket);

            $this->connectionManager->closeClientApi($socket);

            return [
                'success' => true,
                'method' => 'DIRECT_API',
                'rules' => $rules,
                'has_ispwatch_rules' => !empty($rules['address_list']) || !empty($rules['nat']) || !empty($rules['filter']),
            ];
        } catch (\Throwable $e) {
            $this->connectionManager->closeClientApi($socket);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    private function getFirewallRulesViaCoreApi(
        string $clientIp,
        string $clientUser,
        string $clientPass
    ): array {
        return [
            'success' => true,
            'method' => 'CORE_API',
            'message' => 'Verificación via CORE no disponible. Use conexión directa.',
            'rules' => [],
            'has_ispwatch_rules' => null,
        ];
    }

    private function buildFirewallSyncCommand(string $portalIp): string
    {
        $commands = [
            ':do { /ip firewall address-list add list=' . self::ADDRESS_LIST_NAME
                . ' address=' . self::ADDRESS_LIST_PLACEHOLDER
                . ' comment="' . self::ADDRESS_LIST_COMMENT . '" } on-error={}',

            ':do { /ip firewall filter remove [find comment="ISPWatch - Bloqueo general"] } on-error={}',
            ':do { /ip firewall nat remove [find comment="ISPWatch Portal HTTP"] } on-error={}',
            ':do { /ip firewall nat remove [find comment="ISPWatch Portal HTTPS"] } on-error={}',

            // Every statement MUST be wrapped in :do { ... } on-error={}. The whole
            // compound is joined with `;` and run as one RouterOS script — any
            // unwrapped failure (e.g. `set [find]` on an empty result because the
            // previous `add` silently no-op'd) aborts the entire script and the
            // remaining rules never get installed. That is what produced "DROP
            // exists but ALLOW-PORTAL is missing" after re-applying.
            ':do { /ip firewall filter add chain=forward src-address-list=' . self::ADDRESS_LIST_NAME
                . ' action=drop comment="' . self::FILTER_DROP_COMMENT . '" } on-error={}',
            ':do { /ip firewall filter set [find comment="' . self::FILTER_DROP_COMMENT . '"]'
                . ' chain=forward src-address-list=' . self::ADDRESS_LIST_NAME
                . ' action=drop disabled=no } on-error={}',
            ':do { /ip firewall filter move [find comment="' . self::FILTER_DROP_COMMENT . '"] 0 } on-error={}',

            ':do { /ip firewall filter add chain=forward src-address-list=' . self::ADDRESS_LIST_NAME
                . ' dst-address=' . $portalIp
                . ' action=accept comment="' . self::FILTER_ALLOW_COMMENT . '" } on-error={}',
            ':do { /ip firewall filter set [find comment="' . self::FILTER_ALLOW_COMMENT . '"]'
                . ' chain=forward src-address-list=' . self::ADDRESS_LIST_NAME
                . ' dst-address=' . $portalIp
                . ' action=accept disabled=no } on-error={}',
            ':do { /ip firewall filter move [find comment="' . self::FILTER_ALLOW_COMMENT . '"] 0 } on-error={}',

            ':do { /ip firewall nat add chain=dstnat src-address-list=' . self::ADDRESS_LIST_NAME
                . ' protocol=tcp dst-port=443 action=dst-nat to-addresses=' . $portalIp
                . ' to-ports=443 comment="' . self::NAT_HTTPS_COMMENT . '" } on-error={}',
            ':do { /ip firewall nat set [find comment="' . self::NAT_HTTPS_COMMENT . '"]'
                . ' chain=dstnat src-address-list=' . self::ADDRESS_LIST_NAME
                . ' protocol=tcp dst-port=443 action=dst-nat to-addresses=' . $portalIp
                . ' to-ports=443 disabled=no } on-error={}',
            ':do { /ip firewall nat move [find comment="' . self::NAT_HTTPS_COMMENT . '"] 0 } on-error={}',

            ':do { /ip firewall nat add chain=dstnat src-address-list=' . self::ADDRESS_LIST_NAME
                . ' protocol=tcp dst-port=80 action=dst-nat to-addresses=' . $portalIp
                . ' to-ports=80 comment="' . self::NAT_HTTP_COMMENT . '" } on-error={}',
            ':do { /ip firewall nat set [find comment="' . self::NAT_HTTP_COMMENT . '"]'
                . ' chain=dstnat src-address-list=' . self::ADDRESS_LIST_NAME
                . ' protocol=tcp dst-port=80 action=dst-nat to-addresses=' . $portalIp
                . ' to-ports=80 disabled=no } on-error={}',
            ':do { /ip firewall nat move [find comment="' . self::NAT_HTTP_COMMENT . '"] 0 } on-error={}',
        ];

        return implode('; ', $commands);
    }

    /**
     * Build the CORE-side ssh-exec commands. v7 routers prefer reading the explicit
     * output/exit-code fields first, while v6/unknown start with the more tolerant
     * :tostr form and then fall back.
     *
     * @return array<string, string>
     */
    private function buildCoreFirewallCommandVariants(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        string $clientCommand,
        string $firmwareFamily,
        ?int $clientSshPort = null
    ): array {
        // RouterOS defaults ssh-exec to 22; routers serving SSH elsewhere refuse
        // the connect and every variant fails with `<connection failed>`.
        $portArg = $this->sshExecPortArg($clientSshPort);

        $ip = $this->escapeRouterOsQuotedValue($clientIp);
        $user = $this->escapeRouterOsQuotedValue($clientUser);
        $pass = $this->escapeRouterOsQuotedValue($clientPass);
        $cmd = $this->escapeRouterOsQuotedValue($clientCommand);

        $outputFieldEnvelope =
            ':put "ISP_BEGIN"; ' .
            ':local out ""; :local ec -1; ' .
            ':do { ' .
                ':local res [/system ssh-exec address="' . $ip . '"' . $portArg . ' user="' . $user . '" password="' . $pass . '" command="' . $cmd . '" as-value]; ' .
                ':set out ($res->"output"); :set ec ($res->"exit-code") ' .
            '} on-error={ :set out "ISP_FAIL"; :set ec 255 }; ' .
            ':put $out; :put ("ISP_END:" . [:tostr $ec])';

        $tostrEnvelope =
            ':put "ISP_BEGIN"; ' .
            ':local out ""; :local ec -1; ' .
            ':do { ' .
                ':local res [/system ssh-exec address="' . $ip . '"' . $portArg . ' user="' . $user . '" password="' . $pass . '" command="' . $cmd . '" as-value]; ' .
                ':set out [:tostr $res]; :set ec 0 ' .
            '} on-error={ :set out "ISP_FAIL"; :set ec 255 }; ' .
            ':put $out; :put ("ISP_END:" . [:tostr $ec])';

        $legacyAutoprint = sprintf(
            '/system ssh-exec address="%s"%s user="%s" password="%s" command="%s"',
            $ip,
            $portArg,
            $user,
            $pass,
            $cmd
        );

        if ($firmwareFamily === 'v7') {
            return [
                'output_field_envelope' => $outputFieldEnvelope,
                'tostr_envelope' => $tostrEnvelope,
                'legacy_autoprint' => $legacyAutoprint,
            ];
        }

        return [
            'tostr_envelope' => $tostrEnvelope,
            'output_field_envelope' => $outputFieldEnvelope,
            'legacy_autoprint' => $legacyAutoprint,
        ];
    }

    private function parseSshExecEnvelope(string $raw): array
    {
        $clean = preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $raw) ?? $raw;
        $clean = str_replace("\r", '', $clean);

        $beginPos = strpos($clean, 'ISP_BEGIN');
        $endPos = strpos($clean, 'ISP_END');

        if ($beginPos === false || $endPos === false || $endPos < $beginPos) {
            return ['state' => 'no_markers', 'output' => trim($clean), 'exit_code' => null];
        }

        $payload = trim(substr($clean, $beginPos + strlen('ISP_BEGIN'), $endPos - $beginPos - strlen('ISP_BEGIN')));
        $exitCode = null;

        if (preg_match('/ISP_END:?\s*(-?\d+)/', $clean, $matches)) {
            $exitCode = (int) $matches[1];
        }

        if (stripos($payload, 'ISP_FAIL') !== false) {
            return ['state' => 'on_error', 'output' => '', 'exit_code' => $exitCode];
        }

        return ['state' => 'ok', 'output' => $payload, 'exit_code' => $exitCode];
    }

    private function extractSshExecStatus(string $payload, ?int $fallbackExitCode): array
    {
        $clean = trim(str_replace("\r", '', $payload));
        $exitCode = $fallbackExitCode;
        $output = $clean;

        if (preg_match('/exit-code[=:]\s*(-?\d+)/i', $clean, $matches)) {
            $exitCode = (int) $matches[1];
        }

        if (preg_match('/output[=:]\s*(.*)$/is', $clean, $matches)) {
            $output = trim($matches[1]);
        }

        $output = str_replace(["\\r\\n", "\\n", "\\r"], ["\n", "\n", "\r"], $output);

        return [
            'exit_code' => $exitCode,
            'output' => trim($output),
        ];
    }

    private function isRouterOsErrorOutput(string $output): bool
    {
        if ($output === '') {
            return false;
        }

        return preg_match(
            '/\berror\b|\bfailure\b|\bcannot\b|\brefused\b|no such item|syntax error|match any value|permission denied|access denied|invalid/i',
            $output
        ) === 1;
    }

    private function escapeRouterOsQuotedValue(string $value): string
    {
        return addcslashes($value, "\\\"\$");
    }

    private function resolveFirmwareVersionLabel(?string $rawValue): ?string
    {
        if ($rawValue === null) {
            return null;
        }

        $value = trim((string) $rawValue);
        if ($value === '') {
            return null;
        }

        if (!preg_match('/^\d+$/', $value)) {
            return $value;
        }

        if (array_key_exists($value, self::$firmwareVersionCache)) {
            return self::$firmwareVersionCache[$value];
        }

        try {
            $resolved = DB::table('script_version')->where('id', (int) $value)->value('version');
            self::$firmwareVersionCache[$value] = $resolved !== null ? trim((string) $resolved) : $value;
        } catch (\Throwable $e) {
            Log::warning('[FirewallRulesManager] No se pudo resolver firmware_version desde script_version', [
                'firmware_value' => $value,
                'error' => $e->getMessage(),
            ]);
            self::$firmwareVersionCache[$value] = $value;
        }

        return self::$firmwareVersionCache[$value];
    }

    private function detectRouterOsFamily(?string $rawValue): string
    {
        $resolved = strtolower(trim((string) ($this->resolveFirmwareVersionLabel($rawValue) ?? '')));

        if ($resolved === '') {
            return 'unknown';
        }

        if (preg_match('/(^|[^0-9])6(?:[.x]|$)/', $resolved)) {
            return 'v6';
        }

        if (preg_match('/(^|[^0-9])7(?:[.x]|$)/', $resolved)) {
            return 'v7';
        }

        return 'unknown';
    }

    private function getRulesAppliedDetails(string $portalIp, string $firmwareFamily, ?string $resolvedFirmware): array
    {
        return [
            'address_list' => self::ADDRESS_LIST_NAME,
            'portal_ip' => $portalIp,
            'routeros_family' => $firmwareFamily,
            'routeros_version' => $resolvedFirmware,
            'filter_rules' => [
                self::FILTER_ALLOW_COMMENT . ' (accept forward → portal)',
                self::FILTER_DROP_COMMENT . ' (drop forward suspendidos)',
            ],
            'nat_rules' => [
                self::NAT_HTTP_COMMENT . ' (dstnat tcp/80 → portal)',
                self::NAT_HTTPS_COMMENT . ' (dstnat tcp/443 → portal)',
            ],
        ];
    }
}
