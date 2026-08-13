<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Services\MikroTik\PppSecretManager;
use App\Services\MikroTik\WireguardManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Audita que cada router siga teniendo túnel vivo contra el CORE.
 *
 * POR QUÉ EXISTE
 * --------------
 * CORE_TOCAIMA estuvo 8 días con el túnel caído (212 clientes sin gestión,
 * cortes automáticos fallando) y nadie se enteró. El failover de cortes solo ve
 * fallos POR CLIENTE, así que nunca dijo "este router no está". Este comando
 * mira lo único que importa: ¿hay túnel?
 *
 * LA SEÑAL DEPENDE DEL TRANSPORTE
 * -------------------------------
 *   WireGuard → last-handshake del peer en el CORE. No hay "sesión": el peer
 *               existe siempre, lo que envejece es el handshake. Un peer sano
 *               renueva cada ~2 min por el persistent-keepalive de 25s.
 *   L2TP      → presencia en /ppp active. Si el vpn_username no está ahí, el
 *               túnel no existe, punto.
 *
 * No toca nada: solo lee y alerta.
 */
class VerifyVpnTunnels extends Command
{
    /**
     * php artisan vpn:verify-tunnels
     * php artisan vpn:verify-tunnels --stale=15
     * php artisan vpn:verify-tunnels --no-mail
     */
    protected $signature = 'vpn:verify-tunnels
                            {--stale=15 : Minutos sin handshake tras los que un peer WireGuard se da por caído}
                            {--no-mail : No enviar el email de alerta (solo log/consola)}';

    protected $description = 'Verifica que los routers tengan túnel vivo contra el CORE (handshake WireGuard o sesión L2TP) y alerta los caídos. No modifica nada.';

    public function handle(WireguardManager $wireguard, PppSecretManager $ppp): int
    {
        $staleMinutes = max(1, (int) $this->option('stale'));

        // Solo routers que se gestionan POR el CORE. Los que no tienen ni
        // vpn_username ni dirección de overlay nunca se onboardearon por VPN
        // (se alcanzan de otra forma), y marcarlos como "caídos" sería ruido.
        $routers = Router::withoutGlobalScopes()
            ->where(function ($q) {
                $q->whereNotNull('wg_address')->orWhereNotNull('vpn_username');
            })
            ->get()
            ->filter(fn (Router $r) => $r->usesWireguard() || !empty($r->vpn_username));

        if ($routers->isEmpty()) {
            $this->info('No hay routers gestionados por el CORE para verificar.');
            return Command::SUCCESS;
        }

        $wgPeers = [];
        if ($routers->contains(fn (Router $r) => $r->usesWireguard())) {
            $result = $wireguard->peers();
            if (!($result['success'] ?? false)) {
                $this->error('No se pudo leer los peers WireGuard del CORE: ' . ($result['message'] ?? '?'));
                Log::error('[VPN-NOSHOW] No se pudo leer los peers WireGuard del CORE', [
                    'error' => $result['message'] ?? '',
                ]);
                return Command::FAILURE;
            }
            $wgPeers = $result['peers'] ?? [];
        }

        $pppActive   = [];
        $pppSessions = [];
        if ($routers->contains(fn (Router $r) => !$r->usesWireguard())) {
            $result = $ppp->getPppActive();
            if (!($result['success'] ?? false)) {
                $this->error('No se pudo leer /ppp active del CORE: ' . ($result['message'] ?? '?'));
                Log::error('[VPN-NOSHOW] No se pudo leer /ppp active del CORE', [
                    'error' => $result['message'] ?? '',
                ]);
                return Command::FAILURE;
            }
            $pppSessions = $result['connections'] ?? [];
            foreach ($pppSessions as $session) {
                $pppActive[$session['name'] ?? ''] = $session;
            }
        }

        $rows = [];
        $down = [];
        $duplicated = [];

        foreach ($routers as $router) {
            $row = $router->usesWireguard()
                ? $this->checkWireguard($router, $wgPeers, $wireguard, $staleMinutes)
                : $this->checkL2tp($router, $pppActive, $pppSessions, $ppp);

            $rows[] = $row;
            if ($row['status'] === 'DOWN') {
                $down[] = $row;
            }
            if ($row['status'] === 'DUPLICADO') {
                $duplicated[] = $row;
            }
        }

        $this->table(
            ['Router', 'Tenant', 'Transporte', 'Endpoint', 'Señal', 'Estado'],
            collect($rows)->map(fn ($r) => [
                "#{$r['router_id']} {$r['router_name']}",
                $r['tenant_id'],
                $r['transport'],
                $r['endpoint'],
                $r['signal'],
                $r['status'],
            ])->all()
        );

        if (empty($down) && empty($duplicated)) {
            $this->info('✓ Todos los túneles vivos.');
            return Command::SUCCESS;
        }

        // Un túnel duplicado NO está caído — por eso este comando lo daba por
        // bueno y el operador se quedaba sin explicación mientras la gestión
        // fallaba de forma intermitente. Se alerta aparte, con su propia frase.
        $lines = collect($down)->map(
            fn ($r) => "• Router #{$r['router_id']} {$r['router_name']} (tenant {$r['tenant_id']}, {$r['transport']}): TÚNEL CAÍDO — {$r['signal']}."
        )->concat(collect($duplicated)->map(
            fn ($r) => "• Router #{$r['router_id']} {$r['router_name']} (tenant {$r['tenant_id']}, l2tp): TÚNEL DUPLICADO — {$r['signal']}. " .
                       'El túnel figura activo, pero los dos se reciclan entre sí y la gestión desde el CORE falla de forma intermitente.'
        ));

        Log::error('[VPN-NOSHOW] ' . count($down) . ' router(s) sin túnel contra el CORE. ' . $lines->implode(' '));
        $this->error($lines->implode("\n"));

        if (!$this->option('no-mail')) {
            $this->sendAlertEmail($lines, count($down));
        }

        return Command::FAILURE;
    }

    /**
     * @param array<string, array<string, mixed>> $peers
     * @return array<string, mixed>
     */
    private function checkWireguard(Router $router, array $peers, WireguardManager $wireguard, int $staleMinutes): array
    {
        $peer = $peers[$wireguard->peerComment($router)] ?? null;

        if (!$peer) {
            return $this->row($router, 'wireguard', (string) $router->wg_address, 'sin peer registrado en el CORE', 'DOWN');
        }

        $handshake = trim((string) ($peer['last-handshake'] ?? ''));
        if ($handshake === '') {
            return $this->row($router, 'wireguard', (string) $router->wg_address, 'nunca hizo handshake', 'DOWN');
        }

        $seconds = $this->parseRouterOsDuration($handshake);
        $status  = ($seconds !== null && $seconds > $staleMinutes * 60) ? 'DOWN' : 'UP';
        $signal  = 'handshake hace ' . $handshake;

        return $this->row($router, 'wireguard', (string) $router->wg_address, $signal, $status);
    }

    /**
     * @param array<string, array<string, mixed>> $active   sesiones indexadas por nombre
     * @param array<int, array<string, mixed>>    $sessions  la tabla completa, para cruzar caller-id
     * @return array<string, mixed>
     */
    private function checkL2tp(Router $router, array $active, array $sessions, PppSecretManager $ppp): array
    {
        $session = $active[trim((string) $router->vpn_username)] ?? null;

        if (!$session) {
            return $this->row($router, 'l2tp', (string) $router->ip, 'sin sesión en /ppp active', 'DOWN');
        }

        $endpoint = (string) ($session['address'] ?? $router->ip);
        $siblings = $ppp->sessionsSharingCallerId($sessions, $session);

        if (!empty($siblings)) {
            $others = implode(', ', array_map(fn ($s) => "{$s['name']} ({$s['address']})", $siblings));

            return $this->row(
                $router,
                'l2tp',
                $endpoint,
                'otra sesión desde la misma pública ' . ($session['caller_id'] ?? '?') . ': ' . $others,
                'DUPLICADO'
            );
        }

        return $this->row(
            $router,
            'l2tp',
            $endpoint,
            'activo hace ' . ($session['uptime'] ?? '?'),
            'UP'
        );
    }

    /** @return array<string, mixed> */
    private function row(Router $router, string $transport, string $endpoint, string $signal, string $status): array
    {
        return [
            'router_id'   => $router->id,
            'router_name' => $router->name,
            'tenant_id'   => $router->tenant_id,
            'transport'   => $transport,
            'endpoint'    => $endpoint !== '' ? $endpoint : '—',
            'signal'      => $signal,
            'status'      => $status,
        ];
    }

    /**
     * Convierte una duración de RouterOS ("1m30s", "5h6m21s", "2d3h") a segundos.
     * Devuelve null si el formato no se reconoce, y quien llama lo trata como
     * "no puedo afirmar que esté caído" — preferimos callar antes que alertar en
     * falso sobre un formato que no supimos leer.
     */
    private function parseRouterOsDuration(string $duration): ?int
    {
        if (!preg_match_all('/(\d+)([wdhms])/', $duration, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $units = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
        $total = 0;
        foreach ($matches as $match) {
            $total += ((int) $match[1]) * ($units[$match[2]] ?? 0);
        }

        return $total;
    }

    /** @param \Illuminate\Support\Collection<int,string> $lines */
    private function sendAlertEmail($lines, int $count): void
    {
        $to = config('mail.billing_alert_address') ?: config('mail.from.address');
        if (!$to) {
            $this->warn('Sin destinatario de alerta (mail.billing_alert_address / mail.from.address). Solo se registró en el log.');
            return;
        }

        $body = 'ALERTA DE TÚNEL VPN — ' . now()->toDateTimeString() . "\n\n"
              . "Routers con problema de túnel contra el CORE:\n\n"
              . $lines->implode("\n") . "\n\n"
              . "Mientras el túnel esté caído, ese router NO recibe altas, cambios de plan,\n"
              . "cortes ni reconexiones. Los clientes siguen navegando: lo que se pierde es\n"
              . "el control.\n\n"
              . "Qué revisar:\n"
              . "  1) WireGuard: que el peer exista en el CORE y que la interfaz del cliente\n"
              . "     no esté deshabilitada por 'Listen port already used'.\n"
              . "  2) L2TP: log del CORE. 'no IPsec encryption while it was required' = el\n"
              . "     IKE y los datos salen del cliente por IPs públicas distintas\n"
              . "     (multi-WAN); hacen falta las reglas ISPWatch-CORE-no-nat/no-mark.\n"
              . "  3) Que el router tenga internet y llegue a la IP pública del CORE.\n"
              . "  4) TÚNEL DUPLICADO: dos secrets distintos discando desde la MISMA IP\n"
              . "     pública. No es redundancia — con L2TP/IPsec se reciclan entre sí y el\n"
              . "     router queda con dos direcciones de overlay, así que todo lo que el\n"
              . "     CORE inicie hacia él muere a mitad de camino. Deja UNO solo: quítale\n"
              . "     el l2tp-client al equipo que sobre y borra su secret del CORE.\n";

        try {
            Mail::raw($body, function ($msg) use ($to, $count) {
                $msg->to($to)->subject("⚠️ ISPWatch: {$count} router(s) sin túnel contra el CORE");
            });
            $this->info("Alerta enviada a {$to}.");
        } catch (\Throwable $e) {
            Log::error('[VPN-NOSHOW] No se pudo enviar el email de alerta: ' . $e->getMessage());
            $this->warn('No se pudo enviar el email de alerta (ver log): ' . $e->getMessage());
        }
    }
}
