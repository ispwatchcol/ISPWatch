<?php

namespace App\Services\MikroTik;

use App\Models\Router;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the address the CORE must actually dial to reach a client router.
 *
 * Client routers sit behind the CORE's L2TP overlay and their PPP secret has no
 * fixed `remote-address`, so the CORE hands them whatever is free in the tenant
 * pool (`pool-vpn-<tenant>` = x.x.x.2-254). `router.ip` is written once when the
 * VPN script is generated and then goes stale the first time the tunnel
 * reconnects onto a different pool address.
 *
 * When that happens EVERY push (`/system ssh-exec address=<stale ip>`) targets
 * an address nobody answers, and RouterOS reports `<connection failed>` /
 * `action timed out` — which reads like a client firewall or credentials
 * problem, so the operator goes and audits the client router while the real
 * cause is that we are dialling a dead address.
 *
 * Ground truth is the CORE's own `/ppp active` table: the session whose `name`
 * equals the router's `vpn_username` carries the address the overlay is really
 * using right now. We use that, and write it back so the UI stops lying.
 */
class RouterEndpointResolver
{
    /** Per-request memo: router id => resolved endpoint. */
    private array $cache = [];

    /** Per-request memo of the CORE's /ppp active table (one fetch per request). */
    private ?array $activeSessions = null;

    private PppSecretManager $pppManager;

    public function __construct(?PppSecretManager $pppManager = null)
    {
        $this->pppManager = $pppManager ?? new PppSecretManager();
    }

    /**
     * @return array{ip: string, ssh_port: int, api_port: int, source: string, drifted: bool, stored_ip: ?string, duplicate_tunnels: array}
     */
    public function resolve(Router $router): array
    {
        $key = (string) $router->id;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $storedIp = $router->ip;
        $endpoint = [
            'ip'        => (string) $storedIp,
            'ssh_port'  => $router->sshPort(),
            'api_port'  => (int) ($router->puerto_api ?: 8728),
            'source'    => 'db',
            'drifted'   => false,
            'stored_ip' => $storedIp,
            // Otras sesiones activas entrando desde la misma IP pública. Con
            // L2TP eso no es redundancia, es una colisión: los dos túneles se
            // reciclan y la dirección de este endpoint deja de ser fiable.
            'duplicate_tunnels' => [],
        ];

        // WireGuard no tiene pool ni deriva: la dirección del overlay se asigna
        // una vez (allowed-address del peer) y es fija por definición. Consultar
        // /ppp active para estos routers no solo es inútil, es engañoso — no
        // aparecen ahí, y el "no encontrado" se confundiría con un túnel caído.
        if ($router->usesWireguard()) {
            $wgIp = trim((string) ($router->wg_address ?: $storedIp));
            $endpoint['ip']     = $wgIp;
            $endpoint['source'] = 'wireguard';

            return $this->cache[$key] = $endpoint;
        }

        $session = $this->liveSession($router);
        $liveIp  = trim((string) ($session['address'] ?? ''));

        if ($session !== null && $liveIp !== '') {
            $endpoint['ip']     = $liveIp;
            $endpoint['source'] = 'ppp-active';
            $endpoint['duplicate_tunnels'] = $this->pppManager->sessionsSharingCallerId(
                $this->sessions(),
                $session
            );

            if ($liveIp !== $storedIp) {
                $endpoint['drifted'] = true;
                $this->persistLiveIp($router, $liveIp, $storedIp);
            }

            if (!empty($endpoint['duplicate_tunnels'])) {
                Log::warning('[RouterEndpointResolver] túnel duplicado desde la misma IP pública', [
                    'router_id' => $router->id,
                    'router'    => $router->name,
                    'caller_id' => $session['caller_id'] ?? null,
                    'others'    => array_column($endpoint['duplicate_tunnels'], 'name'),
                ]);
            }
        }

        return $this->cache[$key] = $endpoint;
    }

    /**
     * Convenience for callers that only need the address to dial.
     */
    public function resolveIp(Router $router): string
    {
        return $this->resolve($router)['ip'];
    }

    /**
     * The CORE's live `/ppp active` row for this router, or null when it is not
     * connected (or has no vpn_username on file).
     *
     * Devuelve la fila entera y no solo la dirección porque el `caller-id` —la
     * pública desde la que disca— es lo que permite ver si hay otro túnel
     * compitiendo por el mismo punto de entrada.
     *
     * @return array<string, mixed>|null
     */
    private function liveSession(Router $router): ?array
    {
        $vpnUser = trim((string) $router->vpn_username);
        if ($vpnUser === '') {
            return null;
        }

        foreach ($this->sessions() as $session) {
            if (($session['name'] ?? '') === $vpnUser) {
                return $session;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sessions(): array
    {
        if ($this->activeSessions !== null) {
            return $this->activeSessions;
        }

        try {
            $result = $this->pppManager->getPppActive();
            $this->activeSessions = ($result['success'] ?? false)
                ? ($result['connections'] ?? [])
                : [];

            if (!($result['success'] ?? false)) {
                Log::warning('[RouterEndpointResolver] no se pudo leer /ppp active del CORE', [
                    'message' => $result['message'] ?? 'unknown',
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[RouterEndpointResolver] excepción leyendo /ppp active', ['error' => $e->getMessage()]);
            $this->activeSessions = [];
        }

        return $this->activeSessions;
    }

    /**
     * Keep the DB in step with the overlay. Best-effort: a failed write must
     * never abort provisioning, we already hold the address we need in memory.
     */
    private function persistLiveIp(Router $router, string $liveIp, ?string $storedIp): void
    {
        Log::warning('[RouterEndpointResolver] la IP overlay del router cambió — actualizando BD', [
            'router_id' => $router->id,
            'router'    => $router->name,
            'stored_ip' => $storedIp,
            'live_ip'   => $liveIp,
        ]);

        try {
            $router->ip = $liveIp;
            $router->save();
        } catch (\Throwable $e) {
            Log::error('[RouterEndpointResolver] no se pudo persistir la IP viva', [
                'router_id' => $router->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
