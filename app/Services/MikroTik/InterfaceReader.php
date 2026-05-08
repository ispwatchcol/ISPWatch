<?php

namespace App\Services\MikroTik;

use Illuminate\Support\Facades\Log;

/**
 * Interface Reader
 * 
 * Handles reading network interfaces from MikroTik routers.
 * Supports direct API and CORE ssh-exec methods.
 */
class InterfaceReader
{
    private MikroTikConnectionManager $connectionManager;
    private MikroTikApiProtocol $apiProtocol;

    private array $excludedTypes = ['l2tp', 'pptp', 'pppoe', 'ovpn', 'sstp', 'gre', 'ipip', 'eoip'];

    public function __construct(
        ?MikroTikConnectionManager $connectionManager = null,
        ?MikroTikApiProtocol $apiProtocol = null
    ) {
        $this->connectionManager = $connectionManager ?? new MikroTikConnectionManager();
        $this->apiProtocol = $apiProtocol ?? $this->connectionManager->getApiProtocol();
    }

    /**
     * Get interfaces from a client router
     */
    public function getRouterInterfaces(
        string $clientIp,
        string $clientUser,
        string $clientPass,
        int $clientPort = 8728
    ): array {
        try {
            Log::info('[InterfaceReader] Obteniendo interfaces', [
                'client_ip' => $clientIp,
            ]);

            // Try direct API first
            if ($this->connectionManager->tryDirectClientConnection($clientIp, $clientPort)) {
                $socket = $this->apiProtocol->connect($clientIp, $clientPort, 5);
                if ($socket) {
                    Log::info('[InterfaceReader] Conexión API directa exitosa');
                    return $this->getInterfacesDirectApi($socket, $clientUser, $clientPass);
                }
            }

            Log::info('[InterfaceReader] API directa no disponible, usando CORE');
            return $this->getInterfacesViaCoreApi($clientIp, $clientUser, $clientPass);

        } catch (\Throwable $e) {
            Log::error('[InterfaceReader] Error obteniendo interfaces', [
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'interfaces' => [],
            ];
        }
    }

    /**
     * Get interfaces using direct API connection
     */
    private function getInterfacesDirectApi($socket, string $clientUser, string $clientPass): array
    {
        try {
            if (!$this->apiProtocol->login($socket, $clientUser, $clientPass)) {
                $this->apiProtocol->close($socket);
                return [
                    'success' => false,
                    'message' => 'Error de autenticación en router cliente',
                    'interfaces' => [],
                ];
            }

            $this->apiProtocol->sendCommand($socket, '/interface/print');
            $records = $this->apiProtocol->readAllRecords($socket);

            $this->apiProtocol->close($socket);

            $interfaces = $this->parseInterfaceRecords($records);

            return [
                'success' => true,
                'message' => 'Interfaces obtenidas via API directa',
                'method' => 'DIRECT_API',
                'interfaces' => $interfaces,
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
     * Get interfaces using API to CORE, then CORE uses ssh-exec to query the client router.
     * NOTE: We skip the ping step — ICMP is often blocked on MikroTik client routers.
     */
    private function getInterfacesViaCoreApi(
        string $clientIp,
        string $clientUser,
        string $clientPass
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
                    'message' => 'No se pudo conectar al CORE MikroTik (' . $this->connectionManager->getApiHost() . ':' . $this->connectionManager->getApiPort() . '). Verifica la IP y el puerto API.',
                    'interfaces' => [],
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
                    'message' => 'Autenticación fallida al CORE MikroTik. Verifica las credenciales en .env.',
                    'interfaces' => [],
                ];
            }

            // Build the ssh-exec command to run /interface/print on the client router
            $safePass = str_replace('"', '\\"', $clientPass);
            $safeUser = escapeshellarg($clientUser);

            // Use /interface/print detail to get name and type
            $cmd = "/system ssh-exec address={$clientIp} user={$clientUser} password=\"{$safePass}\" command=\"/interface print terse\"";

            $this->apiProtocol->sendCommand($socket, '/tool/fetch', [
                // We'll use script approach instead
            ]);
            // Discard the fetch attempt, use script method
            $this->apiProtocol->readUntilDone($socket);

            // Create a temporary script that runs ssh-exec and stores output
            $scriptName = 'ispwatch_ifaces_' . substr(md5($clientIp), 0, 6);

            // Remove old script if exists
            $this->apiProtocol->sendCommand($socket, '/system/script/remove', [
                '=numbers=' . $scriptName,
            ]);
            $this->apiProtocol->readUntilDone($socket);

            // Create new script
            $scriptSource = "/system ssh-exec address={$clientIp} user={$clientUser} password=\"{$safePass}\" command=\"/interface print terse\"";
            $this->apiProtocol->sendCommand($socket, '/system/script/add', [
                '=name=' . $scriptName,
                '=source=' . $scriptSource,
            ]);
            $addResult = $this->apiProtocol->readUntilDone($socket);

            // Run the script
            $this->apiProtocol->sendCommand($socket, '/system/script/run', [
                '=number=' . $scriptName,
            ]);
            $runResult = $this->apiProtocol->readAllRecords($socket);

            // Cleanup
            $this->apiProtocol->sendCommand($socket, '/system/script/remove', [
                '=numbers=' . $scriptName,
            ]);
            $this->apiProtocol->readUntilDone($socket);

            $this->apiProtocol->close($socket);

            // Parse output
            $output = '';
            foreach ($runResult as $record) {
                if (isset($record['ret'])) {
                    $output = $record['ret'];
                    break;
                }
                if (isset($record['output'])) {
                    $output = $record['output'];
                    break;
                }
            }

            Log::info('[InterfaceReader] ssh-exec output', [
                'client_ip' => $clientIp,
                'output_length' => strlen($output),
                'raw_output' => substr($output, 0, 500),
                'all_records' => $runResult,
            ]);

            if (empty(trim($output))) {
                return [
                    'success' => false,
                    'message' => "El CORE se conectó pero el router cliente ({$clientIp}) no respondió vía SSH. Asegúrate que: 1) La VPN esté activa, 2) SSH esté habilitado en el router cliente, 3) Las credenciales user_rb sean correctas.",
                    'interfaces' => [],
                ];
            }

            $interfaces = $this->parseTerseOutput($output);

            if (empty($interfaces)) {
                return [
                    'success' => false,
                    'message' => 'El router respondió pero no se pudieron leer las interfaces. Respuesta del router: ' . substr($output, 0, 200),
                    'interfaces' => [],
                ];
            }

            return [
                'success' => true,
                'message' => 'Interfaces obtenidas via CORE → SSH al cliente',
                'method'  => 'CORE_SSH_EXEC',
                'interfaces' => $interfaces,
            ];

        } catch (\Throwable $e) {
            Log::error('[InterfaceReader] Error via CORE API', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Error interno: ' . $e->getMessage(),
                'interfaces' => [],
            ];
        }
    }


    /**
     * Parse interface records from API response
     */
    private function parseInterfaceRecords(array $records): array
    {
        $interfaces = [];

        foreach ($records as $record) {
            $name = $record['name'] ?? '';
            $type = $record['type'] ?? 'unknown';

            if ($this->shouldExcludeInterface($name, $type)) {
                continue;
            }

            if ($name) {
                $interfaces[] = [
                    'name' => $name,
                    'type' => $type,
                    'running' => ($record['running'] ?? 'false') === 'true',
                    'disabled' => ($record['disabled'] ?? 'false') === 'true',
                    'comment' => $record['comment'] ?? '',
                ];
            }
        }

        return $interfaces;
    }

    /**
     * Parse terse output format
     */
    private function parseTerseOutput(string $output): array
    {
        $interfaces = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line))
                continue;

            if (preg_match('/name="?([^"\s]+)"?/', $line, $nameMatch)) {
                $name = $nameMatch[1];
                $type = 'unknown';
                if (preg_match('/type="?([^"\s]+)"?/', $line, $typeMatch)) {
                    $type = $typeMatch[1];
                }

                if (!$this->shouldExcludeInterface($name, $type)) {
                    $interfaces[] = [
                        'name' => $name,
                        'type' => $type,
                        'running' => str_contains($line, ' R ') || preg_match('/^\d+\s+R\s/', $line),
                        'disabled' => str_contains($line, ' X ') || preg_match('/^\d+\s+X/', $line),
                        'comment' => '',
                    ];
                }
            }
        }

        return $interfaces;
    }

    /**
     * Check if interface should be excluded
     */
    private function shouldExcludeInterface(string $name, string $type): bool
    {
        foreach ($this->excludedTypes as $excluded) {
            if (stripos($type, $excluded) !== false || stripos($name, $excluded) !== false) {
                return true;
            }
        }

        if (stripos($name, 'ISPWatch-VPN') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Get suggested interfaces when parsing fails
     */
    private function getSuggestedInterfaces(): array
    {
        return [
            ['name' => 'ether1', 'type' => 'ether', 'running' => true, 'disabled' => false, 'comment' => 'WAN típico'],
            ['name' => 'ether2', 'type' => 'ether', 'running' => true, 'disabled' => false, 'comment' => ''],
            ['name' => 'bridge', 'type' => 'bridge', 'running' => true, 'disabled' => false, 'comment' => 'LAN Bridge'],
        ];
    }
}
