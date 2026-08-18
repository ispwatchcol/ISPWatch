<?php

namespace App\Services;

use App\Models\Router;
use App\Services\MikroTik\SshTunnelManager;
use App\Services\MikroTik\WireguardManager;
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
        // SECURITY FIX (OWASP A02): No hardcoded credential fallbacks in source code.
        // All secrets MUST come from .env — fail loudly if not configured.
        $this->vpnPublicIp = env('MIKROTIK_CORE_VPN_IP', '');
        // IP para conexión API desde Laravel al CORE
        $this->apiHost = env('MIKROTIK_CORE_API_HOST', '');
        $this->apiPort = (int) env('MIKROTIK_CORE_API_PORT', 8728);
        $this->apiUser = env('MIKROTIK_CORE_API_USER', 'admin');
        $this->apiPass = env('MIKROTIK_CORE_API_PASS', '');
        // IPsec Secret fijo para todos los clientes L2TP
        $this->ipsecSecret = env('MIKROTIK_IPSEC_SECRET', '');
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
    // PERFIL VPN POR TENANT
    // ==============================

    private function getTenantSubnet(int $tenantId): array
    {
        // Asigna un /24 único por tenant dentro del rango 172.16.0.0/12
        // Tenant 1 → 172.16.1.0/24, Tenant 255 → 172.16.255.0/24, Tenant 256 → 172.17.1.0/24 ...
        $third  = (($tenantId - 1) % 254) + 1;
        $second = 16 + intdiv($tenantId - 1, 254);
        return [
            'local_address' => "172.{$second}.{$third}.1",
            'pool_range'    => "172.{$second}.{$third}.2-172.{$second}.{$third}.254",
            'network_cidr'  => "172.{$second}.{$third}.0/24",
        ];
    }

    private function getProfileName(int $tenantId): string
    {
        return "vpn-isp-{$tenantId}";
    }

    private function getPoolName(int $tenantId): string
    {
        return "pool-vpn-{$tenantId}";
    }

    private function ensureTenantVpnResources(int $tenantId): bool
    {
        $subnet      = $this->getTenantSubnet($tenantId);
        $profileName = $this->getProfileName($tenantId);
        $poolName    = $this->getPoolName($tenantId);

        $sshService = new MikroTikSshService();

        $poolResult = $sshService->ensureIpPool($poolName, $subnet['pool_range']);
        if (!$poolResult['success']) {
            Log::warning('[VPN] No se pudo asegurar el pool IP del tenant', [
                'tenantId' => $tenantId,
                'error'    => $poolResult['message'] ?? '',
            ]);
            return false;
        }

        $profileResult = $sshService->ensurePppProfile($profileName, $subnet['local_address'], $poolName);
        if (!$profileResult['success']) {
            Log::warning('[VPN] No se pudo asegurar el perfil PPP del tenant', [
                'tenantId' => $tenantId,
                'error'    => $profileResult['message'] ?? '',
            ]);
            return false;
        }

        // Auto-add this tenant's /24 to the CORE firewall bypass list so its
        // client routers' traffic never hits the input-chain blacklist/drop.
        // Idempotent and non-blocking: a failure here must not stop script
        // generation (the broad seed entry 172.16.0.0/12 still covers it).
        $bypassResult = $sshService->ensureCoreAddressListEntry(
            'ISPWATCH_VPN_POOLS',
            $subnet['network_cidr'],
            "VPN pool tenant {$tenantId}"
        );
        if (!($bypassResult['success'] ?? false)) {
            Log::warning('[VPN] No se pudo agregar el pool del tenant al bypass del firewall', [
                'tenantId' => $tenantId,
                'cidr'     => $subnet['network_cidr'],
                'error'    => $bypassResult['message'] ?? '',
            ]);
        }

        Log::info('[VPN] Recursos VPN de tenant listos', [
            'tenantId'      => $tenantId,
            'profile'       => $profileName,
            'pool'          => $poolName,
            'localAddress'  => $subnet['local_address'],
            'poolRange'     => $subnet['pool_range'],
            'networkCidr'   => $subnet['network_cidr'],
            'poolAction'    => $poolResult['action'] ?? 'unknown',
            'profileAction' => $profileResult['action'] ?? 'unknown',
            'bypassAction'  => $bypassResult['action'] ?? ($bypassResult['success'] ?? false ? 'ok' : 'failed'),
        ]);

        return true;
    }

    // ==============================
    // SCRIPT CLIENTE L2TP
    // ==============================
    public function getServerPublicIp(): string
    {
        return $this->vpnPublicIp;
    }

    /**
     * Script de provisión del router, según su transporte.
     *
     * WireGuard existe desde RouterOS 7.1 y en v6 no lo hay, así que esto NO es
     * una migración global sino una decisión por equipo. Un router marcado como
     * wireguard cuyo firmware no lo soporte cae a L2TP: preferimos el transporte
     * que funciona en las dos ramas antes que emitir un script que su RouterOS
     * va a rechazar entero.
     */
    public function generateScript(Router $router): string
    {
        if ($router->usesWireguard()) {
            if (Router::firmwareSupportsWireguard($router->firmware_version)) {
                return $this->generateWireguardScript($router);
            }

            Log::warning('[VPN] Router marcado como WireGuard con firmware que no lo soporta; se emite L2TP', [
                'router_id' => $router->id,
                'firmware'  => $router->firmware_version,
            ]);
        }

        return $this->generateL2tpScript($router);
    }

    /**
     * Script WireGuard. El par de claves lo acuña ISPWatch y el peer queda
     * registrado en el CORE antes de devolver nada, así que el script es
     * autosuficiente: sirve para un router recién sacado de la caja, sin túnel
     * previo por el que negociar.
     */
    public function generateWireguardScript(Router $router): string
    {
        $wg       = new WireguardManager();
        $tenantId = (int) $router->tenant_id;

        $core = $wg->ensureCoreInterface();
        if (!($core['success'] ?? false)) {
            throw new \RuntimeException(
                'No se pudo preparar la interfaz WireGuard del CORE: ' . ($core['message'] ?? 'error desconocido')
            );
        }

        if ($tenantId > 0) {
            $wg->ensureTenantAddress($tenantId);
        }

        $overlayIp = $wg->allocateOverlayIp($router);
        if (!$overlayIp) {
            throw new \RuntimeException('No hay direcciones libres en el overlay WireGuard del tenant');
        }

        // Reusar las claves ya emitidas mantiene válido el peer del CORE si el
        // operador vuelve a pedir el script; solo se acuñan nuevas la primera vez.
        $privateKey = $router->wg_private_key;
        $publicKey  = $router->wg_public_key;
        if (empty($privateKey) || empty($publicKey)) {
            $pair       = $wg->generateKeypair();
            $privateKey = $pair['private'];
            $publicKey  = $pair['public'];
        }

        $peer = $wg->upsertPeer($router, $publicKey, $overlayIp);
        if (!($peer['success'] ?? false)) {
            throw new \RuntimeException(
                'No se pudo registrar el peer en el CORE: ' . ($peer['message'] ?? 'error desconocido')
            );
        }

        $router->update([
            'vpn_transport'   => Router::TRANSPORT_WIREGUARD,
            'wg_private_key'  => $privateKey,
            'wg_public_key'   => $publicKey,
            'wg_address'      => $overlayIp,
            'ip'              => $overlayIp,
        ]);

        $localUser = 'ispwatch';
        $localPass = $this->ensureManagementCredentials($router, $localUser);

        $subnet   = $wg->tenantSubnet($tenantId > 0 ? $tenantId : 1);
        $mgmtNet  = $subnet['network_cidr'];
        $coreOv   = $subnet['local_address'];
        $corePub  = $core['public_key'];
        $corePort = $core['listen_port'];
        $iface    = WireguardManager::IFACE;

        return <<<SCRIPT
# ====================================
# USUARIO DE GESTIÓN
# ====================================
/user remove [find name="{$localUser}"]
/user add name="{$localUser}" password="{$localPass}" group=full
/ip service set api disabled=no port=8728
/ip service set ssh disabled=no port=22

# ====================================
# TÚNEL WIREGUARD HACIA EL CORE
# ====================================
# El listen-port NO se puede fijar a ciegas: 13231 es el default de RouterOS y
# lo ocupa el Back To Home VPN de MikroTik. Si choca, la interfaz queda
# deshabilitada con "Listen port already used". Como el cliente es quien disca,
# el puerto local da igual — el único que debe coincidir es el endpoint-port,
# que es el del CORE. Por eso se busca uno libre.
/interface/wireguard remove [find name="{$iface}"]
:local wgport {$corePort}
:while ([:len [/interface/wireguard find listen-port=\$wgport]] > 0) do={ :set wgport (\$wgport + 1) }
/interface/wireguard add name="{$iface}" private-key="{$privateKey}" listen-port=\$wgport comment="ISPWatch WireGuard"

/ip/address remove [find comment="ISPWatch WG overlay"]
/ip/address add address={$overlayIp}/24 interface="{$iface}" comment="ISPWatch WG overlay"

# persistent-keepalive mantiene vivo el mapeo NAT del lado del cliente.
/interface/wireguard/peers remove [find comment="ISPWatch CORE"]
/interface/wireguard/peers add interface="{$iface}" \\
    public-key="{$corePub}" \\
    endpoint-address={$this->vpnPublicIp} endpoint-port={$corePort} \\
    allowed-address={$mgmtNet} \\
    persistent-keepalive=25s comment="ISPWatch CORE"

# ====================================
# ACCESO DE GESTIÓN DESDE EL CORE
# ====================================
/ip firewall filter remove [find comment="ISPWatch-CORE-MGMT"]
/ip firewall filter add chain=input action=accept protocol=tcp src-address={$mgmtNet} dst-port=22,8291,8728 comment="ISPWatch-CORE-MGMT" place-before=0

# ====================================
# WATCHDOG
# ====================================
# WireGuard no "se cae" como L2TP (no hay sesión que renegociar), pero si el
# handshake muere por un cambio de ruteo, recrear la interfaz lo fuerza.
/tool netwatch remove [find comment="ISPWatch-VPN-Watchdog"]
/tool netwatch add host={$coreOv} interval=60s timeout=5s comment="ISPWatch-VPN-Watchdog" \\
    up-script=":log info \\"ISPWatch: VPN UP - CORE {$coreOv} alcanzable\\"" \\
    down-script="/interface/wireguard disable [find name={$iface}]; :delay 3s; /interface/wireguard enable [find name={$iface}]; :log warning \\"ISPWatch: VPN DOWN - reiniciando WireGuard (watchdog)\\""
SCRIPT;
    }

    /**
     * Credencial de gestión local. Devuelve la vigente o acuña una nueva si no
     * hay, o si sigue el default histórico que se filtró en instalaciones viejas.
     */
    private function ensureManagementCredentials(Router $router, string $localUser): string
    {
        if (empty($router->password_rb) || $router->password_rb === 'Sena2017') {
            $localPass = \Illuminate\Support\Str::random(24);
            $router->update(['user_rb' => $localUser, 'password_rb' => $localPass]);

            return $localPass;
        }

        if ($router->user_rb !== $localUser) {
            $router->update(['user_rb' => $localUser]);
        }

        return $router->password_rb;
    }

    public function generateL2tpScript(Router $router): string
    {
        $routerName = $this->sanitizeName($router->name);

        // Generar o reutilizar username VPN único (aleatorio)
        $vpnUsername = $router->vpn_username;

        // Si no existe O si tiene el formato antiguo (core-isp-...), generar uno nuevo aleatorio
        if (empty($vpnUsername) || str_starts_with($vpnUsername, 'core-isp-')) {
            // Generar usuario aleatorio de 10 caracteres (ej: aB3xY9z123)
            $vpnUsername = \Illuminate\Support\Str::random(10);
        }

        // Generar o reutilizar contraseña VPN segura y única
        $vpnPassword = $router->vpn_password;
        if (empty($vpnPassword)) {
            // Generar contraseña segura de 20 caracteres alfanuméricos
            $vpnPassword = \Illuminate\Support\Str::random(20);
        }

        // Guardar credenciales VPN (el cast del modelo las cifra al escribir).
        // Las columnas *_encrypted que aquí se seteaban ya no existen: las
        // eliminó la migración 2026_07_31_000002, que pasó a cifrar EN LA MISMA
        // columna. Se quitaron de este update porque no estaban en $fillable y
        // el mass-assignment las descartaba en silencio — código muerto que
        // aparentaba estar haciendo algo.
        $router->update([
            'vpn_username' => $vpnUsername,
            'vpn_password' => $vpnPassword,
        ]);

        // Generar credenciales de gestión local (INTERNO - no mostrar al usuario)
        $localUser = 'ispwatch';
        $localPass = $this->ensureManagementCredentials($router, $localUser);

        // ==============================
        // SINCRONIZAR CON EL CORE (Importantísimo)
        // ==============================
        // Determinar perfil VPN específico del tenant (o fallback a profile-vpn)
        $vpnProfile = 'profile-vpn';
        $tenantId   = $router->tenant_id;
        // Red del túnel desde la que el CORE alcanza a ESTE router (su /24 de
        // tenant; la IP del CORE en el túnel es la .1 de ese /24). Se usa para
        // permitir gestión en el firewall del cliente. Fallback al supernet
        // 172.16.0.0/12 (cubre todos los /24 que genera la fórmula de tenants).
        $mgmtNet = '172.16.0.0/12';
        $coreTunnelIp = '';
        if ($tenantId) {
            $this->ensureTenantVpnResources((int) $tenantId);
            $vpnProfile   = $this->getProfileName((int) $tenantId);
            $subnet       = $this->getTenantSubnet((int) $tenantId);
            $mgmtNet      = $subnet['network_cidr'];
            $coreTunnelIp = $subnet['local_address'];
        }

        // Intentar crear/actualizar el secret en el CORE vía API
        // Si falla, lo logueamos pero no bloqueamos la generación del script
        try {
            $syncResult = $this->syncPppSecret($vpnUsername, $vpnPassword, $routerName, $vpnProfile);
            if (!$syncResult) {
                Log::warning('[VPN] Falló la sincronización del secret en el CORE', ['user' => $vpnUsername]);
            } else {
                Log::info('[VPN] Secret sincronizado correctamente', ['user' => $vpnUsername, 'profile' => $vpnProfile]);
            }
        } catch (\Throwable $e) {
            Log::error('[VPN] Excepción al sincronizar secret', ['error' => $e->getMessage()]);
        }


        // ==============================
        // BLOQUE OPCIONAL: ruta de gestión + watchdog netwatch
        // ==============================
        // Solo se aplica cuando hay tenantId, porque el fallback /12 es
        // demasiado amplio para enrutar todo por el túnel sin riesgo.
        $tenantBlock = '';
        if ($coreTunnelIp) {
            $tenantBlock = <<<TENANT


# ====================================
# RUTA DE GESTIÓN POR EL TÚNEL
# ====================================
# Asegura que el tráfico hacia la red de gestión del CORE ({$mgmtNet})
# viaje por el túnel L2TP. Idempotente por comentario.
/ip route remove [find comment="ISPWatch-VPN-MGMT-Route"]
/ip route add dst-address={$mgmtNet} gateway=ISPWatch-VPN-CORE comment="ISPWatch-VPN-MGMT-Route" disabled=no

# ====================================
# WATCHDOG DE SALUD VPN (Netwatch)
# ====================================
# Ping al CORE ({$coreTunnelIp}) cada 60s por el túnel. Si no responde,
# reinicia automáticamente el L2TP. También registra eventos UP/DOWN en
# /log para diagnóstico. Idempotente por comentario.
/tool netwatch remove [find comment="ISPWatch-VPN-Watchdog"]
/tool netwatch add host={$coreTunnelIp} interval=60s timeout=5s comment="ISPWatch-VPN-Watchdog" up-script=":log info \"ISPWatch: VPN UP - CORE {$coreTunnelIp} alcanzable\"" down-script=":local d [/interface l2tp-client get [find name=ISPWatch-VPN-CORE] disabled]; :if (\$d = false) do={/interface l2tp-client disable [find name=ISPWatch-VPN-CORE]; :delay 3s; /interface l2tp-client enable [find name=ISPWatch-VPN-CORE]; :log warning \"ISPWatch: VPN DOWN - reiniciando L2TP (watchdog)\"}"
TENANT;
        }

        // Script con configuración completa: usuario de gestión + VPN
        return <<<SCRIPT
# ====================================
# CONFIGURACIÓN USUARIO DE GESTIÓN
# ====================================
# Crear usuario para acceso remoto de ISPWatch
/user remove [find name="{$localUser}"]
/user add name="{$localUser}" password="{$localPass}" group=full

# Habilitar servicios para gestión remota
/ip service set api disabled=no port=8728
/ip service set ssh disabled=no port=22

# ====================================
# ACCESO DE GESTIÓN DESDE EL CORE (TÚNEL VPN)
# ====================================
# Permite que ISPWatch llegue por el túnel (SSH/API/Winbox) sin tener que
# buscar a mano entre cientos de reglas del firewall del router.
# Idempotente: se identifica por comentario y se reinserta al TOPE del
# chain input (antes de cualquier blacklist/drop), así re-aplicar el
# script no duplica la regla ni requiere inspeccionar las reglas existentes.
/ip firewall filter remove [find comment="ISPWatch-CORE-MGMT"]
/ip firewall filter add chain=input action=accept protocol=tcp src-address={$mgmtNet} dst-port=22,8291,8728 comment="ISPWatch-CORE-MGMT" place-before=0

# ====================================
# BLINDAJE DEL TÚNEL CONTRA MULTI-WAN
# ====================================
# ESTO NO ES OPCIONAL. L2TP/IPsec parte el túnel en dos flujos: el IKE por
# udp/500 y los datos por udp/1701. Si el router tiene dos salidas, balanceo,
# policy routing o un src-nat que reescribe el origen, cada flujo puede salir
# por una IP pública distinta. El CORE levanta la SA contra la primera; el
# paquete L2TP llega de la segunda sin política que lo cubra y, con
# use-ipsec=required, lo rechaza: "no IPsec encryption while it was required".
# Así estuvo CORE_TOCAIMA 8 días caído en julio de 2026, reintentando cada 12s.
#
# Las dos reglas siguientes fuerzan a que ambos flujos salgan igual:
#   - mangle output: que ningún marcado de balanceo lo desvíe a otra tabla.
#   - srcnat: que nadie le reescriba el origen (si se lo reescriben, deja de
#     matchear la política IPsec y sale en claro).
# Van acotadas a la IP del CORE, así que no tocan el tráfico de los clientes.
#
# Los routers v7 no necesitan nada de esto porque van por WireGuard, donde la
# clase de falla no existe. Para los v6 es la única defensa disponible.
/ip firewall mangle remove [find comment="ISPWatch-CORE-no-mark"]
/ip firewall mangle add chain=output action=accept dst-address={$this->vpnPublicIp} comment="ISPWatch-CORE-no-mark" place-before=0

/ip firewall nat remove [find comment="ISPWatch-CORE-no-nat"]
/ip firewall nat add chain=srcnat action=accept dst-address={$this->vpnPublicIp} comment="ISPWatch-CORE-no-nat" place-before=0

# Si además hay ECMP (varios gateways en la misma default), el accept de mangle
# no alcanza: el hash por conexión todavía puede separar el 500 del 1701. La
# ruta /32 al CORE por el gateway activo lo fija. Se resuelve el gateway en
# caliente para no depender de conocer la topología del cliente.
/ip route remove [find comment="ISPWatch-CORE-pin"]
:local gw ""
:foreach r in=[/ip route find dst-address="0.0.0.0/0"] do={
    :if ([:len \$gw] = 0 && [/ip route get \$r active]) do={ :set gw [/ip route get \$r gateway] }
}
:if ([:len \$gw] > 0) do={
    /ip route add dst-address={$this->vpnPublicIp}/32 gateway=\$gw distance=1 comment="ISPWatch-CORE-pin"
}

# ====================================
# CONFIGURACIÓN VPN L2TP
# ====================================
# Crear interfaz Cliente L2TP
/interface l2tp-client remove [find name="ISPWatch-VPN-CORE"]

# PERFIL PROPIO — NO USAR "default". ESTO NO ES COSMÉTICO.
#
# En un router que además es servidor PPPoE (o sea, casi todos los CORE de
# cliente) el perfil "default" trae local-address puesta: la IP con la que
# atiende a SUS abonados. Un l2tp-client que use ese perfil se queda con ESA
# dirección en la interfaz del túnel e ignora la que el CORE le asigna por
# IPCP. El túnel entonces figura conectado en las dos puntas —hay sesión en
# /ppp active y los contadores suben— pero el router no reconoce como propia
# la IP del overlay: reenvía a su gateway todo lo que le mandamos y ISPWatch
# pierde el equipo entero (API, SSH, colas, cortes) sin un solo error visible.
#
# Medido en CORE_SAN_ISIDRO el 2026-08-18: "default" tenía
# local-address=10.72.103.1 (su pool PPPoE) y la interfaz del túnel quedó con
# 10.72.103.1 en vez de 172.16.16.253. Ver § 40 de BITACORA_TECNICA.
#
# WireGuard nunca tuvo este problema porque su script FIJA la dirección con
# /ip address add; L2TP la negocia, y ahí es donde el perfil puede pisarla.
#
# Se recrea en cada aplicación para garantizar que no arrastre local-address
# de una versión anterior. Va después del remove del l2tp-client: mientras la
# interfaz exista, el perfil está en uso y no se puede borrar.
/ppp profile remove [find name="ISPWatch-VPN"]
/ppp profile add name="ISPWatch-VPN" change-tcp-mss=yes

/interface l2tp-client
add name="ISPWatch-VPN-CORE" \\
    connect-to="{$this->vpnPublicIp}" \\
    user="{$vpnUsername}" \\
    password="{$vpnPassword}" \\
    use-ipsec=yes \\
    ipsec-secret="{$this->ipsecSecret}" \\
    profile=ISPWatch-VPN \\
    add-default-route=no \\
    disabled=no

# Asegurar que la interfaz inicie habilitada
/interface l2tp-client enable [find name="ISPWatch-VPN-CORE"]

# ====================================
# AUTO-REINICIO PROGRAMADO DE LA VPN
# ====================================
# Reinicia el túnel L2TP cada 6 horas (a las 04:00, 10:00, 16:00, 22:00)
# para evitar conexiones colgadas. Idempotente por comentario: re-aplicar
# el script no duplica el scheduler.
/system scheduler remove [find comment="ISPWatch-VPN-Health"]
/system scheduler add name="ISPWatch-VPN-Health" comment="ISPWatch-VPN-Health" interval=6h start-time=04:00:00 policy=read,write on-event="/interface l2tp-client disable [find name=ISPWatch-VPN-CORE]; :delay 5s; /interface l2tp-client enable [find name=ISPWatch-VPN-CORE]; :log info \"ISPWatch: VPN reiniciada por scheduler\""{$tenantBlock}
SCRIPT;
    }

    // ==============================
    // VERIFICAR ESTADO REAL DE VPN
    // ==============================
    // ==============================
    // VERIFICAR ESTADO REAL DE VPN
    // ==============================
    public function verifyConnection(Router $router): array
    {
        // Usar el usuario VPN guardado en BD, o generar el legacy si no existe
        $vpnUsername = $router->vpn_username;
        if (empty($vpnUsername)) {
            $vpnUsername = $this->getVpnUsername($router);
        }

        Log::debug('[VPN] Verificando conexión VPN (SSH)', [
            'router_id' => $router->id,
            'vpn_username' => $vpnUsername,
        ]);

        try {
            $sshService = new MikroTikSshService();
            $result = $sshService->isVpnConnected($vpnUsername);

            if ($result['connected']) {
                $assignedIp = $result['assigned_ip'] ?? null;

                if ($assignedIp) {
                    $router->update([
                        'ip' => $assignedIp,
                    ]);

                    Log::info('[VPN] Router actualizado con datos VPN', [
                        'router_id' => $router->id,
                        'vpn_remote_ip' => $assignedIp,
                    ]);
                }

                $duplicates = $result['duplicate_tunnels'] ?? [];

                if (!empty($duplicates)) {
                    $others = implode(', ', array_map(
                        fn ($t) => "{$t['name']} ({$t['address']})",
                        $duplicates
                    ));

                    Log::warning('[VPN] Túnel duplicado desde la misma IP pública', [
                        'router_id' => $router->id,
                        'router'    => $router->name,
                        'caller_id' => $result['caller_id'] ?? null,
                        'others'    => array_column($duplicates, 'name'),
                    ]);
                }

                return [
                    'success' => true,
                    'connected' => true,
                    // "Conectada" a secas era engañoso con dos túneles peleándose:
                    // el estado momentáneo es correcto y la gestión igual no funciona.
                    'message' => empty($duplicates)
                        ? '✅ VPN ACTIVA (PPP activo en CORE)'
                        : "⚠️ VPN ACTIVA pero HAY OTRO TÚNEL desde la misma IP pública ({$result['caller_id']}): {$others}. " .
                          'Los dos se reciclan entre sí y la gestión desde el CORE fallará de forma intermitente. ' .
                          'Deja uno solo discando desde esa pública.',
                    'assigned_ip' => $assignedIp,
                    'uptime' => $result['uptime'] ?? null,
                    'caller_id' => $result['caller_id'] ?? null,
                    'duplicate_tunnels' => $duplicates,
                    // Credenciales de gestión del RB (columnas legacy = fuente de verdad,
                    // las que escribe el formulario; *_encrypted no se mantiene — ver 4f24551)
                    'user_rb' => $router->user_rb,
                    'password_rb' => $router->password_rb,
                ];
            } else {
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

        } catch (\Throwable $e) {
            Log::error('[VPN] Excepción en verifyConnection (SSH)', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->error('Error inesperado al consultar la API Mikrotik (SSH): ' . $e->getMessage());
        }
    }

    // ==============================
    // SYNC CREDENTIALS TO CORE
    // ==============================
    // protected, no private: la prueba del script (VpnScriptTest) la sustituye
    // para generar el texto sin abrir SSH contra el CORE de producción.
    protected function syncPppSecret(string $username, string $password, string $routerName = '', string $profile = 'profile-vpn'): bool
    {
        try {
            Log::info("[VPN] Sincronizando secret con el CORE", [
                'user'            => $username,
                'profile'         => $profile,
                // ⚠️ NUNCA loguear password_length o datos sensibles
            ]);

            $comment = $routerName
                ? "ISPWatch - {$routerName}"
                : 'ISPWatch Auto';

            $sshService = new MikroTikSshService();
            $result = $sshService->ensurePppSecret($username, $password, 'l2tp', $profile, $comment);

            Log::info('[VPN] Resultado de sincronización de secret', [
                'success' => $result['success'],
                'method' => $result['method'] ?? 'unknown',
                'action' => $result['action'] ?? 'unknown',
                'message' => $result['message'] ?? 'no message',
                'verified' => $result['verified'] ?? false,
            ]);

            if ($result['success']) {
                Log::info('[VPN] ✅ Secret sincronizado exitosamente', [
                    'user' => $username,
                    'action' => $result['action'] ?? 'unknown',
                    'method' => $result['method'] ?? 'unknown',
                ]);
                return true;
            } else {
                Log::error('[VPN] ❌ Falló syncPppSecret', [
                    'user' => $username,
                    'message' => $result['message'] ?? 'unknown error',
                    'full_result' => $result,
                ]);
                return false;
            }
        } catch (\Throwable $e) {
            Log::error('[VPN] Excepción al sincronizar secret', [
                'user' => $username,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
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

        // Client router IPs (172.16.x.x, 192.168.x.x) only route inside the L2TP
        // overlay, so we go through the CORE via SSH local-forward.
        $tunnelManager = new SshTunnelManager();
        try {
            $tunnel = $tunnelManager->open($router->ip, 8728);
        } catch (\Throwable $e) {
            Log::error('[INTERFACES] No se pudo abrir túnel SSH al router', [
                'router_id' => $router->id,
                'ip' => $router->ip,
                'error' => $e->getMessage(),
            ]);
            return $this->error("No se pudo abrir túnel al router en {$router->ip}:8728: " . $e->getMessage());
        }

        $errno = 0; $errstr = '';
        $socket = @fsockopen($tunnel->localHost(), $tunnel->localPort(), $errno, $errstr, 10);

        if (!$socket) {
            Log::error('[INTERFACES] No se pudo conectar al router via túnel', [
                'errno' => $errno,
                'errstr' => $errstr,
                'ip' => $router->ip,
                'tunnel_port' => $tunnel->localPort(),
            ]);
            $tunnel->close();
            return $this->error("No se pudo conectar al router en {$router->ip}:8728: {$errstr}");
        }

        stream_set_timeout($socket, 15);

        try {
            // LOGIN con las credenciales del router (encriptadas)
            $loginSuccess = $this->doLoginToClient($socket, $router->user_rb, $router->password_rb);

            if (!$loginSuccess) {
                @fclose($socket);
                $tunnel->close();
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

            @fclose($socket);
            $tunnel->close();

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
            $tunnel->close();
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
