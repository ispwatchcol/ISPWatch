<?php

namespace App\Services;

use App\Models\Router;
use Illuminate\Support\Facades\Log;

/**
 * Servicio para comunicación directa con routers MikroTik
 * Separado de VpnService para llamadas específicas al router cliente
 */
class RouterApiService
{
    private int $apiPort = 8728;
    private int $timeout = 15;

    /**
     * Obtener todas las interfaces del router
     */
    public function getInterfaces(Router $router): array
    {
        if (!$router->ip || !$router->user_rb || !$router->password_rb) {
            return $this->error('Router sin credenciales configuradas');
        }

        Log::info('[RouterAPI] Conectando para obtener interfaces', [
            'router_id' => $router->id,
            'ip' => $router->ip,
            'user' => $router->user_rb,
        ]);

        // Conexión fresca al router
        $socket = @fsockopen($router->ip, $this->apiPort, $errno, $errstr, $this->timeout);

        if (!$socket) {
            Log::error('[RouterAPI] No se pudo conectar', ['error' => $errstr]);
            return $this->error("No se pudo conectar al router: $errstr");
        }

        stream_set_timeout($socket, $this->timeout);

        try {
            // LOGIN
            if (!$this->login($socket, $router->user_rb, $router->password_rb)) {
                fclose($socket);
                return $this->error('Error de autenticación en el router');
            }

            Log::info('[RouterAPI] Login exitoso');

            // Obtener interfaces - enviamos comando
            $this->sendCommand($socket, '/interface/print');

            // Leer TODA la respuesta hasta !done
            $records = $this->readAllRecords($socket);

            fclose($socket);

            Log::info('[RouterAPI] Interfaces obtenidas', ['count' => count($records)]);

            // Formatear para frontend
            $interfaces = [];
            foreach ($records as $record) {
                $type = strtolower($record['type'] ?? '');
                $name = $record['name'] ?? '';

                // Excluir interfaces de túnel VPN
                if (
                    str_contains($type, 'l2tp') || str_contains($type, 'pptp') ||
                    str_contains($type, 'pppoe') || str_contains($type, 'ovpn') ||
                    str_contains($type, 'sstp')
                ) {
                    continue;
                }

                $interfaces[] = [
                    'name' => $name,
                    'type' => $record['type'] ?? 'unknown',
                    'running' => ($record['running'] ?? 'false') === 'true',
                    'disabled' => ($record['disabled'] ?? 'false') === 'true',
                    'comment' => $record['comment'] ?? '',
                ];
            }

            return [
                'success' => true,
                'interfaces' => $interfaces,
                'current_wan' => $router->wan_interface,
            ];

        } catch (\Throwable $e) {
            Log::error('[RouterAPI] Excepción', ['error' => $e->getMessage()]);
            @fclose($socket);
            return $this->error('Error al consultar interfaces: ' . $e->getMessage());
        }
    }

    /**
     * Aplicar reglas de firewall para bloquear usuarios morosos
     */
    public function applyBlockRules(Router $router): array
    {
        if (!$router->ip || !$router->user_rb || !$router->password_rb) {
            return $this->error('Router sin credenciales configuradas');
        }

        if (!$router->wan_interface) {
            return $this->error('Router sin interfaz WAN configurada');
        }

        Log::info('[RouterAPI] Aplicando reglas de bloqueo', [
            'router_id' => $router->id,
            'wan_interface' => $router->wan_interface,
        ]);

        // Obtener IP del portal (IP del servidor Laravel)
        // Por ahora usamos la IP del router principal, pero debería configurarse
        $portalIp = request()->ip(); // IP del servidor Laravel donde está corriendo

        // Conexión al router
        $socket = @fsockopen($router->ip, $this->apiPort, $errno, $errstr, $this->timeout);

        if (!$socket) {
            Log::error('[RouterAPI] No se pudo conectar', ['error' => $errstr]);
            return $this->error("No se pudo conectar al router: $errstr");
        }

        stream_set_timeout($socket, $this->timeout);

        try {
            // LOGIN
            if (!$this->login($socket, $router->user_rb, $router->password_rb)) {
                fclose($socket);
                return $this->error('Error de autenticación en el router');
            }

            Log::info('[RouterAPI] Login exitoso, aplicando reglas');

            // 1. Crear address-list inicial (con IP placeholder)
            $this->sendCommand($socket, '/ip/firewall/address-list/add', [
                '=list=ISPWATCH_SUSPENDIDOS',
                '=address=0.0.0.0',
                '=comment=Control ISPWatch',
            ]);
            $this->readUntilDone($socket);
            Log::info('[RouterAPI] Address-list creada');

            // 2. Regla NAT: Redirigir HTTP al portal
            $this->sendCommand($socket, '/ip/firewall/nat/add', [
                '=chain=dstnat',
                '=src-address-list=ISPWATCH_SUSPENDIDOS',
                '=protocol=tcp',
                '=dst-port=80',
                '=action=dst-nat',
                '=to-addresses=' . $portalIp,
                '=to-ports=80',
                '=comment=ISPWatch Portal HTTP',
            ]);
            $this->readUntilDone($socket);
            Log::info('[RouterAPI] Regla NAT HTTP aplicada');

            // 3. Regla NAT: Redirigir HTTPS al portal
            $this->sendCommand($socket, '/ip/firewall/nat/add', [
                '=chain=dstnat',
                '=src-address-list=ISPWATCH_SUSPENDIDOS',
                '=protocol=tcp',
                '=dst-port=443',
                '=action=dst-nat',
                '=to-addresses=' . $portalIp,
                '=to-ports=443',
                '=comment=ISPWatch Portal HTTPS',
            ]);
            $this->readUntilDone($socket);
            Log::info('[RouterAPI] Regla NAT HTTPS aplicada');

            // 4. Regla FILTER: Bloquear todo el resto del tráfico
            $this->sendCommand($socket, '/ip/firewall/filter/add', [
                '=chain=forward',
                '=src-address-list=ISPWATCH_SUSPENDIDOS',
                '=out-interface=' . $router->wan_interface,
                '=action=drop',
                '=comment=ISPWatch - Bloqueo general',
            ]);
            $this->readUntilDone($socket);
            Log::info('[RouterAPI] Regla FILTER aplicada');

            fclose($socket);

            return [
                'success' => true,
                'message' => 'Reglas de bloqueo y redirección aplicadas correctamente',
                'rules_applied' => [
                    'address_list' => 'ISPWATCH_SUSPENDIDOS',
                    'portal_ip' => $portalIp,
                    'wan_interface' => $router->wan_interface,
                    'nat_rules' => ['HTTP:80', 'HTTPS:443'],
                    'filter_rule' => 'DROP forward to WAN',
                ],
            ];

        } catch (\Throwable $e) {
            Log::error('[RouterAPI] Error aplicando reglas', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            @fclose($socket);
            return $this->error('Error al aplicar reglas: ' . $e->getMessage());
        }
    }

    /**
     * Login al router (soporta MD5 challenge)
     */
    private function login($socket, string $user, string $pass): bool
    {
        $this->sendCommand($socket, '/login', [
            '=name=' . $user,
            '=password=' . $pass,
        ]);

        // Leer respuesta completa del login
        $response = [];
        $challenge = null;

        while (true) {
            $word = $this->readWord($socket);
            if ($word === '')
                break;

            $response[] = $word;

            if (str_starts_with($word, '=ret=')) {
                $challenge = substr($word, 5);
            }

            if ($word === '!trap') {
                Log::error('[RouterAPI] Login trap', ['response' => $response]);
                return false;
            }
        }

        // Si hay challenge, hacer login MD5
        if ($challenge) {
            Log::debug('[RouterAPI] Challenge detectado, haciendo MD5 login');

            $challengeBin = hex2bin($challenge);
            $hash = md5(chr(0) . $pass . $challengeBin);

            $this->sendCommand($socket, '/login', [
                '=name=' . $user,
                '=response=00' . $hash,
            ]);

            // Leer respuesta MD5
            while (true) {
                $word = $this->readWord($socket);
                if ($word === '')
                    break;
                if ($word === '!trap') {
                    Log::error('[RouterAPI] MD5 login failed');
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Leer TODOS los registros hasta !done
     * IMPORTANTE: Las palabras vacías son separadores de sentencia, NO fin de respuesta
     */
    private function readAllRecords($socket): array
    {
        $records = [];
        $currentRecord = [];
        $wordCount = 0;
        $maxWords = 2000; // Safety limit
        $emptyCount = 0;
        $maxEmpty = 20; // Máximo de palabras vacías consecutivas antes de asumir timeout

        while ($wordCount < $maxWords) {
            $word = $this->readWord($socket);
            $wordCount++;

            // Nuevo registro
            if ($word === '!re') {
                if (!empty($currentRecord)) {
                    $records[] = $currentRecord;
                }
                $currentRecord = [];
                $emptyCount = 0;
                continue;
            }

            // Fin de respuesta - solo paramos aquí
            if ($word === '!done') {
                if (!empty($currentRecord)) {
                    $records[] = $currentRecord;
                }
                break;
            }

            // Palabra vacía = separador de sentencia, CONTINUAR leyendo
            if ($word === '') {
                $emptyCount++;

                // Verificar timeout
                $meta = stream_get_meta_data($socket);
                if ($meta['timed_out'] || $emptyCount > $maxEmpty) {
                    Log::warning('[RouterAPI] Timeout o demasiadas palabras vacías', [
                        'emptyCount' => $emptyCount,
                        'timedOut' => $meta['timed_out'],
                    ]);
                    if (!empty($currentRecord)) {
                        $records[] = $currentRecord;
                    }
                    break;
                }

                // Continuar leyendo - la palabra vacía es solo separador
                continue;
            }

            // Reset contador de vacías cuando recibimos datos
            $emptyCount = 0;

            // Atributo
            if (str_starts_with($word, '=')) {
                $parts = explode('=', substr($word, 1), 2);
                if (count($parts) === 2) {
                    $currentRecord[$parts[0]] = $parts[1];
                }
            }
        }

        Log::info('[RouterAPI] Total records parsed', ['count' => count($records), 'words' => $wordCount]);

        return $records;
    }

    /**
     * Enviar comando al router
     */
    private function sendCommand($socket, string $command, array $params = []): void
    {
        $this->writeWord($socket, $command);
        foreach ($params as $param) {
            $this->writeWord($socket, $param);
        }
        // Fin de sentencia
        fwrite($socket, chr(0));
    }

    /**
     * Leer respuesta hasta !done
     */
    private function readUntilDone($socket): void
    {
        $maxWords = 100;
        $count = 0;

        while ($count < $maxWords) {
            $word = $this->readWord($socket);
            $count++;

            if ($word === '!done') {
                break;
            }

            if ($word === '!trap') {
                Log::warning('[RouterAPI] Trap received');
                break;
            }

            // Empty words are separators, continue
            if ($word === '') {
                continue;
            }
        }
    }

    /**
     * Escribir palabra con longitud codificada
     */
    private function writeWord($socket, string $word): void
    {
        $len = strlen($word);

        if ($len < 0x80) {
            fwrite($socket, chr($len));
        } elseif ($len < 0x4000) {
            $len |= 0x8000;
            fwrite($socket, chr(($len >> 8) & 0xFF));
            fwrite($socket, chr($len & 0xFF));
        } elseif ($len < 0x200000) {
            $len |= 0xC00000;
            fwrite($socket, chr(($len >> 16) & 0xFF));
            fwrite($socket, chr(($len >> 8) & 0xFF));
            fwrite($socket, chr($len & 0xFF));
        } else {
            $len |= 0xE0000000;
            fwrite($socket, chr(($len >> 24) & 0xFF));
            fwrite($socket, chr(($len >> 16) & 0xFF));
            fwrite($socket, chr(($len >> 8) & 0xFF));
            fwrite($socket, chr($len & 0xFF));
        }

        fwrite($socket, $word);
    }

    /**
     * Leer una palabra del socket
     */
    private function readWord($socket): string
    {
        $byte = @fread($socket, 1);
        if ($byte === '' || $byte === false) {
            return '';
        }

        $len = ord($byte);
        if ($len === 0) {
            return '';
        }

        // Decodificar longitud
        if (($len & 0x80) == 0) {
            // 1 byte: 0-127
        } elseif (($len & 0xC0) == 0x80) {
            // 2 bytes: 128 - 16383
            $b2 = ord(@fread($socket, 1));
            $len = (($len & 0x3F) << 8) + $b2;
        } elseif (($len & 0xE0) == 0xC0) {
            // 3 bytes
            $b2 = ord(@fread($socket, 1));
            $b3 = ord(@fread($socket, 1));
            $len = (($len & 0x1F) << 16) + ($b2 << 8) + $b3;
        } elseif (($len & 0xF0) == 0xE0) {
            // 4 bytes
            $b2 = ord(@fread($socket, 1));
            $b3 = ord(@fread($socket, 1));
            $b4 = ord(@fread($socket, 1));
            $len = (($len & 0x0F) << 24) + ($b2 << 16) + ($b3 << 8) + $b4;
        } else {
            // 5 bytes (muy raro)
            @fread($socket, 4);
            return '';
        }

        if ($len <= 0) {
            return '';
        }

        // Leer datos
        $data = '';
        $remaining = $len;
        while ($remaining > 0) {
            $chunk = @fread($socket, $remaining);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $data .= $chunk;
            $remaining = $len - strlen($data);
        }

        return $data;
    }

    /**
     * Respuesta de error
     */
    private function error(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
            'interfaces' => [],
        ];
    }
}
