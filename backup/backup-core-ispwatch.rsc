# ============================================
# RouterOS 7.22.x - CORE-ISPWATCH (CHR @ DigitalOcean)
# Hub central / concentrador VPN L2TP de ISPWatch
# Host de gestion (publico): 167.172.132.234
# ============================================
# ALCANCE: este archivo es la REFERENCIA DE SEGURIDAD versionada
# (firewall, NAT, address-lists, servicios). Refleja exactamente el
# ruleset corregido y probado el 2026-05-18.
#
# NO incluye:
#  - PPP profiles / secrets / pools por-tenant  -> los crea Laravel
#    dinamicamente (VpnService / PppSecretManager). NO agregar a mano.
#  - Secretos reales (ipsec-secret, passwords)  -> placeholders <...>.
#    Los valores vivos van SOLO en .env / en el equipo, nunca en git.
#  - LAN/bridge/DHCP: el CHR de DO solo tiene ether1 + l2tp dinamicas.
# ============================================

############################
# SYSTEM IDENTITY
############################
/system identity
set name="CORE-ISPWATCH"

############################
# SERVICIOS DE GESTION
# El control de origen lo hace el firewall (no se restringe por
# /ip service address). DO bloquea el 22 saliente -> ver dstnat 2222.
############################
/ip service
set ssh   disabled=no port=22
set winbox disabled=no port=8291
set api   disabled=no port=8728
set api-ssl disabled=yes
set www   disabled=yes
set www-ssl disabled=yes
set ftp   disabled=yes
set telnet disabled=yes

############################
# L2TP + IPSEC (VPN SERVER)
# El ipsec-secret REAL va en .env (MIKROTIK_IPSEC_SECRET). Aqui placeholder.
############################
/interface l2tp-server server
set enabled=yes use-ipsec=yes ipsec-secret="<MIKROTIK_IPSEC_SECRET>" \
    authentication=mschap2

############################
# FIREWALL ADDRESS-LISTS
############################
/ip firewall address-list
# --- Gestion autorizada (SSH 22 / Winbox 8291 / ICMP / API 8728) ---
# IPs de egreso de la app (DO App Platform: pueden rotar; mantener al dia).
add list=ALLOWED_MGMT address=190.14.255.110  comment="App - IP Autorizada 1"
add list=ALLOWED_MGMT address=129.212.176.143 comment="App - IP Autorizada 2"
add list=ALLOWED_MGMT address=134.199.204.61  comment="App - IP Autorizada 3"
add list=ALLOWED_MGMT address=181.225.70.27   comment="App - IP Autorizada 4"
# add list=ALLOWED_MGMT address=<IP_ADMIN>     comment="Admin PC"
# --- Lista legacy de API (la regla API acepta ALLOWED_MGMT ademas) ---
add list=ISPWATCH_ALLOWED_API address=190.14.255.110  comment="App - API 1"
add list=ISPWATCH_ALLOWED_API address=129.212.176.143 comment="App - API 2"
add list=ISPWATCH_ALLOWED_API address=134.199.204.61  comment="App - API 3"
add list=ISPWATCH_ALLOWED_API address=10.10.10.0/24   comment="VPN Clientes"
# --- Bypass de pools VPN por-tenant (auto-poblado por Laravel) ---
# Laravel agrega el /24 de cada tenant via ensureCoreAddressListEntry.
# El /12 cubre todos los /24 que genera la formula aunque el add falle.
add list=ISPWATCH_VPN_POOLS address=10.10.10.0/24    comment="VPN clientes legacy"
add list=ISPWATCH_VPN_POOLS address=172.16.0.0/12    comment="Tenant VPN pools (formula)"
add list=ISPWATCH_VPN_POOLS address=172.123.155.0/24 comment="VEN_CORE_VEGA legacy"
# --- Control ISPWatch (suspendidos) ---
add list=ISPWATCH_SUSPENDIDOS address=0.0.0.0 comment="Control ISPWatch - vacio inicial"
# BLACKLIST: dinamica (la pueblan las reglas [AUTO]); no se versiona.

############################
# FIREWALL FILTER  (orden CRITICO)
# Regla clave: los accept de gestion van ANTES del bloque blacklist,
# asi una IP de ALLOWED_MGMT NUNCA se auto-bloquea aunque rote.
############################
/ip firewall filter
add chain=input action=accept connection-state=established,related comment="Accept established/related"
add chain=input action=drop   connection-state=invalid comment="Drop invalid"
add chain=input action=accept in-interface=lo comment="Accept loopback"
add chain=input action=accept src-address-list=ISPWATCH_VPN_POOLS comment="VPN clientes (bypass total)"
add chain=input action=accept protocol=icmp src-address-list=ALLOWED_MGMT comment="ICMP autorizado"
add chain=input action=accept protocol=tcp dst-port=22   src-address-list=ALLOWED_MGMT comment="SSH autorizado (cubre 2222->22)"
add chain=input action=accept protocol=tcp dst-port=8291 src-address-list=ALLOWED_MGMT comment="Winbox autorizado"
add chain=input action=accept protocol=tcp dst-port=8728 src-address-list=ALLOWED_MGMT comment="API (ALLOWED_MGMT)"
add chain=input action=accept protocol=tcp dst-port=8728 src-address-list=ISPWATCH_ALLOWED_API comment="API (lista legacy)"
add chain=input action=accept protocol=udp dst-port=500,1701,4500 comment="L2TP/IPsec routers cliente"
add chain=input action=accept protocol=ipsec-esp comment="IPsec ESP"
add chain=input action=add-src-to-address-list address-list=BLACKLIST address-list-timeout=4w2d protocol=tcp dst-port=23   in-interface=ether1 comment="[AUTO] Blacklist Telnet"
add chain=input action=add-src-to-address-list address-list=BLACKLIST address-list-timeout=4w2d protocol=tcp dst-port=22   in-interface=ether1 src-address-list=!ALLOWED_MGMT comment="[AUTO] Blacklist SSH no autorizado"
add chain=input action=add-src-to-address-list address-list=BLACKLIST address-list-timeout=4w2d protocol=tcp dst-port=8291 in-interface=ether1 src-address-list=!ALLOWED_MGMT comment="[AUTO] Blacklist Winbox no autorizado"
add chain=input action=drop src-address-list=BLACKLIST comment="Drop Blacklist"
add chain=input action=log  in-interface=ether1 log-prefix="[BLOQUEADO]" comment="Log intentos WAN"
add chain=input action=drop in-interface=ether1 comment="DROP todo desde WAN"
add chain=forward action=drop   connection-state=invalid comment="Drop forward invalidos"
add chain=forward action=accept connection-state=established,related comment="Accept forward establecidos"
add chain=forward action=accept src-address=10.10.10.0/24 comment="VPN forward"

############################
# NAT
# El redirect 2222->22 es el workaround de DO (bloquea el 22 saliente):
# la app de produccion conecta al CORE por :2222 (MIKROTIK_CORE_SSH_PORT=2222).
############################
/ip firewall nat
add chain=srcnat action=masquerade out-interface=ether1 comment="NAT Internet"
add chain=srcnat action=masquerade src-address=10.10.10.0/24 dst-address=192.168.88.252 comment="VPN to Portal ISPWatch"
add chain=dstnat action=redirect protocol=tcp dst-port=2222 to-ports=22 comment="SSH alt for DO App Platform (DO blocks outbound :22)"
