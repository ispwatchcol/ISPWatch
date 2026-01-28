<?php

namespace App\Services;

use App\Models\Router;
use Illuminate\Support\Facades\Log;

class VpnService
{
    // ==============================
    // CONFIGURACIÓN DEL CORE (desde .env)
    // ==============================

    // IP pública → SOLO para la VPN (clientes se conectan aquí)
    private string $vpnPublicIp;

    // IP para API → Donde Laravel se conecta (IP pública si está en DigitalOcean)
    private string $apiHost;
    private int $apiPort;

    // ==============================
    // CREDENCIALES VPN
    // ==============================
    private string $ipsecSecret;

    // ==============================
    // CREDENCIALES API (LECTURA)
    // ==============================
    private string $apiUser;
    private string $apiPass;

    public function __construct()
    {
        // Cargar configuración desde .env
        // IP Publica del MikroTik CORE (donde los clientes VPN se conectan)
        $this->vpnPublicIp = env('MIKROTIK_CORE_VPN_IP', '138.197.30.155');
        // IP para conexión API desde Laravel al CORE
        $this->apiHost = env('MIKROTIK_CORE_API_HOST', '138.197.30.155');
        $this->apiPort = (int) env('MIKROTIK_CORE_API_PORT', 8728);
        $this->apiUser = env('MIKROTIK_CORE_API_USER', 'admin');
        $this->apiPass = env('MIKROTIK_CORE_API_PASS', '');
        // IPsec Secret fijo para todos los clientes L2TP
        $this->ipsecSecret = env('MIKROTIK_IPSEC_SECRET', 'Q9fZ7MrL2xSA8DkEpHwCy');
    }

    // ==============================
    // USERNAME VPN POR ROUTER
    // ==============================
    private function getVpnUsername(Router $router): string
    {
        $identifier = $router->external_id ?? $router->id;
        return "core-isp-{$identifier}";
    }

    // ==============================
    // SCRIPT CLIENTE L2TP
    // ==============================
    public function getServerPublicIp(): string
    {
        return $this->vpnPublicIp;
    }

    public function generateScript(Router $router): string
    {
        $routerName = $this->sanitizeName($router->name);

        // Generar o reutilizar username VPN único
        $vpnUsername = $router->vpn_username;
        if (empty($vpnUsername)) {
            $vpnUsername = $this->getVpnUsername($router);
        }

        // Generar o reutilizar contraseña VPN segura y única
        $vpnPassword = $router->vpn_password;
        if (empty($vpnPassword)) {
            // Generar contraseña segura de 20 caracteres alfanuméricos
            $vpnPassword = \Illuminate\Support\Str::random(20);
        }

        // Guardar credentials VPN en la base de datos
        $router->update([
            'vpn_username' => $vpnUsername,
            'vpn_password' => $vpnPassword,
        ]);

        // Generar credenciales de gestión local (INTERNO - no mostrar al usuario)
        $localUser = 'ispwatch';
        if (empty($router->password_rb) || $router->password_rb === 'Sena2017') {
            $localPass = \Illuminate\Support\Str::random(16);
            $router->update([
                'user_rb' => $localUser,
                'password_rb' => $localPass
            ]);
        } else {
            $localPass = $router->password_rb;
            if ($router->user_rb !== $localUser) {
                $router->update(['user_rb' => $localUser]);
            }
        }

        // IPsec Secret fijo
        $ipsecSecret = 'Q9fZ7MrL2xSA8DkEpHwCy';

        // Script solo con credenciales VPN visibles
        // Credenciales de gestión local se manejan internamente y NO se muestran en el script
        return <<<SCRIPT
# ============================================
# Script VPN para Router Cliente: {$routerName}
# Generado por ISPWatch
# Usuario VPN: {$vpnUsername}
# Contraseña VPN: {$vpnPassword}
# ============================================

# Crear interfaz Cliente L2TP
/interface l2tp-client
add name=ISPWatch-VPN-{$routerName} connect-to={$this->vpnPublicIp} user={$vpnUsername} password={$vpnPassword} use-ipsec=yes ipsec-secret={$ipsecSecret} profile=default-encryption add-default-route=no disabled=no
SCRIPT;
    }

    // ==============================
    // VERIFICAR ESTADO REAL DE VPN
    // ==============================
    public function verifyConnection(Router $router): array
    {
        $vpnUsername = $this->getVpnUsername($router);

        Log::debug('[VPN] Verificando conexión VPN', [
            'router_id' => $router->id,
            'external_id' => $router->external_id,
            'vpn_username' => $vpnUsername,
            'api_host' => $this->apiHost,
            'api_port' => $this->apiPort,
        ]);

        $socket = @fsockopen($this->apiHost, $this->apiPort, $errno, $errstr, 5);

        if (!$socket) {
            Log::error('[VPN] No se pudo abrir socket API', [
                'errno' => $errno,
                'errstr' => $errstr,
            ]);
            return $this->error("No se pudo conectar al CORE por API ($errstr)");
        }

        stream_set_timeout($socket, 10);

        try {
            // LOGIN - Manejar protocolo nuevo y legacy
            $loginSuccess = $this->doLogin($socket);

            if (!$loginSuccess) {
                fclose($socket);
                return $this->error('Error de autenticación API');
            }

            Log::debug('[VPN] Login exitoso, consultando PPP active');

            // 1) PPP ACTIVE
            $this->writeCommand($socket, '/ppp/active/print');
            $pppActive = $this->readRecords($socket);
            Log::debug('[VPN] PPP active recibidos', ['ppp_active' => $pppActive, 'count' => count($pppActive)]);

            // 2) L2TP SERVER (opcional, debug)
            $this->writeCommand($socket, '/interface/l2tp-server/print');
            $l2tpServer = $this->readRecords($socket);
            Log::debug('[VPN] L2TP server', ['l2tp_server' => $l2tpServer]);

            fclose($socket);
        } catch (\Throwable $e) {
            Log::error('[VPN] Excepción en verifyConnection', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            @fclose($socket);
            return $this->error('Error inesperado al consultar la API Mikrotik');
        }

        // BUSCAR EL USUARIO PPP
        foreach ($pppActive as $conn) {
            if (isset($conn['name']) && $conn['name'] === $vpnUsername) {
                Log::info('[VPN] Conexión PPP encontrada', ['conn' => $conn]);

                $assignedIp = $conn['address'] ?? null;

                // Obtener credenciales del PPP Secret para poder conectar al cliente
                $secretData = $this->getPppSecretData($vpnUsername);

                // Actualizar el router SOLO con la IP de la VPN
                // Mantenemos user_rb y password_rb intactos (seran 'ispwatch')
                if ($assignedIp) {
                    $router->update([
                        'ip' => $assignedIp,
                    ]);

                    Log::info('[VPN] Router actualizado con datos VPN', [
                        'router_id' => $router->id,
                        'vpn_remote_ip' => $assignedIp,
                        'vpn_user' => $secretData['name'] ?? null,
                    ]);
                }

                return [
                    'success' => true,
                    'connected' => true,
                    'message' => '✅ VPN ACTIVA (PPP activo en CORE)',
                    'assigned_ip' => $assignedIp,
                    'service' => $conn['service'] ?? null,
                    'uptime' => $conn['uptime'] ?? null,
                    'vpn_credentials_saved' => $secretData ? true : false,
                ];
            }
        }

        Log::info('[VPN] No hay PPP activo para usuario', [
            'vpn_username' => $vpnUsername,
        ]);

        return [
            'success' => true,
            'connected' => false,
            'message' => '❌ VPN CAÍDA (no existe sesión PPP activa)',
            'assigned_ip' => null,
        ];
    }

    // ==============================
    // OBTENER DATOS DEL PPP SECRET
    // ==============================
    private function getPppSecretData(string $username): ?array
    {
        $socket = @fsockopen($this->apiHost, $this->apiPort, $errno, $errstr, 5);

        if (!$socket) {
            Log::error('[VPN] No se pudo conectar para obtener secret');
            return null;
        }

        stream_set_timeout($socket, 10);

        try {
            $loginSuccess = $this->doLogin($socket);

            if (!$loginSuccess) {
                fclose($socket);
                return null;
            }

            // Consultar el secret específico
            $this->writeCommand($socket, '/ppp/secret/print', [
                '?name=' . $username,
            ]);

            $secrets = $this->readRecords($socket);
            fclose($socket);

            if (!empty($secrets)) {
                Log::debug('[VPN] Secret encontrado', ['secret' => $secrets[0]]);
                return $secrets[0];
            }

            return null;

        } catch (\Throwable $e) {
            Log::error('[VPN] Error obteniendo secret', ['exception' => $e->getMessage()]);
            @fclose($socket);
            return null;
        }
    }

    // ==============================
    // OBTENER INTERFACES DEL ROUTER CLIENTE
    // ==============================
    // ==============================
    // OBTENER INTERFACES DEL ROUTER CLIENTE
    // ==============================
    public function getInterfaces(Router $router): array
    {
        // Validar que el router tenga credenciales configuradas
        if (!$router->ip || !$router->user_rb || !$router->password_rb) {
            return $this->error('Router sin credenciales configuradas. Verifica la conexión VPN primero.');
        }

        Log::debug('[INTERFACES] Obteniendo interfaces del router', [
            'router_id' => $router->id,
            'router_ip' => $router->ip,
        ]);

        // Conectar directamente al router cliente
        $socket = @fsockopen($router->ip, 8728, $errno, $errstr, 10);

        if (!$socket) {
            Log::error('[INTERFACES] No se pudo conectar al router', [
                'errno' => $errno,
                'errstr' => $errstr,
                'ip' => $router->ip,
            ]);
            return $this->error("No se pudo conectar al router ($errstr). Verifica que la VPN esté activa.");
        }

        stream_set_timeout($socket, 15);

        try {
            // LOGIN con las credenciales del router
            $loginSuccess = $this->doLoginToClient($socket, $router->user_rb, $router->password_rb);

            if (!$loginSuccess) {
                fclose($socket);
                return $this->error('Error de autenticación en el router cliente');
            }

            Log::debug('[INTERFACES] Login exitoso al router cliente');

            // Consultar interfaces solicitando solo campos necesarios
            $this->writeCommand($socket, '/interface/print', [
                '=.proplist=name,type,running,disabled,comment'
            ]);
            $interfacesData = $this->readRecords($socket);

            Log::debug('[INTERFACES] Interfaces recibidas', [
                'count' => count($interfacesData),
                'data' => $interfacesData,
            ]);

            fclose($socket);

            // Formatear respuesta
            $interfaces = [];
            foreach ($interfacesData as $iface) {
                $type = $iface['type'] ?? 'unknown';
                $name = $iface['name'] ?? 'N/A';

                Log::debug('[INTERFACES] Processing interface', [
                    'name' => $name,
                    'type' => $type,
                    'running' => $iface['running'] ?? 'false',
                    'disabled' => $iface['disabled'] ?? 'false',
                ]);

                // Filtrar solo interfaces físicas (ethernet, sfp, vlan, bridge principales)
                // Excluir interfaces virtuales como l2tp, pptp, pppoe, etc.
                $normalizedType = strtolower($type);
                $excludedTypes = ['l2tp', 'pptp', 'pppoe', 'ovpn', 'sstp', 'gre', 'ipip', 'eoip'];

                $shouldExclude = false;
                foreach ($excludedTypes as $excluded) {
                    if (str_contains($normalizedType, $excluded)) {
                        $shouldExclude = true;
                        break;
                    }
                }

                if ($shouldExclude) {
                    Log::debug('[INTERFACES] Skipping virtual interface', ['name' => $name, 'type' => $type]);
                    continue;
                }

                $interfaces[] = [
                    'name' => $name,
                    'type' => $type,
                    'running' => ($iface['running'] ?? 'false') === 'true',
                    'disabled' => ($iface['disabled'] ?? 'false') === 'true',
                    'comment' => $iface['comment'] ?? '',
                ];
            }

            return [
                'success' => true,
                'interfaces' => $interfaces,
                'current_wan' => $router->wan_interface,
            ];

        } catch (\Throwable $e) {
            Log::error('[INTERFACES] Excepción al consultar interfaces', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            @fclose($socket);
            return $this->error('Error inesperado al consultar interfaces del router');
        }
    }

    // ==============================
    // LOGIN ESPECÍFICO PARA ROUTER CLIENTE
    // ==============================
    private function doLoginToClient($socket, string $user, string $pass): bool
    {
        $this->writeCommand($socket, '/login', [
            '=name=' . $user,
            '=password=' . $pass,
        ]);

        $response = [];
        $challenge = null;
        $gotTrap = false;

        while (true) {
            $word = $this->readWord($socket);

            if ($word === '') {
                break;
            }

            $response[] = $word;

            if (str_starts_with($word, '=ret=')) {
                $challenge = substr($word, 5);
            }

            if ($word === '!trap') {
                $gotTrap = true;
            }
        }

        if ($gotTrap) {
            Log::error('[INTERFACES] Login trap al router cliente', ['response' => $response]);
            return false;
        }

        // Si hay challenge, hacer login MD5
        if ($challenge) {
            Log::debug('[INTERFACES] Challenge detectado en router cliente');

            $challengeBin = hex2bin($challenge);
            $hash = md5(chr(0) . $pass . $challengeBin);

            $this->writeCommand($socket, '/login', [
                '=name=' . $user,
                '=response=00' . $hash,
            ]);

            while (true) {
                $word = $this->readWord($socket);

                if ($word === '') {
                    break;
                }

                if ($word === '!trap') {
                    Log::error('[INTERFACES] Login MD5 fallido en router cliente');
                    return false;
                }
            }
        }

        Log::info('[INTERFACES] Login exitoso al router cliente');
        return true;
    }

    // ==============================
    // LECTURA DE REGISTROS MULTI-LÍNEA
    // ==============================
    private function readRecords($socket): array
    {
        $records = [];
        $current = [];

        while (true) {
            $word = $this->readWord($socket);

            if ($word === '!re') {
                if (!empty($current)) {
                    $records[] = $current;
                }
                $current = [];
                continue;
            }

            if ($word === '!done' || $word === '') {
                if (!empty($current)) {
                    $records[] = $current;
                }
                break;
            }

            // Ignorar palabras que no son atributos (como !trap)
            if ($word === '!trap') {
                continue;
            }

            if (str_starts_with($word, '=')) {
                $parts = explode('=', substr($word, 1), 2);
                if (count($parts) === 2) {
                    $current[$parts[0]] = $parts[1];
                }
            }
        }

        return $records;
    }

    // ==============================
    // LOGIN API MIKROTIK (Compatible con RouterOS 6.43+ y 7.x/10.x)
    // ==============================
    private function doLogin($socket): bool
    {
        // Enviar login con credenciales
        $this->writeCommand($socket, '/login', [
            '=name=' . $this->apiUser,
            '=password=' . $this->apiPass,
        ]);

        // Leer TODA la respuesta del login hasta palabra vacía (fin de sentencia)
        // IMPORTANTE: En RouterOS 10.x, el orden puede ser: !done, =ret=challenge
        // No debemos parar en !done, sino hasta encontrar palabra vacía
        $response = [];
        $challenge = null;
        $gotDone = false;
        $gotTrap = false;

        while (true) {
            $word = $this->readWord($socket);

            // Palabra vacía = fin de sentencia
            if ($word === '') {
                break;
            }

            $response[] = $word;
            Log::debug('[VPN] Login word', ['word' => $word]);

            // Detectar challenge
            if (str_starts_with($word, '=ret=')) {
                $challenge = substr($word, 5);
            }

            if ($word === '!done') {
                $gotDone = true;
            }

            if ($word === '!trap') {
                $gotTrap = true;
            }
        }

        // Si hay trap, el login falló
        if ($gotTrap) {
            Log::error('[VPN] Login trap received', ['response' => $response]);
            return false;
        }

        // Si hay challenge, necesitamos hacer login MD5
        if ($challenge) {
            Log::debug('[VPN] Challenge detectado, realizando login MD5', [
                'challenge' => $challenge,
                'response' => $response,
            ]);

            $challengeBin = hex2bin($challenge);
            $hash = md5(chr(0) . $this->apiPass . $challengeBin);

            $this->writeCommand($socket, '/login', [
                '=name=' . $this->apiUser,
                '=response=00' . $hash,
            ]);

            // Leer respuesta del segundo login hasta palabra vacía
            $md5Response = [];
            while (true) {
                $word = $this->readWord($socket);

                if ($word === '') {
                    break;
                }

                $md5Response[] = $word;
                Log::debug('[VPN] MD5 Login word', ['word' => $word]);

                if ($word === '!trap') {
                    Log::error('[VPN] Login MD5 fallido', ['response' => $md5Response]);
                    return false;
                }
            }
        }

        Log::info('[VPN] Login exitoso', ['response' => $response]);
        return true;
    }

    // ==============================
    // LECTURA DE UNA SENTENCIA
    // ==============================
    private function readSentence($socket): array
    {
        $response = [];

        while (true) {
            $word = $this->readWord($socket);

            if ($word === '') {
                break;
            }

            $response[] = $word;

            if ($word === '!done' || $word === '!trap') {
                break;
            }
        }

        return $response;
    }

    // ==============================
    // HELPERS API
    // ==============================
    private function writeCommand($socket, string $command, array $params = []): void
    {
        $this->writeWord($socket, $command);
        foreach ($params as $param) {
            $this->writeWord($socket, $param);
        }
        fwrite($socket, chr(0)); // fin de sentencia
    }

    private function writeWord($socket, string $word): void
    {
        $len = strlen($word);

        // Implementación suficiente para respuestas pequeñas/medias
        if ($len < 0x80) {
            fwrite($socket, chr($len));
        } elseif ($len < 0x4000) { // 2 bytes
            $len |= 0x8000;
            fwrite($socket, chr(($len >> 8) & 0xFF));
            fwrite($socket, chr($len & 0xFF));
        } else {
            // Para respuestas enormes deberías implementar todos los casos de la doc oficial.[web:7]
            fwrite($socket, chr(0x80 | ($len >> 8)));
            fwrite($socket, chr($len & 0xFF));
        }

        fwrite($socket, $word);
    }

    private function readWord($socket): string
    {
        $byte = fread($socket, 1);
        if ($byte === '' || $byte === false) {
            return '';
        }

        $len = ord($byte);
        if ($len === 0) {
            return '';
        }

        // Decodificar longitud según protocolo API MikroTik
        if (($len & 0x80) == 0) {
            // 1 byte: 0xxxxxxx (0 - 127) -> Ya tenemos $len
        } elseif (($len & 0xC0) == 0x80) {
            // 2 bytes: 10xxxxxx xxxxxxxx (128 - 16383)
            $byte2 = ord(fread($socket, 1));
            $len = (($len & 0x3F) << 8) + $byte2;
        } elseif (($len & 0xE0) == 0xC0) {
            // 3 bytes: 110xxxxx xxxxxxxx xxxxxxxx (16384 - 2097151)
            $byte2 = ord(fread($socket, 1));
            $byte3 = ord(fread($socket, 1));
            $len = (($len & 0x1F) << 16) + ($byte2 << 8) + $byte3;
        } elseif (($len & 0xF0) == 0xE0) {
            // 4 bytes: 1110xxxx ... (2097152 - 268435455)
            $byte2 = ord(fread($socket, 1));
            $byte3 = ord(fread($socket, 1));
            $byte4 = ord(fread($socket, 1));
            $len = (($len & 0x0F) << 24) + ($byte2 << 16) + ($byte3 << 8) + $byte4;
        } else {
            // 5 bytes (raro, > 268MB): 11110000 ...
            // Implementación simplificada, lee 4 bytes mas
            fread($socket, 4);
            return ''; // Retornamos vacío para evitar bloqueo
        }

        if ($len <= 0) {
            return '';
        }

        // Leer datos eficientemente
        $data = '';
        $read = 0;
        while ($read < $len) {
            $chunk = fread($socket, $len - $read);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
            $read = strlen($data);
        }

        return $data;
    }

    // ==============================
    // HELPERS GENERALES
    // ==============================
    private function error(string $msg): array
    {
        return [
            'success' => false,
            'connected' => false,
            'message' => "❌ $msg",
            'assigned_ip' => null,
        ];
    }

    private function sanitizeName(string $name): string
    {
        return substr(
            preg_replace('/[^a-zA-Z0-9-_]/', '-', $name),
            0,
            20
        );
    }
}
