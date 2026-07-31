<?php

namespace App\Services\MikroTik;

use App\Models\Router;
use Illuminate\Support\Facades\Log;
use phpseclib3\Crypt\EC;

/**
 * Lado CORE del transporte WireGuard: interfaz, direcciones de overlay por
 * tenant y alta/baja de peers.
 *
 * POR QUÉ EXISTE ESTE TRANSPORTE
 * ------------------------------
 * L2TP/IPsec parte el túnel en dos flujos (IKE en udp/500 y los datos en
 * udp/1701). Si el router cliente tiene multi-WAN, balanceo o un src-nat que
 * reescribe el origen, cada flujo puede salir por una IP pública distinta: el
 * CORE levanta la SA contra la primera, el paquete L2TP llega de la segunda sin
 * política que lo cubra y — con use-ipsec=required — lo rechaza con
 * "no IPsec encryption while it was required". Eso tumbó CORE_TOCAIMA 8 días.
 *
 * WireGuard no puede fallar así: es un solo flujo UDP y el peer se identifica
 * por su clave pública, de modo que el CORE aprende el endpoint de cualquier
 * origen autenticado. Que la IP pública del cliente cambie es irrelevante.
 *
 * LAS CLAVES LAS GENERA ISPWATCH, NO EL ROUTER
 * --------------------------------------------
 * Con phpseclib (X25519). Si esperáramos a que el router nos entregara su
 * clave pública necesitaríamos un túnel previo para leerla — y un router recién
 * instalado no tiene ninguno. Generándolas acá, el script que se le pega es
 * autosuficiente igual que el de L2TP.
 */
class WireguardManager
{
    /** Nombre de la interfaz en el CORE y en cada cliente. */
    public const IFACE = 'ISPWatch-WG';

    /** Puerto en el que ESCUCHA el CORE. Es el único que debe coincidir. */
    public const CORE_LISTEN_PORT = 13231;

    /**
     * Se resuelve perezosamente: acuñar claves, calcular la subred de un tenant
     * o armar el comentario de un peer no necesitan hablar con el CORE, y no
     * tiene sentido que abrir esta clase exija una app booteada.
     */
    private ?MikroTikConnectionManager $connection;

    public function __construct(?MikroTikConnectionManager $connection = null)
    {
        $this->connection = $connection;
    }

    private function connection(): MikroTikConnectionManager
    {
        return $this->connection ??= new MikroTikConnectionManager();
    }

    /**
     * Par de claves X25519 en base64, el formato que espera RouterOS.
     *
     * @return array{private: string, public: string}
     */
    public function generateKeypair(): array
    {
        $key = EC::createKey('Curve25519');

        return [
            'private' => base64_encode($key->toString('MontgomeryPrivate')),
            'public'  => base64_encode($key->getPublicKey()->toString('MontgomeryPublic')),
        ];
    }

    /**
     * /24 de overlay del tenant.
     *
     * El transporte L2TP reparte 172.16.0.0/12 desde 172.16 hacia arriba
     * (172.16.x para tenants 1-254, 172.17.x para 255-508...). WireGuard arranca
     * en 172.18 para no pisarlo. Ambos caben en el 172.16.0.0/12 que la lista
     * ISPWATCH_VPN_POOLS del CORE ya tiene en bypass, así que no hay que tocar
     * el firewall al dar de alta un tenant nuevo.
     *
     * Colisionarían recién pasando los 508 tenants en L2TP; si eso llegara a
     * pasar hay que mover una de las dos bases, no seguir apilando.
     *
     * @return array{local_address: string, client_start: string, network_cidr: string}
     */
    public function tenantSubnet(int $tenantId): array
    {
        $third  = (($tenantId - 1) % 254) + 1;
        $second = 18 + intdiv($tenantId - 1, 254);

        return [
            'local_address' => "172.{$second}.{$third}.1",
            'client_start'  => "172.{$second}.{$third}.2",
            'network_cidr'  => "172.{$second}.{$third}.0/24",
        ];
    }

    /**
     * Asegura la interfaz WireGuard del CORE y devuelve su clave pública, que
     * es lo que cada cliente necesita para configurar su peer.
     *
     * @return array{success: bool, public_key?: string, listen_port?: int, message?: string}
     */
    public function ensureCoreInterface(): array
    {
        return $this->withCore(function ($api, $sock) {
            $api->sendCommand($sock, '/interface/wireguard/print');
            foreach ($api->readAllRecords($sock, 8000) as $iface) {
                if (($iface['name'] ?? '') === self::IFACE) {
                    return [
                        'success'     => true,
                        'public_key'  => $iface['public-key'] ?? '',
                        'listen_port' => (int) ($iface['listen-port'] ?? self::CORE_LISTEN_PORT),
                    ];
                }
            }

            $api->sendCommand($sock, '/interface/wireguard/add', [
                '=name=' . self::IFACE,
                '=listen-port=' . self::CORE_LISTEN_PORT,
                '=comment=ISPWatch WireGuard CORE',
            ]);
            $res = $api->readAllRecordsWithStatus($sock, 2000);
            if (!empty($res['trap'])) {
                return ['success' => false, 'message' => $res['trap']];
            }

            $api->sendCommand($sock, '/interface/wireguard/print');
            foreach ($api->readAllRecords($sock, 8000) as $iface) {
                if (($iface['name'] ?? '') === self::IFACE) {
                    return [
                        'success'     => true,
                        'public_key'  => $iface['public-key'] ?? '',
                        'listen_port' => (int) ($iface['listen-port'] ?? self::CORE_LISTEN_PORT),
                    ];
                }
            }

            return ['success' => false, 'message' => 'La interfaz se creó pero no se pudo leer de vuelta'];
        });
    }

    /**
     * Dirección del CORE dentro del overlay del tenant. Idempotente.
     */
    public function ensureTenantAddress(int $tenantId): array
    {
        $subnet = $this->tenantSubnet($tenantId);
        $want   = $subnet['local_address'] . '/24';

        return $this->withCore(function ($api, $sock) use ($want, $tenantId) {
            $api->sendCommand($sock, '/ip/address/print');
            foreach ($api->readAllRecords($sock, 8000) as $addr) {
                if (($addr['address'] ?? '') === $want) {
                    return ['success' => true, 'action' => 'already_exists'];
                }
            }

            $api->sendCommand($sock, '/ip/address/add', [
                '=address=' . $want,
                '=interface=' . self::IFACE,
                '=comment=ISPWatch WG tenant ' . $tenantId,
            ]);
            $res = $api->readAllRecordsWithStatus($sock, 2000);
            if (!empty($res['trap'])) {
                return ['success' => false, 'message' => $res['trap']];
            }

            return ['success' => true, 'action' => 'created'];
        });
    }

    /**
     * Primera dirección libre del /24 del tenant, mirando lo que ya está
     * asignado en la BD. El .1 es del CORE, así que se reparte desde el .2.
     */
    public function allocateOverlayIp(Router $router): ?string
    {
        $tenantId = (int) $router->tenant_id;
        if ($tenantId <= 0) {
            return null;
        }

        if (!empty($router->wg_address)) {
            return $router->wg_address;
        }

        $subnet = $this->tenantSubnet($tenantId);
        $prefix = substr($subnet['local_address'], 0, strrpos($subnet['local_address'], '.') + 1);

        $taken = Router::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('wg_address')
            ->pluck('wg_address')
            ->all();

        for ($host = 2; $host <= 254; $host++) {
            $candidate = $prefix . $host;
            if (!in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }

        Log::error('[WireGuard] /24 del tenant agotado', ['tenant_id' => $tenantId]);

        return null;
    }

    /**
     * Alta (o reemplazo) del peer del router en el CORE. Se identifica por
     * comentario para que re-provisionar no deje peers huérfanos acumulándose.
     */
    public function upsertPeer(Router $router, string $publicKey, string $overlayIp): array
    {
        $comment = $this->peerComment($router);

        return $this->withCore(function ($api, $sock) use ($comment, $publicKey, $overlayIp) {
            $api->sendCommand($sock, '/interface/wireguard/peers/print');
            foreach ($api->readAllRecords($sock, 8000) as $peer) {
                if (($peer['comment'] ?? '') === $comment && !empty($peer['.id'])) {
                    $api->sendCommand($sock, '/interface/wireguard/peers/remove', ['=.id=' . $peer['.id']]);
                    $api->readAllRecordsWithStatus($sock, 2000);
                }
            }

            $api->sendCommand($sock, '/interface/wireguard/peers/add', [
                '=interface=' . self::IFACE,
                '=public-key=' . $publicKey,
                '=allowed-address=' . $overlayIp . '/32',
                '=comment=' . $comment,
            ]);
            $res = $api->readAllRecordsWithStatus($sock, 2000);
            if (!empty($res['trap'])) {
                return ['success' => false, 'message' => $res['trap']];
            }

            return ['success' => true];
        });
    }

    /**
     * Peers registrados en el CORE, indexados por comentario. `last-handshake`
     * vacío = nunca conectó; es la señal de salud del transporte WireGuard,
     * equivalente a mirar /ppp active en L2TP.
     *
     * @return array{success: bool, peers?: array<string, array<string, mixed>>, message?: string}
     */
    public function peers(): array
    {
        return $this->withCore(function ($api, $sock) {
            $api->sendCommand($sock, '/interface/wireguard/peers/print');
            $peers = [];
            foreach ($api->readAllRecords($sock, 12000) as $peer) {
                $key = $peer['comment'] ?? ($peer['public-key'] ?? '');
                if ($key !== '') {
                    $peers[$key] = $peer;
                }
            }

            return ['success' => true, 'peers' => $peers];
        });
    }

    public function peerComment(Router $router): string
    {
        return 'ISPWatch - ' . $router->name . ' (router ' . $router->id . ')';
    }

    /**
     * Abre la API del CORE, ejecuta y cierra siempre. Cualquier fallo se
     * devuelve como array en vez de propagar: el provisioning nunca debe
     * abortar por no poder hablar con el CORE en un paso auxiliar.
     */
    private function withCore(callable $fn): array
    {
        $connection = $this->connection();
        $api        = $connection->getApiProtocol();
        $sock       = null;

        try {
            $sock = $api->connect(
                $connection->getApiHost(),
                $connection->getApiPort(),
                15
            );

            if (!$sock) {
                return ['success' => false, 'message' => 'No se pudo conectar a la API del CORE'];
            }

            if (!$api->login($sock, $connection->getApiUser(), $connection->getApiPass())) {
                return ['success' => false, 'message' => 'Autenticación rechazada por la API del CORE'];
            }

            return $fn($api, $sock);
        } catch (\Throwable $e) {
            Log::error('[WireGuard] fallo hablando con el CORE', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage()];
        } finally {
            if ($sock) {
                $api->close($sock);
            }
        }
    }
}
