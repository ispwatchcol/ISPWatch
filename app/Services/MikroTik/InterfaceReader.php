<?php

namespace App\Services\MikroTik;

use Illuminate\Support\Facades\Log;

/**
 * Interface Reader
 *
 * Handles reading network interfaces from MikroTik routers.
 * Supports direct API and CORE SSH fallback methods.
 */
class InterfaceReader
{
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
     *   3. SSH via CORE (ssh-exec) as last resort.
     *
     * Returns a `attempts` array so the UI can show what was tried and why each step failed.
     */
    public function getRouterInterfaces(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        int $clientPort = 8728
    ): array {
        $attempts = [];

        try {
            Log::info('[InterfaceReader] Getting interfaces', [
                'client_ip' => $clientIp,
                'configured_port' => $clientPort,
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
            $sshResult = $this->getInterfacesViaCoreSsh($clientIp, $clientUser, $clientPass);
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
     * Attempt a direct API connection on a specific port and read interfaces.
     */
    private function tryDirectApi(string $clientIp, string $clientUser, string $clientPass, int $port): array
    {
        if (!$this->connectionManager->tryDirectClientConnection($clientIp, $port)) {
            return [
                'success' => false,
                'message' => "Puerto {$port} no responde (TCP) desde el servidor.",
                'interfaces' => [],
            ];
        }

        $socket = $this->apiProtocol->connect($clientIp, $port, 5);
        if (!$socket) {
            return [
                'success' => false,
                'message' => "TCP abierto en puerto {$port} pero la API MikroTik no respondió.",
                'interfaces' => [],
            ];
        }

        Log::info('[InterfaceReader] Direct API connection available', ['port' => $port]);
        $result = $this->getInterfacesDirectApi($socket, $clientUser, $clientPass);

        if ($result['success']) {
            $result['port_used'] = $port;
        }

        return $result;
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
            if (!$this->apiProtocol->login($socket, $clientUser, $clientPass)) {
                $this->apiProtocol->close($socket);

                return [
                    'success' => false,
                    'message' => 'Authentication failed on client router',
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
     * This avoids the old API script flow that returned internal ids such as "*2" instead of the real output.
     */
    private function getInterfacesViaCoreSsh(
        string $clientIp,
        string $clientUser,
        string $clientPass
    ): array {
        try {
            $command = $this->buildCoreInterfaceCommand($clientIp, $clientUser, $clientPass);
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

            $output = $this->normalizeRouterOutput((string) ($sshResult['output'] ?? ''));

            Log::info('[InterfaceReader] CORE SSH output', [
                'client_ip' => $clientIp,
                'output_length' => strlen($output),
                'raw_output' => substr($output, 0, 500),
            ]);

            if ($output === '') {
                return [
                    'success' => false,
                    'message' => "El CORE se conecto pero el router cliente ({$clientIp}) no devolvio salida por SSH.",
                    'interfaces' => [],
                ];
            }

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
                    'message' => 'El router respondio pero no se pudieron leer las interfaces. Respuesta del router: ' . substr($output, 0, 200),
                    'interfaces' => [],
                ];
            }

            return [
                'success' => true,
                'message' => 'Interfaces obtenidas via CORE SSH',
                'method' => 'CORE_SSH_EXEC',
                'interfaces' => $interfaces,
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
     * Build the command executed on CORE.
     * We prefer RouterOS "print terse" here because ssh-exec may evaluate variables
     * in nested scripts unexpectedly and return "no such item".
     */
    private function buildCoreInterfaceCommand(string $clientIp, string $clientUser, string $clientPass): string
    {
        $clientCommand = '/interface print terse without-paging';

        return sprintf(
            '/system ssh-exec address="%s" user="%s" password="%s" command="%s"',
            $this->escapeRouterOsQuotedValue($clientIp),
            $this->escapeRouterOsQuotedValue($clientUser),
            $this->escapeRouterOsQuotedValue($clientPass),
            $this->escapeRouterOsQuotedValue($clientCommand),
        );
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

        if (preg_match('/output:\s*(.*)$/si', $output, $matches)) {
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
                str_contains($normalized, 'no route to host')
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
