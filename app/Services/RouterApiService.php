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
    private int $timeout = 30; // Increased timeout for slower connections

    /**
     * Obtener todas las interfaces del router
     */
    public function getInterfaces(Router $router): array
    {
        if (!$router->ip || !$router->user_rb || !$router->password_rb) {
            return $this->error('Router sin credenciales configuradas');
        }

        // Use the API port configured in the router, default to 8728
        $apiPort = $router->puerto_api ?? 8728;

        Log::info('[RouterAPI] Conectando para obtener interfaces', [
            'router_id' => $router->id,
            'ip' => $router->ip,
            'port' => $apiPort,
            'user' => $router->user_rb,
            'timeout' => $this->timeout,
        ]);

        // Conexión fresca al router
        $socket = @fsockopen($router->ip, $apiPort, $errno, $errstr, $this->timeout);

        if (!$socket) {
            $errorMsg = "No se pudo conectar al router en {$router->ip}:{$apiPort}. " .
                "Error: $errstr (Código: $errno). " .
                "Verifica que el servicio API esté habilitado en el router y que el puerto sea accesible.";

            Log::error('[RouterAPI] No se pudo conectar', [
                'error' => $errstr,
                'errno' => $errno,
                'ip' => $router->ip,
                'port' => $apiPort,
            ]);

            return $this->error($errorMsg);
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
        // Puede configurarse en .env como PORTAL_IP=192.168.1.100
        $portalIp = env('PORTAL_IP');

        if (!$portalIp) {
            // Fallback: intentar detectar IP del servidor
            $portalIp = request()->server('SERVER_ADDR');

            if (!$portalIp || $portalIp === '127.0.0.1' || $portalIp === '::1') {
                // Si no se puede detectar, registrar advertencia
                Log::warning('[RouterAPI] No se pudo determinar IP del portal. Configure PORTAL_IP en .env');
                return $this->error('Configure PORTAL_IP en .env para habilitar la redirección al portal');
            }
        }

        Log::info('[RouterAPI] Portal IP configurada', ['portal_ip' => $portalIp]);

        // Use the API port configured in the router
        $apiPort = $router->puerto_api ?? 8728;

        // Conexión al router
        $socket = @fsockopen($router->ip, $apiPort, $errno, $errstr, $this->timeout);

        if (!$socket) {
            Log::error('[RouterAPI] No se pudo conectar', [
                'error' => $errstr,
                'errno' => $errno,
                'ip' => $router->ip,
                'port' => $apiPort,
            ]);
            return $this->error("No se pudo conectar al router en {$router->ip}:{$apiPort}: $errstr");
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
     * Sincronizar cliente con el router (Simple Queue)
     */
    public function syncCustomer(Router $router, $customer, $servicePlan): array
    {
        if (!$router->ip || !$router->user_rb || !$router->password_rb) {
            return $this->error('Router sin credenciales configuradas');
        }

        if (!$customer->ip_user) {
            return $this->error('Cliente sin IP asignada');
        }

        if (!$servicePlan) {
            return $this->error('Cliente sin plan de servicio asignado');
        }

        Log::info('[RouterAPI] Sincronizando cliente', [
            'router_id' => $router->id,
            'customer_id' => $customer->user_id,
            'ip' => $customer->ip_user,
            'plan_down' => $servicePlan->speed_down,
            'plan_up' => $servicePlan->speed_up,
        ]);

        // Use the API port configured in the router
        $apiPort = $router->puerto_api ?? 8728;

        $socket = @fsockopen($router->ip, $apiPort, $errno, $errstr, $this->timeout);

        if (!$socket) {
            Log::error('[RouterAPI] No se pudo conectar', [
                'error' => $errstr,
                'errno' => $errno,
                'ip' => $router->ip,
                'port' => $apiPort,
            ]);
            return $this->error("No se pudo conectar al router en {$router->ip}:{$apiPort}: $errstr");
        }

        stream_set_timeout($socket, $this->timeout);

        try {
            if (!$this->login($socket, $router->user_rb, $router->password_rb)) {
                fclose($socket);
                return $this->error('Error de autenticación en el router');
            }

            // Construir max-limit (uplink/downlink)
            // Normalizar velocidades: si no tienen sufijo, asumir que son megas y agregar 'M'
            $speedUp = $servicePlan->speed_up;
            $speedDown = $servicePlan->speed_down;

            // Si el valor es solo numérico, agregar 'M' para megabits
            if (is_numeric($speedUp)) {
                $speedUp = $speedUp . 'M';
            }
            if (is_numeric($speedDown)) {
                $speedDown = $speedDown . 'M';
            }

            $maxLimit = "{$speedUp}/{$speedDown}";
            $target = $customer->ip_user;
            $name = "Client - {$customer->name} {$customer->last_name}";

            Log::info('[RouterAPI] Configurando queue', [
                'name' => $name,
                'target' => $target,
                'max_limit' => $maxLimit,
            ]);

            // 1. Verificar si ya existe la queue usando la IP (target)
            // Nota: Mikrotik find devuelve ID interno, no registro completo
            $this->sendCommand($socket, '/queue/simple/print', [
                '?target=' . $target . '/32',  // Intentar match exacto con máscara /32 implícita o explicita
                '=.proplist=.id'
            ]);

            // Si no encuentra exacto, buscamos por nombre
            $records = $this->readAllRecords($socket);
            $existingId = null;

            if (!empty($records)) {
                $existingId = $records[0]['.id'] ?? null;
            } else {
                // Intentar buscar por nombre si no encontró por target
                $this->sendCommand($socket, '/queue/simple/print', [
                    '?name=' . $name,
                    '=.proplist=.id'
                ]);
                $records = $this->readAllRecords($socket);
                if (!empty($records)) {
                    $existingId = $records[0]['.id'] ?? null;
                }
            }

            if ($existingId) {
                // UPDATE
                Log::info('[RouterAPI] Actualizando Simple Queue existente', ['id' => $existingId]);
                $this->sendCommand($socket, '/queue/simple/set', [
                    '=.id=' . $existingId,
                    '=name=' . $name,
                    '=target=' . $target,
                    '=max-limit=' . $maxLimit,
                    '=comment=ISPWatch Auto-Provisioned',
                ]);
            } else {
                // CREATE
                Log::info('[RouterAPI] Creando nueva Simple Queue');
                $this->sendCommand($socket, '/queue/simple/add', [
                    '=name=' . $name,
                    '=target=' . $target,
                    '=max-limit=' . $maxLimit,
                    '=comment=ISPWatch Auto-Provisioned',
                ]);
            }

            $this->readUntilDone($socket);
            fclose($socket);

            return [
                'success' => true,
                'message' => 'Cliente sincronizado correctamente en el router',
                'details' => "Queue '$name' configurada con límite $maxLimit para $target"
            ];

        } catch (\Throwable $e) {
            Log::error('[RouterAPI] Error sincronizando cliente', [
                'error' => $e->getMessage()
            ]);
            @fclose($socket);
            return $this->error('Error al sincronizar cliente: ' . $e->getMessage());
        }
    }

    /**
     * Add customer IP to suspended address-list
     */
    public function addSuspendedIp(Router $router, string $ip, string $customerName): array
    {
        if (!$router->ip || !$router->user_rb || !$router->password_rb) {
            return $this->error('Router sin credenciales configuradas');
        }

        if (!$ip) {
            return $this->error('IP del cliente no especificada');
        }

        Log::info('[RouterAPI] Suspendiendo cliente', [
            'router_id' => $router->id,
            'customer_ip' => $ip,
            'customer_name' => $customerName,
        ]);

        // Use the API port configured in the router
        $apiPort = $router->puerto_api ?? 8728;

        $socket = @fsockopen($router->ip, $apiPort, $errno, $errstr, $this->timeout);

        if (!$socket) {
            Log::error('[RouterAPI] No se pudo conectar', [
                'error' => $errstr,
                'errno' => $errno,
                'ip' => $router->ip,
                'port' => $apiPort,
            ]);
            return $this->error("No se pudo conectar al router en {$router->ip}:{$apiPort}: $errstr");
        }

        stream_set_timeout($socket, $this->timeout);

        try {
            if (!$this->login($socket, $router->user_rb, $router->password_rb)) {
                fclose($socket);
                return $this->error('Error de autenticación en el router');
            }

            // Agregar IP a la address-list
            $this->sendCommand($socket, '/ip/firewall/address-list/add', [
                '=list=ISPWATCH_SUSPENDIDOS',
                '=address=' . $ip,
                '=comment=Cliente: ' . $customerName,
            ]);
            $this->readUntilDone($socket);

            fclose($socket);

            return [
                'success' => true,
                'message' => "Cliente suspendido - IP agregada a lista de bloqueados",
                'details' => "IP {$ip} agregada a ISPWATCH_SUSPENDIDOS"
            ];

        } catch (\Throwable $e) {
            Log::error('[RouterAPI] Error suspendiendo cliente', ['error' => $e->getMessage()]);
            @fclose($socket);
            return $this->error('Error al suspender cliente: ' . $e->getMessage());
        }
    }

    /**
     * Remove customer IP from suspended address-list
     */
    public function removeSuspendedIp(Router $router, string $ip): array
    {
        if (!$router->ip || !$router->user_rb || !$router->password_rb) {
            return $this->error('Router sin credenciales configuradas');
        }

        if (!$ip) {
            return $this->error('IP del cliente no especificada');
        }

        Log::info('[RouterAPI] Activando cliente', [
            'router_id' => $router->id,
            'customer_ip' => $ip,
        ]);

        // Use the API port configured in the router
        $apiPort = $router->puerto_api ?? 8728;

        $socket = @fsockopen($router->ip, $apiPort, $errno, $errstr, $this->timeout);

        if (!$socket) {
            Log::error('[RouterAPI] No se pudo conectar', [
                'error' => $errstr,
                'errno' => $errno,
                'ip' => $router->ip,
                'port' => $apiPort,
            ]);
            return $this->error("No se pudo conectar al router en {$router->ip}:{$apiPort}: $errstr");
        }

        stream_set_timeout($socket, $this->timeout);

        try {
            if (!$this->login($socket, $router->user_rb, $router->password_rb)) {
                fclose($socket);
                return $this->error('Error de autenticación en el router');
            }

            // Buscar la entrada en address-list
            $this->sendCommand($socket, '/ip/firewall/address-list/print', [
                '?list=ISPWATCH_SUSPENDIDOS',
                '?address=' . $ip,
                '=.proplist=.id'
            ]);

            $records = $this->readAllRecords($socket);

            if (empty($records)) {
                fclose($socket);
                return [
                    'success' => true,
                    'message' => 'Cliente ya estaba activo',
                    'details' => "IP {$ip} no encontrada en lista de suspendidos"
                ];
            }

            // Eliminar cada entrada encontrada (puede haber duplicados)
            foreach ($records as $record) {
                $id = $record['.id'] ?? null;
                if ($id) {
                    $this->sendCommand($socket, '/ip/firewall/address-list/remove', [
                        '=.id=' . $id
                    ]);
                    $this->readUntilDone($socket);
                }
            }

            fclose($socket);

            return [
                'success' => true,
                'message' => "Cliente activado - IP removida de lista de bloqueados",
                'details' => "IP {$ip} removida de ISPWATCH_SUSPENDIDOS"
            ];

        } catch (\Throwable $e) {
            Log::error('[RouterAPI] Error activando cliente', ['error' => $e->getMessage()]);
            @fclose($socket);
            return $this->error('Error al activar cliente: ' . $e->getMessage());
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
