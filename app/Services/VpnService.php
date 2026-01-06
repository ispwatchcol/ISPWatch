<?php

namespace App\Services;

use App\Models\Router;

class VpnService
{
    /**
     * ==============================
     * IPs DEL CORE
     * ==============================
     */

    // IP pública → SOLO para la VPN
    private string $vpnPublicIp = '190.14.255.107';

    // IP local → SOLO para la API (Laravel está en la LAN)
    private string $apiHost = '192.168.88.1';
    private int    $apiPort = 8728;

    /**
     * ==============================
     * CREDENCIALES VPN
     * ==============================
     */
    private string $vpnUsername = 'core-isp-001';
    private string $vpnPassword = 'claveSegura';
    private string $ipsecSecret = 'ISPWATCH_SECRET';

    /**
     * ==============================
     * CREDENCIALES API (LECTURA)
     * ==============================
     */
    private string $apiUser = 'ispwatch-api';
    private string $apiPass = 'ClaveMuySegura';

    /**
     * =========================================================
     * GENERA SCRIPT L2TP CLIENTE (PARA CORE REMOTO)
     * =========================================================
     */
    public function generateScript(Router $router): string
    {
        $routerName = $this->sanitizeName($router->name);

        return <<<SCRIPT
/interface l2tp-client
add name=ISPWatch-VPN-{$routerName} \\
    connect-to={$this->vpnPublicIp} \\
    user={$this->vpnUsername} \\
    password={$this->vpnPassword} \\
    use-ipsec=yes \\
    ipsec-secret={$this->ipsecSecret} \\
    profile=default-encryption \\
    add-default-route=no \\
    disabled=no
SCRIPT;
    }

    /**
     * =========================================================
     * VERIFICA ESTADO REAL DE LA VPN EN EL CORE
     * =========================================================
     */
    public function verifyConnection(Router $router): array
    {
        $socket = @fsockopen($this->apiHost, $this->apiPort, $errno, $errstr, 5);

        if (!$socket) {
            return $this->error("No se pudo conectar al CORE por API ($errstr)");
        }

        stream_set_timeout($socket, 10);

        // ===== LOGIN API =====
        $this->writeCommand($socket, '/login', [
            '=name=' . $this->apiUser,
            '=password=' . $this->apiPass,
        ]);

        $login = $this->readSentence($socket);
        if (in_array('!trap', $login)) {
            fclose($socket);
            return $this->error('Error de autenticación API');
        }

        /**
         * ==============================
         * 1️⃣ PPP ACTIVE
         * ==============================
         */
        $this->writeCommand($socket, '/ppp/active/print');
        $pppActive = $this->readRecords($socket);

        /**
         * ==============================
         * 2️⃣ L2TP SERVER
         * ==============================
         */
        $this->writeCommand($socket, '/interface/l2tp-server/print');
        $l2tpServer = $this->readRecords($socket);

        fclose($socket);

        /**
         * ==============================
         * VERIFICACIÓN REAL
         * ==============================
         */
        foreach ($pppActive as $conn) {
            if (
                isset($conn['user']) &&
                $conn['user'] === $this->vpnUsername
            ) {
                return [
                    'success'     => true,
                    'connected'   => true,
                    'message'     => '✅ VPN ACTIVA (PPP activo en CORE)',
                    'assigned_ip' => $conn['address'] ?? null,
                ];
            }
        }

        return [
            'success'     => true,
            'connected'   => false,
            'message'     => '❌ VPN CAÍDA (no existe sesión PPP activa)',
            'assigned_ip' => null,
        ];
    }

    /**
     * =========================================================
     * LEE REGISTROS MULTI-LÍNEA
     * =========================================================
     */
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

            if ($word === '!done') {
                if (!empty($current)) {
                    $records[] = $current;
                }
                break;
            }

            if (str_starts_with($word, '=')) {
                [$k, $v] = explode('=', substr($word, 1), 2);
                $current[$k] = $v;
            }
        }

        return $records;
    }

    /**
     * =========================================================
     * LEE RESPUESTA SIMPLE
     * =========================================================
     */
    private function readSentence($socket): array
    {
        $response = [];
        while (true) {
            $word = $this->readWord($socket);
            if ($word === '') break;
            $response[] = $word;
            if ($word === '!done' || $word === '!trap') break;
        }
        return $response;
    }

    /**
     * =========================================================
     * API HELPERS
     * =========================================================
     */
    private function writeCommand($socket, string $command, array $params = []): void
    {
        $this->writeWord($socket, $command);
        foreach ($params as $param) {
            $this->writeWord($socket, $param);
        }
        fwrite($socket, chr(0));
    }

    private function writeWord($socket, string $word): void
    {
        $len = strlen($word);
        if ($len < 0x80) {
            fwrite($socket, chr($len));
        } else {
            fwrite($socket, chr(($len >> 8) | 0x80));
            fwrite($socket, chr($len & 0xFF));
        }
        fwrite($socket, $word);
    }

    private function readWord($socket): string
    {
        $c = ord(fread($socket, 1));
        if ($c === 0) return '';

        if ($c < 0x80) {
            $len = $c;
        } else {
            $len = (($c & 0x7F) << 8) + ord(fread($socket, 1));
        }

        return fread($socket, $len);
    }

    /**
     * =========================================================
     * HELPERS
     * =========================================================
     */
    private function error(string $msg): array
    {
        return [
            'success'     => false,
            'connected'   => false,
            'message'     => "❌ $msg",
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
