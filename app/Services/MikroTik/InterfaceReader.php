<?php

namespace App\Services\MikroTik;

use App\Services\MikroTik\Concerns\BuildsCoreSshExec;
use Illuminate\Support\Facades\Log;

/**
 * Interface Reader
 *
 * Handles reading network interfaces from MikroTik routers.
 * Supports direct API and CORE SSH fallback methods.
 */
class InterfaceReader
{
    use BuildsCoreSshExec;

    private const SSH_FIELD_DELIMITER = '|#|';
    // Only physical ports are valid WAN candidates — no VLANs, bridges, or tunnels.
    private const ALLOWED_WAN_TYPES = ['ether', 'sfp'];

    private MikroTikConnectionManager $connectionManager;
    private MikroTikApiProtocol $apiProtocol;

    // Tunnel-type names that should be excluded if found as substring in either type or name
    // (these strings don't appear in normal physical-port names).
    private array $excludedTunnelTypes = ['l2tp', 'pptp', 'pppoe', 'ovpn', 'sstp', 'gre', 'ipip', 'eoip'];

    // Non-physical interface types — exact match against the type field only.
    private array $excludedExactTypes = ['vlan', 'bridge', 'bond', 'wlan', 'lte', 'vrrp', 'vpls', 'vxlan'];

    public function __construct(
        ?MikroTikConnectionManager $connectionManager = null,
        ?MikroTikApiProtocol $apiProtocol = null
    ) {
        $this->connectionManager = $connectionManager ?? new MikroTikConnectionManager();
        $this->apiProtocol = $apiProtocol ?? $this->connectionManager->getApiProtocol();
    }

    /**
     * Get interfaces from a client router.
     *
     * Tries, in order:
     *   1. Direct API on the configured DB port (router.puerto_api).
     *   2. Direct API on the factory default port (8728) — only if it differs from the configured one.
     *   3. SSH via CORE (ssh-exec) as last resort, with version-aware command formatting.
     *
     * $clientFirmwareVersion (e.g. "6.49.10" or "7.15.3") is used to pick a syntax
     * the *client's* RouterOS will understand; "unknown" or null = try both v7 and v6 forms.
     *
     * Returns a `attempts` array so the UI can show what was tried and why each step failed.
     */
    public function getRouterInterfaces(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        int $clientPort = 8728,
        ?string $clientFirmwareVersion = null,
        ?int $clientSshPort = null
    ): array {
        $attempts = [];

        try {
            Log::info('[InterfaceReader] Getting interfaces', [
                'client_ip' => $clientIp,
                'configured_port' => $clientPort,
                'client_ssh_port' => $clientSshPort ?? 22,
                'client_firmware' => $clientFirmwareVersion,
            ]);

            // ── Attempt 1: API on the configured DB port ─────────────────────────
            $apiResult = $this->tryDirectApi($clientIp, $clientUser, $clientPass, $clientPort);
            $attempts[] = [
                'method' => 'API directa',
                'port' => $clientPort,
                'success' => $apiResult['success'],
                'message' => $apiResult['message'],
            ];
            if ($apiResult['success']) {
                $apiResult['attempts'] = $attempts;
                return $apiResult;
            }
            // If the API authenticated and ran /interface/print (raw_count is set), we already
            // have the router's definitive answer — falling back to SSH would only hide the real
            // diagnostic (trap message or filter exclusion details).
            if (array_key_exists('raw_count', $apiResult)) {
                $apiResult['attempts'] = $attempts;
                return $apiResult;
            }

            // ── Attempt 2: API on the factory default 8728, if different ─────────
            if ($clientPort !== 8728) {
                $apiResult2 = $this->tryDirectApi($clientIp, $clientUser, $clientPass, 8728);
                $attempts[] = [
                    'method' => 'API directa (puerto de fábrica)',
                    'port' => 8728,
                    'success' => $apiResult2['success'],
                    'message' => $apiResult2['message'],
                ];
                if ($apiResult2['success']) {
                    $apiResult2['attempts'] = $attempts;
                    return $apiResult2;
                }
                if (array_key_exists('raw_count', $apiResult2)) {
                    $apiResult2['attempts'] = $attempts;
                    return $apiResult2;
                }
            }

            // ── Attempt 3: SSH via CORE as last resort ───────────────────────────
            Log::info('[InterfaceReader] Direct API unavailable, using CORE SSH fallback');
            $sshResult = $this->getInterfacesViaCoreSsh($clientIp, $clientUser, $clientPass, $clientFirmwareVersion);
            $attempts[] = [
                'method' => 'SSH vía CORE',
                'port' => null,
                'success' => $sshResult['success'],
                'message' => $sshResult['message'],
            ];

            $sshResult['attempts'] = $attempts;

            if (!$sshResult['success']) {
                // Build a clear consolidated error for the UI
                $sshResult['message'] = $this->buildConsolidatedError($attempts);
                $sshResult['hint'] = $sshResult['hint'] ?? 'Verifica que el router cliente tenga el servicio API activo (puerto 8728 por defecto) y/o que el CORE pueda hacer SSH al cliente.';
            }

            return $sshResult;
        } catch (\Throwable $e) {
            Log::error('[InterfaceReader] Error getting interfaces', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'attempts' => $attempts,
                'interfaces' => [],
            ];
        }
    }

    /**
     * Attempt an API connection on a specific port and read interfaces.
     *
     * Client routers live behind the L2TP overlay on the CORE; we go through
     * an SSH local-forward tunnel opened by SshTunnelManager. The probe via
     * tryDirectClientConnection() is kept first so existing test doubles that
     * mock it to return false (forcing the SSH-via-CORE fallback path) keep
     * working unchanged.
     */
    private function tryDirectApi(string $clientIp, string $clientUser, string $clientPass, int $port): array
    {
        if (!$this->connectionManager->tryDirectClientConnection($clientIp, $port)) {
            return [
                'success' => false,
                'message' => "Puerto {$port} no alcanzable a través del túnel CORE hacia {$clientIp}.",
                'hint'    => "El túnel SSH al CORE abre, pero el TCP a {$clientIp}:{$port} no completa. " .
                             "Causas típicas: (1) servicio API del cliente deshabilitado — verifica /ip service where name=api en el cliente. " .
                             "(2) firewall del cliente bloquea {$port} en el chain input. " .
                             "(3) el túnel L2TP/SSTP entre CORE y cliente está caído o con un solo sentido — desde el CORE prueba: /ping {$clientIp}.",
                'interfaces' => [],
            ];
        }

        // Open the actual API socket via a fresh tunnel. tryDirectClientConnection
        // closed its probe tunnel already; we want a dedicated one bound to the
        // lifecycle of this API session.
        $tunnelManager = new SshTunnelManager();
        try {
            $tunnel = $tunnelManager->open($clientIp, $port);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => "No se pudo abrir túnel SSH al puerto {$port}: " . $e->getMessage(),
                'interfaces' => [],
            ];
        }

        try {
            $socket = $this->apiProtocol->connect($tunnel->localHost(), $tunnel->localPort(), 5);
            if (!$socket) {
                return [
                    'success' => false,
                    'message' => "Túnel SSH abierto pero la API MikroTik en {$port} no respondió.",
                    'interfaces' => [],
                ];
            }

            Log::info('[InterfaceReader] Direct API connection available via tunnel', [
                'port' => $port,
                'tunnel_local_port' => $tunnel->localPort(),
            ]);
            $result = $this->getInterfacesDirectApi($socket, $clientUser, $clientPass);

            if ($result['success']) {
                $result['port_used'] = $port;
            }

            return $result;
        } finally {
            // getInterfacesDirectApi already closes $socket via apiProtocol->close.
            // Closing the tunnel here is what stops the ssh subprocess.
            $tunnel->close();
        }
    }

    /**
     * Build a single error message summarising every attempt and its failure reason.
     */
    private function buildConsolidatedError(array $attempts): string
    {
        $lines = ['No se pudo obtener las interfaces. Se intentaron los siguientes métodos:'];
        foreach ($attempts as $i => $a) {
            $portLabel = $a['port'] ? " (puerto {$a['port']})" : '';
            $lines[] = ($i + 1) . ". {$a['method']}{$portLabel}: {$a['message']}";
        }
        return implode("\n", $lines);
    }

    /**
     * Get interfaces using a direct API connection to the client router.
     */
    private function getInterfacesDirectApi($socket, string $clientUser, string $clientPass): array
    {
        try {
            $loginResult = $this->apiProtocol->loginDetailed($socket, $clientUser, $clientPass);
            if (!$loginResult['success']) {
                $this->apiProtocol->close($socket);

                if ($loginResult['reason'] === 'socket_closed') {
                    return [
                        'success' => false,
                        'message' => 'El router cerró la conexión durante el login API (sin enviar respuesta).',
                        'hint'    => 'Causa más probable: RouterOS "Login Protection" bloqueó temporalmente la IP del CORE ' .
                                     'tras varios intentos fallidos. Espera ~5 minutos e inténtalo de nuevo. ' .
                                     'Para evitarlo, agrega la IP del CORE a la lista blanca en el router cliente: ' .
                                     '/ip firewall filter add chain=input src-address=<IP_CORE> action=accept comment="ISPWatch-CORE" (antes de la regla de bloqueo). ' .
                                     'También verifica /ip settings en el router para ver o limpiar Login Protection.',
                        'interfaces' => [],
                    ];
                }

                $trapMsg = $loginResult['message'] ?? '';
                return [
                    'success' => false,
                    'message' => 'Credenciales incorrectas en el router cliente' .
                                 ($trapMsg ? ": {$trapMsg}" : ' (el router rechazó el login con !trap).'),
                    'hint'    => 'Verifica que user_rb y password_rb en la base de datos coincidan exactamente ' .
                                 'con las credenciales configuradas en el router cliente (distingo de mayúsculas). ' .
                                 'En el router: /user print.',
                    'interfaces' => [],
                ];
            }

            // Use /interface/ethernet/print instead of /interface/print to target only physical
            // ports. A busy PPPoE/SSTP CORE can have 200+ dynamic tunnel entries; the generic
            // print command buries the ether/sfp ports past the maxWords safety limit.
            $this->apiProtocol->sendCommand($socket, '/interface/ethernet/print');
            $status = $this->apiProtocol->readAllRecordsWithStatus($socket);
            $this->apiProtocol->close($socket);

            $records = $status['records'] ?? [];
            $trap = $status['trap'] ?? null;
            $connectionClosed = $status['connection_closed'] ?? false;
            $rawCount = count($records);

            // Router dropped the connection mid-read. This is NOT "0 records" — it's a transport
            // failure. The most common cause is an "Available From" ACL on /ip service api that
            // doesn't include the server's source IP: TCP completes, login appears fine, but the
            // service layer kills the session as soon as the real query starts.
            if ($connectionClosed) {
                return [
                    'success' => false,
                    'message' => 'El router cerró la conexión durante la consulta /interface/ethernet/print (sin enviar !done ni !trap).',
                    'hint' => 'Causa típica: la IP de origen del servidor no está en "Available From" de /ip service api en el router. Verifica que la IP del servidor de producción esté incluida en el ACL del servicio API.',
                    'interfaces' => [],
                    'raw_count' => 0,
                    'connection_closed' => true,
                ];
            }

            // API rejected the command — almost always a missing "read" policy on the API user.
            if ($trap !== null) {
                return [
                    'success' => false,
                    'message' => "El router respondió con error a /interface/ethernet/print: {$trap}.",
                    'hint' => 'Suele significar que el usuario API no tiene la policy "read" sobre /interface. Verifica en el router /user group del grupo asignado al usuario API.',
                    'interfaces' => [],
                    'raw_count' => $rawCount,
                ];
            }

            $interfaces = $this->parseInterfaceRecords($records);
            $filteredOutCount = $rawCount - count($interfaces);

            // API connected, returned data, but every single record was filtered out.
            if ($rawCount > 0 && empty($interfaces)) {
                return [
                    'success' => false,
                    'message' => "El router devolvió {$rawCount} interfaces ethernet, pero todas fueron excluidas por el filtro (VLANs, bridges, etc.).",
                    'hint' => 'Si tu WAN es un túnel (pppoe, sstp, l2tp) o un bridge, no aparecerá en la lista — el filtro sólo acepta puertos físicos. Ingresa el nombre manualmente abajo.',
                    'interfaces' => [],
                    'raw_count' => $rawCount,
                    'filtered_out' => $filteredOutCount,
                ];
            }

            // API connected, command executed cleanly, but the table really is empty.
            if ($rawCount === 0) {
                return [
                    'success' => false,
                    'message' => 'El router respondió !done pero /interface/ethernet/print devolvió 0 registros.',
                    'hint' => 'Esto es raro — revisa que el usuario API tenga visibilidad sobre /interface/ethernet, o que no haya una restricción inusual en /user group.',
                    'interfaces' => [],
                    'raw_count' => 0,
                ];
            }

            $message = "Interfaces loaded via direct API ({$rawCount} ethernet, " . count($interfaces) . ' disponibles)';

            return [
                'success' => true,
                'message' => $message,
                'method' => 'DIRECT_API',
                'interfaces' => $interfaces,
                'raw_count' => $rawCount,
                'filtered_out' => $filteredOutCount,
            ];
        } catch (\Throwable $e) {
            @$this->apiProtocol->close($socket);

            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'interfaces' => [],
            ];
        }
    }

    /**
     * Get interfaces by connecting to CORE over SSH and delegating an ssh-exec to the client router.
     *
     * Strategy (because the RouterOS ssh-exec output format changed between v6 and v7 and
     * the CORE we're sitting on may be either):
     *   - Build a SINGLE robust script that wraps the call in :do {} on-error={} so we always
     *     get *something* back (even if the inner ssh fails). The script emits sentinel markers
     *     (ISP_BEGIN / ISP_END / ISP_FAIL) plus the exit-code so we can tell apart:
     *       (a) "CORE could not reach client SSH at all" (script aborts in on-error)
     *       (b) "CORE reached client but auth/command failed" (non-zero exit-code, empty output)
     *       (c) "Everything ok, here is the interface dump" (exit-code=0, output non-empty)
     *   - If the first attempt comes back empty (very old RouterOS or weirdness), retry with
     *     the legacy "auto-print" form that older v6 builds expect.
     */
    private function getInterfacesViaCoreSsh(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        ?string $clientFirmwareVersion = null
    ): array {
        try {
            $variants = $this->buildCoreInterfaceCommandVariants($clientIp, $clientUser, $clientPass, $clientSshPort);

            $lastRawOutput = '';
            $lastExitCode  = null;
            $allRawOutputs = [];

            foreach ($variants as $variantName => $command) {
                $sshResult = $this->connectionManager->executeSsh($command);

                if (!($sshResult['success'] ?? false)) {
                    $innerMessage = $sshResult['message'] ?? 'sin respuesta del CORE';
                    $isCoreUnreachable = stripos($innerMessage, 'no se pudo conectar') !== false
                        || stripos($innerMessage, 'connection refused') !== false
                        || stripos($innerMessage, 'timed out') !== false;

                    return [
                        'success' => false,
                        'message' => $isCoreUnreachable
                            ? 'El servidor no pudo conectar SSH al CORE. Verifica que el CORE esté encendido, que su servicio SSH esté activo y que las credenciales SSH del CORE configuradas en el servidor sean correctas.'
                            : 'No se pudo consultar el router cliente desde el CORE: ' . $innerMessage,
                        'hint' => $isCoreUnreachable
                            ? 'Esto es un problema entre el servidor y el CORE — no entre el CORE y el router cliente. La VPN del cliente está OK; lo que falla es el SSH del servidor al CORE.'
                            : 'Verifica que el router cliente tenga el servicio SSH activo y que las credenciales (user_rb / password_rb) sean correctas.',
                        'interfaces' => [],
                    ];
                }

                $rawSshOutput = (string) ($sshResult['output'] ?? '');
                $allRawOutputs[$variantName] = $rawSshOutput;

                $parsed   = $this->parseSshExecEnvelope($rawSshOutput);
                $output   = $this->normalizeRouterOutput($parsed['output']);
                $exitCode = $parsed['exit_code'];

                Log::info('[InterfaceReader] CORE SSH attempt', [
                    'client_ip'           => $clientIp,
                    'variant'             => $variantName,
                    'client_firmware'     => $clientFirmwareVersion,
                    'envelope_state'      => $parsed['state'], // 'ok' | 'on_error' | 'no_markers'
                    'exit_code'           => $exitCode,
                    'normalized_length'   => strlen($output),
                    'raw_length'          => strlen($rawSshOutput),
                    'raw_output_preview'  => substr($rawSshOutput, 0, 800),
                ]);

                $lastRawOutput = $rawSshOutput;
                $lastExitCode  = $exitCode;

                // CORE script ran but its on-error block fired — the inner /system ssh-exec threw.
                // Almost always: CORE can't reach the client's SSH (port filtered, service off,
                // L2TP overlay down) OR auth failed at the client side. Surface a precise hint
                // instead of letting it fall through as "empty output".
                if ($parsed['state'] === 'on_error') {
                    return [
                        'success' => false,
                        'message' => "El CORE no pudo ejecutar ssh-exec contra el router cliente ({$clientIp}). RouterOS reportó un error interno al intentar la conexión SSH.",
                        'hint'    => "Esto suele significar una de tres cosas (verifícalas en este orden):\n" .
                                     "1) El router cliente NO acepta SSH desde la IP overlay del CORE — agrega la IP del CORE a la lista blanca: /ip firewall filter del cliente, o desactiva temporalmente para probar.\n" .
                                     "2) Credenciales (user_rb / password_rb) incorrectas en la BD para este router.\n" .
                                     "3) El servicio SSH del cliente está deshabilitado o en otro puerto: /ip service print en el cliente.\n" .
                                     "Diagnóstico rápido desde el CORE: /system ssh user={$clientUser} address={$clientIp} (sin ssh-exec, interactivo). Si tampoco entra, el problema es del router cliente.",
                        'interfaces' => [],
                    ];
                }

                // Got a real output payload — break out and parse it.
                if ($output !== '') {
                    if ($errorMessage = $this->extractRouterCommandError($output)) {
                        return [
                            'success' => false,
                            'message' => $errorMessage,
                            'interfaces' => [],
                        ];
                    }

                    $interfaces = $this->parseRouterOutput($output);

                    if (empty($interfaces)) {
                        return [
                            'success' => false,
                            'message' => 'El router respondió pero no se pudieron leer las interfaces. Respuesta del router: ' . substr($output, 0, 200),
                            'interfaces' => [],
                        ];
                    }

                    return [
                        'success' => true,
                        'message' => 'Interfaces obtenidas via CORE SSH (' . $variantName . ')',
                        'method'  => 'CORE_SSH_EXEC',
                        'interfaces' => $interfaces,
                    ];
                }

                // Empty after normalize — fall through to the next variant (different RouterOS quirks).
            }

            // All variants returned empty / unparseable. Build the most informative error we can.
            return [
                'success' => false,
                'message' => "El CORE recibió respuesta del ssh-exec pero la salida quedó vacía después de probar " . count($variants) . " variantes de comando" .
                             ($lastExitCode !== null ? " (último exit-code reportado: {$lastExitCode})" : '') . ".",
                'hint'    => "Esto indica que /system ssh-exec en el CORE retornó un valor sin la lista de interfaces. Posibles causas:\n" .
                             "1) Auth del CORE→cliente falló silenciosamente (RouterOS no escribe a stdout en este caso): verifica user_rb/password_rb.\n" .
                             "2) El cliente respondió pero el comando interno (/interface ethernet print terse) no produjo salida: revisa políticas del usuario API del cliente.\n" .
                             "3) Versión del CORE incompatible con ssh-exec script-mode: si tu CORE es RouterOS 6.x antiguo, podría necesitar otra sintaxis.\n" .
                             "Captura del raw output (logs): " . substr($lastRawOutput, 0, 200),
                'interfaces' => [],
            ];
        } catch (\Throwable $e) {
            Log::error('[InterfaceReader] CORE SSH fallback failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error interno: ' . $e->getMessage(),
                'interfaces' => [],
            ];
        }
    }

    /**
     * Parse the envelope produced by buildCoreInterfaceCommandVariants().
     *
     * The CORE script we send emits, regardless of RouterOS version:
     *   ISP_BEGIN
     *   <result-as-string>      ← either the inner ssh-exec :tostr, or "ISP_FAIL" if it threw
     *   ISP_END:<exit-code>
     *
     * If the markers are missing (some RouterOS 6.x builds don't run :do/:set the same way),
     * we fall back to treating the whole stdout as the output payload (legacy mode).
     *
     * Returns: ['state' => 'ok'|'on_error'|'no_markers', 'output' => string, 'exit_code' => ?int]
     */
    private function parseSshExecEnvelope(string $raw): array
    {
        $clean = preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $raw) ?? $raw;
        $clean = str_replace("\r", '', $clean);

        $beginPos = strpos($clean, 'ISP_BEGIN');
        $endPos   = strpos($clean, 'ISP_END');

        if ($beginPos === false || $endPos === false || $endPos < $beginPos) {
            // No envelope — legacy / unknown shape. Hand the whole thing back as output.
            return ['state' => 'no_markers', 'output' => trim($clean), 'exit_code' => null];
        }

        $payload = substr($clean, $beginPos + strlen('ISP_BEGIN'), $endPos - $beginPos - strlen('ISP_BEGIN'));
        $payload = trim($payload);

        $exitCode = null;
        if (preg_match('/ISP_END:?\s*(-?\d+)/', $clean, $m)) {
            $exitCode = (int) $m[1];
        }

        if (stripos($payload, 'ISP_FAIL') !== false) {
            return ['state' => 'on_error', 'output' => '', 'exit_code' => $exitCode];
        }

        return ['state' => 'ok', 'output' => $payload, 'exit_code' => $exitCode];
    }

    /**
     * Build the command(s) executed on CORE to get client interfaces.
     *
     * Returns an ordered map of [variantName => command]. The caller tries each in
     * order until one returns usable output. We need multiple variants because
     * `/system ssh-exec` behaves differently across RouterOS versions:
     *
     *   - RouterOS 7.22+: returns an array {exit-code; output} as a VALUE that is
     *     NOT auto-printed when invoked from a non-interactive SSH exec channel.
     *     The output is invisible to phpseclib unless we explicitly :put it.
     *
     *   - RouterOS 7.x (older): same as above, plus the output array layout shifted
     *     across point releases — some builds still let you do :put [...]] directly,
     *     others need :put [:tostr [...]].
     *
     *   - RouterOS 6.x: returns the output as a string and auto-prints it in
     *     interactive mode. In script/exec mode behaviour varies — some builds
     *     auto-print, some don't. The :put-with-tostr form works either way.
     *
     * To make this robust, every variant wraps the call in :do {} on-error={} so
     * a hard ssh failure on the inner connection (auth fail at the client,
     * unreachable SSH port, etc.) doesn't abort the whole script silently.
     * Sentinel markers ISP_BEGIN/ISP_END let us tell apart "script never ran"
     * from "script ran but client side failed" from "script ran and got data".
     */
    private function buildCoreInterfaceCommandVariants(string $clientIp, string $clientUser, string $clientPass, ?int $clientSshPort = null): array
    {
        $clientCommand = '/interface ethernet print terse';

        // RouterOS defaults ssh-exec to 22; routers that serve SSH elsewhere
        // (CORE_TOCAIMA uses 2200) refuse the connect and every variant below
        // fails identically with `<connection failed>`.
        $portArg = $this->sshExecPortArg($clientSshPort);

        $ip   = $this->escapeRouterOsQuotedValue($clientIp);
        $user = $this->escapeRouterOsQuotedValue($clientUser);
        $pass = $this->escapeRouterOsQuotedValue($clientPass);
        $cmd  = $this->escapeRouterOsQuotedValue($clientCommand);

        // Variant A — explicit :tostr wrap. This is the most universal form.
        // Works on RouterOS 7.x (where ssh-exec returns an array) AND 6.x (where it
        // returns a string), because :tostr formats both into a printable string.
        // The envelope (:put markers) is always written, so even if the inner ssh
        // throws we still see ISP_BEGIN/ISP_FAIL/ISP_END and don't get "empty output".
        $variantTostr =
            ':put "ISP_BEGIN"; ' .
            ':local r ""; ' .
            ':local ec -1; ' .
            ':do { ' .
                ':local res [/system ssh-exec address="' . $ip . '"' . $portArg . ' user="' . $user . '" password="' . $pass . '" command="' . $cmd . '"]; ' .
                ':set r [:tostr $res]; ' .
                ':set ec 0 ' .
            '} on-error={ :set r "ISP_FAIL"; :set ec 1 }; ' .
            ':put $r; ' .
            ':put ("ISP_END:" . [:tostr $ec])';

        // Variant B — extract the "output" field directly (RouterOS 7.x array shape).
        // If ssh-exec returned a string instead (6.x), the ->"output" lookup throws
        // and our on-error block catches it; the caller will then fall back to A.
        $variantOutputField =
            ':put "ISP_BEGIN"; ' .
            ':local r ""; ' .
            ':local ec -1; ' .
            ':do { ' .
                ':local res [/system ssh-exec address="' . $ip . '"' . $portArg . ' user="' . $user . '" password="' . $pass . '" command="' . $cmd . '"]; ' .
                ':set r ($res->"output"); ' .
                ':set ec ($res->"exit-code") ' .
            '} on-error={ :set r "ISP_FAIL"; :set ec 1 }; ' .
            ':put $r; ' .
            ':put ("ISP_END:" . [:tostr $ec])';

        // Variant C — legacy RouterOS 6.x: just run ssh-exec and let RouterOS auto-print.
        // Kept as the very last fallback. No envelope here because RouterOS 6.x
        // sometimes can't run the multi-statement form via SSH exec channel.
        $variantLegacy = sprintf(
            '/system ssh-exec address="%s"%s user="%s" password="%s" command="%s"',
            $ip, $portArg, $user, $pass, $cmd
        );

        return [
            'tostr_envelope'        => $variantTostr,
            'output_field_envelope' => $variantOutputField,
            'legacy_autoprint'      => $variantLegacy,
        ];
    }

    /**
     * Parse interface records from a RouterOS API response.
     */
    private function parseInterfaceRecords(array $records): array
    {
        $interfaces = [];

        foreach ($records as $record) {
            $name = $record['name'] ?? '';
            $type = $record['type'] ?? 'unknown';

            if (!$name || $this->shouldExcludeInterface($name, $type)) {
                continue;
            }

            $interfaces[] = [
                'name' => $name,
                'type' => $type,
                'running' => ($record['running'] ?? 'false') === 'true',
                'disabled' => ($record['disabled'] ?? 'false') === 'true',
                'comment' => $record['comment'] ?? '',
            ];
        }

        return $interfaces;
    }

    /**
     * Try the structured SSH output first and then fall back to classic terse parsing.
     */
    private function parseRouterOutput(string $output): array
    {
        $interfaces = $this->parseStructuredOutput($output);

        if (!empty($interfaces)) {
            return $interfaces;
        }

        return $this->parseTerseOutput($output);
    }

    private function parseStructuredOutput(string $output): array
    {
        $interfaces = [];
        $lines = preg_split('/\n+/', $output) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if (
                $line === '' ||
                !str_contains($line, self::SSH_FIELD_DELIMITER) ||
                !str_contains($line, 'name=')
            ) {
                continue;
            }

            if (!str_starts_with($line, 'name=')) {
                $line = substr($line, strpos($line, 'name='));
            }

            $fields = [];

            foreach (explode(self::SSH_FIELD_DELIMITER, $line) as $segment) {
                $segment = trim($segment);

                if (!str_contains($segment, '=')) {
                    continue;
                }

                [$key, $value] = explode('=', $segment, 2);
                $fields[strtolower(trim($key))] = trim($value, " \t\n\r\0\x0B\"'");
            }

            $name = $fields['name'] ?? '';
            $type = $fields['type'] ?? $this->inferInterfaceType($name);

            if (!$name || $this->shouldExcludeInterface($name, $type)) {
                continue;
            }

            $interfaces[] = [
                'name' => $name,
                'type' => $type,
                'running' => $this->toBool($fields['running'] ?? 'false'),
                'disabled' => $this->toBool($fields['disabled'] ?? 'false'),
                'comment' => '',
            ];
        }

        return $interfaces;
    }

    /**
     * Parse classic RouterOS "print terse" style output.
     */
    private function parseTerseOutput(string $output): array
    {
        $interfaces = [];
        $lines = preg_split('/\n+/', $output) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if (
                $line === '' ||
                str_starts_with($line, 'Flags:') ||
                str_starts_with($line, '[') ||
                str_ends_with($line, '>') ||
                str_ends_with($line, '] >')
            ) {
                continue;
            }

            $name = $this->extractTerseField($line, 'name');

            if ($name === null || $name === '') {
                continue;
            }

            $type = $this->inferInterfaceType($name);

            $typeField = $this->extractTerseField($line, 'type');
            if ($typeField !== null && $typeField !== '') {
                $type = $typeField;
            }

            if ($this->shouldExcludeInterface($name, $type)) {
                continue;
            }

            preg_match('/^\d+\s+([A-Z]+)/', $line, $flagMatch);
            $flags = $flagMatch[1] ?? '';

            $interfaces[] = [
                'name' => $name,
                'type' => $type,
                'running' => str_contains($flags, 'R') || str_contains($line, 'running=yes') || str_contains($line, 'running=true'),
                'disabled' => str_contains($flags, 'X') || str_contains($line, 'disabled=yes') || str_contains($line, 'disabled=true'),
                'comment' => '',
            ];
        }

        return $interfaces;
    }

    private function shouldExcludeInterface(string $name, string $type): bool
    {
        // Tunnel-type substring match (in either name or type)
        foreach ($this->excludedTunnelTypes as $excluded) {
            if (stripos($type, $excluded) !== false || stripos($name, $excluded) !== false) {
                return true;
            }
        }

        // VLAN sub-interfaces named like "ether1.100" or "sfp1-vlan10" — anything with a dot or "vlan" tag
        if (preg_match('/\.\d+$/', $name) || stripos($name, '.vlan') !== false || stripos($name, '-vlan') !== false) {
            return true;
        }

        // Non-physical exact type match (vlan/bridge/bond/etc.)
        $normalizedType = strtolower(trim($type));
        if (in_array($normalizedType, $this->excludedExactTypes, true)) {
            return true;
        }

        if (stripos($name, 'ISPWatch-VPN') !== false) {
            return true;
        }

        return !$this->isAllowedWanType($type, $name);
    }

    private function escapeRouterOsQuotedValue(string $value): string
    {
        return addcslashes($value, "\\\"");
    }

    private function normalizeRouterOutput(string $output): string
    {
        $output = preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $output) ?? $output;
        $output = str_replace("\r", '', $output);
        $output = trim($output);

        // RouterOS terminal formats ssh-exec output as "output=<content>" (RouterOS 6/7 terminal)
        // or "output: <content>" (some versions). Extract the content part in either case.
        if (preg_match('/output[=:]\s*(.*)$/si', $output, $matches)) {
            $output = $matches[1];
        }

        $output = str_replace(["\\r\\n", "\\n", "\\r"], ["\n", "\n", "\r"], $output);

        return trim($output);
    }

    private function extractRouterCommandError(string $output): ?string
    {
        $lines = preg_split('/\n+/', $output) ?: [];

        foreach ($lines as $line) {
            $normalized = strtolower(trim($line));

            if ($normalized === '') {
                continue;
            }

            if (
                str_contains($normalized, 'failure:') ||
                str_contains($normalized, 'no such item') ||
                str_contains($normalized, 'permission denied') ||
                str_contains($normalized, 'timed out') ||
                str_contains($normalized, 'connection closed') ||
                str_contains($normalized, 'bad command name') ||
                str_contains($normalized, 'syntax error') ||
                str_contains($normalized, 'invalid user name or password') ||
                str_contains($normalized, 'no route to host') ||
                str_contains($normalized, 'login error') ||
                str_contains($normalized, "can't connect") ||
                str_contains($normalized, 'cannot connect') ||
                str_contains($normalized, 'failed to connect') ||
                str_contains($normalized, 'connection refused') ||
                str_contains($normalized, 'host unreachable') ||
                str_contains($normalized, 'could not connect') ||
                str_contains($normalized, 'ssh: connect') ||
                str_contains($normalized, 'authentication failed') ||
                str_contains($normalized, 'access denied')
            ) {
                return 'Error consultando interfaces desde el CORE: ' . trim($line);
            }
        }

        return null;
    }

    private function inferInterfaceType(string $name): string
    {
        $normalized = strtolower($name);

        return match (true) {
            str_starts_with($normalized, 'ether') => 'ether',
            str_starts_with($normalized, 'sfp') => 'sfp',
            str_starts_with($normalized, 'bridge') => 'bridge',
            str_starts_with($normalized, 'vlan') => 'vlan',
            str_starts_with($normalized, 'wlan') => 'wlan',
            str_starts_with($normalized, 'lte') => 'lte',
            str_starts_with($normalized, 'bond') => 'bond',
            default => 'unknown',
        };
    }

    private function extractTerseField(string $line, string $field): ?string
    {
        $pattern = '/\b' . preg_quote($field, '/') . '=(?:"([^"]*)"|(.*?))(?=\s+[a-zA-Z0-9_-]+=|$)/';

        if (!preg_match($pattern, $line, $matches)) {
            return null;
        }

        return trim(($matches[1] ?? '') !== '' ? $matches[1] : ($matches[2] ?? ''));
    }

    private function isAllowedWanType(string $type, string $name = ''): bool
    {
        $normalizedType = strtolower(trim($type));
        $normalizedName = strtolower(trim($name));

        foreach (self::ALLOWED_WAN_TYPES as $allowedType) {
            if (
                $normalizedType === $allowedType ||
                str_starts_with($normalizedType, $allowedType) ||
                str_starts_with($normalizedName, $allowedType)
            ) {
                return true;
            }
        }

        return false;
    }

    private function toBool(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Kept for future fallbacks if interface discovery needs a manual suggestion set.
     */
    private function getSuggestedInterfaces(): array
    {
        return [
            ['name' => 'ether1', 'type' => 'ether', 'running' => true, 'disabled' => false, 'comment' => 'Typical WAN'],
            ['name' => 'ether2', 'type' => 'ether', 'running' => true, 'disabled' => false, 'comment' => ''],
            ['name' => 'bridge', 'type' => 'bridge', 'running' => true, 'disabled' => false, 'comment' => 'LAN bridge'],
        ];
    }
}
