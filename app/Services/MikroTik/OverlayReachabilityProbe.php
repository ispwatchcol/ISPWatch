<?php

namespace App\Services\MikroTik;

use Illuminate\Support\Facades\Log;

/**
 * ¿Hay REALMENTE un router escuchando en esa dirección del overlay?
 *
 * Todo lo que ISPWatch hace contra un router cliente (API por túnel, ssh-exec
 * desde el CORE, colas, cortes) empieza igual: el CORE manda un paquete a una
 * IP del overlay. Cuando esa IP no la tiene nadie, cada uno de esos caminos
 * falla por su cuenta y con su propio mensaje —"tiempo de espera agotado",
 * "no respondió al login", "<connection failed>"— y ninguno dice lo único que
 * importa: que del otro lado no hay nadie.
 *
 * El caso que motivó esto (CORE_SAN_ISIDRO, 2026-08-18): el túnel L2TP estaba
 * ARRIBA —sesión activa en /ppp active, contadores subiendo, link-downs=0— pero
 * el equipo del otro lado NO tenía puesta la 172.16.16.253 que el CORE le
 * asignó, así que REENVIABA nuestros paquetes hacia su gateway (10.72.103.1) en
 * vez de contestarlos. La lectura de interfaces se colgaba 25s y culpaba al
 * firewall del cliente; el operador auditaba un firewall que estaba bien.
 *
 * La sonda distingue esos casos con un solo viaje al CORE:
 *
 *   /ping <ip> count=2 ttl=1
 *
 * El ttl=1 es la clave. Un paquete que se ENTREGA localmente no decrementa TTL,
 * así que el router dueño de la dirección contesta normal. Uno que se REENVÍA
 * sí lo decrementa, y el equipo que lo reenvía delata su identidad con un
 * "TTL exceeded" desde su propia dirección. Es decir:
 *
 *   - responde la IP consultada      → el router está vivo y es él            (alive)
 *   - responde OTRA dirección        → nadie tiene esa IP; el paquete se fue   (foreign_hop)
 *     por otro lado y quien lo reenvía es esa otra dirección
 *   - nadie responde                 → ambiguo: puede ser ICMP filtrado        (silent)
 *
 * "silent" es ambiguo A PROPÓSITO y nunca aborta nada: el propio script de
 * provisión de ISPWatch abre TCP 22/8291/8728 desde la red de gestión pero NO
 * abre ICMP, así que un cliente bien configurado con drop por defecto en el
 * chain input no contesta ping y sin embargo funciona perfecto por SSH.
 */
class OverlayReachabilityProbe
{
    /** El router contestó él mismo: está vivo en esa dirección. */
    public const STATE_ALIVE = 'alive';

    /** Contestó otro equipo: esa IP no la tiene el router que buscamos. */
    public const STATE_FOREIGN_HOP = 'foreign_hop';

    /** Nadie contestó. Puede ser ICMP filtrado — no concluye nada. */
    public const STATE_SILENT = 'silent';

    /** No pudimos preguntarle al CORE (SSH caído, salida ilegible). */
    public const STATE_UNKNOWN = 'unknown';

    /**
     * Ping corto a propósito: dos paquetes bastan para clasificar y el objetivo
     * de la sonda es AHORRAR tiempo, no gastarlo. Con el handshake SSH al CORE
     * el viaje completo ronda los 3s.
     */
    private const PROBE_TIMEOUT = 12;

    private MikroTikConnectionManager $connectionManager;

    public function __construct(?MikroTikConnectionManager $connectionManager = null)
    {
        $this->connectionManager = $connectionManager ?? new MikroTikConnectionManager();
    }

    /**
     * @return array{
     *     state: string, hop: ?string, detail: ?string, has_route: bool,
     *     has_session: bool, in_overlay: bool, raw: string
     * }
     */
    public function probe(string $clientIp): array
    {
        $blank = [
            'state'       => self::STATE_UNKNOWN,
            'hop'         => null,
            'detail'      => null,
            'has_route'   => false,
            'has_session' => false,
            'in_overlay'  => false,
            'raw'         => '',
        ];

        // La IP viaja dentro de un comando de RouterOS. Sólo se acepta una IPv4
        // literal: cualquier otra cosa se descarta aquí en vez de concatenarse.
        if (!filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $blank;
        }

        $result = $this->connectionManager->executeSsh($this->buildCommand($clientIp), self::PROBE_TIMEOUT);

        if (!($result['success'] ?? false)) {
            return $blank;
        }

        $probe = $this->parse((string) ($result['output'] ?? ''), $clientIp);

        Log::info('[OverlayReachabilityProbe] sonda de alcance', [
            'client_ip'   => $clientIp,
            'state'       => $probe['state'],
            'hop'         => $probe['hop'],
            'has_route'   => $probe['has_route'],
            'has_session' => $probe['has_session'],
        ]);

        return $probe;
    }

    /**
     * Un solo comando compuesto porque cada executeSsh() abre y cierra su propia
     * sesión SSH contra el CORE (~2.5s). Las dos primeras líneas dicen si el CORE
     * cree tener camino hacia esa IP; el ping dice si alguien la usa de verdad.
     *
     * La ruta se busca como /32 porque así es como el CORE registra a cada
     * cliente L2TP. Un router WireGuard vive dentro del /24 de su tenant y aquí
     * dará has_route=false: es correcto y es conservador — sin evidencia de
     * pertenencia al overlay la sonda no aborta nada (ver in_overlay).
     */
    private function buildCommand(string $clientIp): string
    {
        return ':put ("ISP_ROUTE:" . [:len [/ip route find dst-address="' . $clientIp . '/32"]]); '
            . ':put ("ISP_PPP:" . [:len [/ppp active find address=' . $clientIp . ']]); '
            . '/ping ' . $clientIp . ' count=2 ttl=1';
    }

    /**
     * @return array{
     *     state: string, hop: ?string, detail: ?string, has_route: bool,
     *     has_session: bool, in_overlay: bool, raw: string
     * }
     */
    private function parse(string $raw, string $clientIp): array
    {
        $clean = preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $raw) ?? $raw;
        $clean = str_replace("\r", '', $clean);

        $hasRoute   = (bool) preg_match('/ISP_ROUTE:\s*([1-9]\d*)/', $clean);
        $hasSession = (bool) preg_match('/ISP_PPP:\s*([1-9]\d*)/', $clean);

        $received = null;
        if (preg_match('/received=(\d+)/', $clean, $m)) {
            $received = (int) $m[1];
        }

        $hop       = null;
        $hopDetail = null;
        $selfReply = false;

        foreach (preg_split('/\n+/', $clean) ?: [] as $line) {
            // Fila de la tabla de /ping: "<seq> <host> <size> <ttl> <time> <status>"
            if (!preg_match('/^\s*\d+\s+(\d{1,3}(?:\.\d{1,3}){3})\b(.*)$/', $line, $m)) {
                continue;
            }

            if ($m[1] === $clientIp) {
                // El propio destino aparece también en las filas de "timeout",
                // donde no contestó nadie: sólo cuenta como respuesta si el
                // resumen confirma paquetes recibidos.
                $selfReply = true;
                continue;
            }

            $hop = $m[1];
            if (preg_match('/\b(ttl exceeded|host unreachable|net(?:work)? unreachable|packet filtered|fragmentation needed)\b/i', $m[2], $status)) {
                $hopDetail = strtolower($status[1]);
            }
        }

        $state = self::STATE_UNKNOWN;
        if ($hop !== null) {
            $state = self::STATE_FOREIGN_HOP;
        } elseif ($received !== null && $received > 0 && $selfReply) {
            $state = self::STATE_ALIVE;
        } elseif ($received === 0) {
            $state = self::STATE_SILENT;
        }

        return [
            'state'       => $state,
            'hop'         => $hop,
            'detail'      => $hopDetail,
            'has_route'   => $hasRoute,
            'has_session' => $hasSession,
            // Evidencia de que ESTA dirección es del overlay que administramos.
            // Sin ella no se saca ninguna conclusión dura: un router alcanzado
            // por fuera del túnel siempre daría "foreign_hop" (el primer salto
            // es el gateway del CORE) y abortarlo sería un falso positivo.
            'in_overlay'  => $hasRoute || $hasSession,
            'raw'         => trim($clean),
        ];
    }

    /**
     * ¿La sonda demuestra que seguir intentando es inútil?
     *
     * Sólo hay una situación concluyente: el CORE tiene túnel hacia esa IP y aun
     * así el paquete lo contesta OTRO equipo. Eso no lo arregla ningún reintento,
     * ninguna credencial y ningún puerto.
     */
    public function isConclusiveFailure(array $probe): bool
    {
        return ($probe['state'] ?? null) === self::STATE_FOREIGN_HOP && ($probe['in_overlay'] ?? false);
    }

    /**
     * Traducción del veredicto a lo que el operador tiene que ir a mirar.
     *
     * @return array{message: string, hint: string}
     */
    public function explain(array $probe, string $clientIp): array
    {
        $hop    = $probe['hop'] ?? 'otro equipo';
        $detail = $probe['detail'] ? " ({$probe['detail']})" : '';

        return [
            'message' => "El túnel hacia {$clientIp} está levantado en el CORE, pero el equipo del otro lado NO tiene esa "
                . "dirección: reenvía los paquetes hacia {$hop}{$detail} en vez de contestarlos. Mientras siga así ni la API "
                . 'ni el SSH van a responder — no es un problema de credenciales, de puertos ni de firmware.',
            'hint' => "El CORE le asignó {$clientIp} al router y la sesión VPN figura activa, pero el router no se quedó con "
                . "la dirección. En el ROUTER CLIENTE (por Winbox desde su LAN) verifica en este orden:\n"
                . "1) CAUSA MÁS FRECUENTE — el túnel usa un perfil PPP con local-address. Mira "
                . "/interface l2tp-client print detail: si el campo profile dice 'default' (o cualquier perfil de tus "
                . "planes PPPoE), ese perfil le impone su propia dirección al túnel y el router ignora la del overlay. "
                . "Arreglo: /ppp profile add name=ISPWatch-VPN change-tcp-mss=yes y luego "
                . "/interface l2tp-client set [find name=ISPWatch-VPN-CORE] profile=ISPWatch-VPN. "
                . "Volver a generar y aplicar el script VPN de ISPWatch ya lo deja así.\n"
                . "2) /ip address print — tiene que aparecer una dirección dinámica {$clientIp}/32 sobre esa interfaz. "
                . "Si no está, /interface l2tp-client disable y enable sobre ella la vuelve a negociar.\n"
                . "3) Que NO haya otro equipo discando con las mismas credenciales VPN: la sesión se la queda el último "
                . "que entra y el router bueno se queda afuera con el túnel a medias.\n"
                . "4) Comprobación desde el CORE cuando lo corrijan: /ping {$clientIp} count=2 ttl=1 — debe contestar "
                . "{$clientIp}, no {$hop}.",
        ];
    }
}
