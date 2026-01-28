# ============================================
# RouterOS 6.40.x - PLANTILLA para Routers Clientes
# Template de Router Cliente para ISPWatch
# 
# VARIABLES DE PLANTILLA (reemplazar al generar):
# {{ROUTER_NAME}}    - Nombre del router cliente
# {{VPN_USERNAME}}   - Usuario VPN unico para este router
# {{VPN_PASSWORD}}   - Contrasena VPN unica para este router
# {{VPN_SERVER_IP}}  - IP publica del servidor VPN (138.197.30.155)
# {{IPSEC_SECRET}}   - Secreto IPSec (Q9fZ7MrL2xSA8DkEpHwCy)
# {{LARAVEL_IP}}     - IP del servidor Laravel (162.159.140.98)
# ============================================

############################
# INTERFACES (BRIDGE EN VEZ DE MASTER-PORT)
############################
/interface bridge
add name=bridge-lan protocol-mode=none comment="LAN Bridge"

/interface bridge port
add bridge=bridge-lan interface=ether2
add bridge=bridge-lan interface=ether3
add bridge=bridge-lan interface=ether4
add bridge=bridge-lan interface=ether5

############################
# LISTAS DE INTERFACES
############################
/interface list
add name=WAN comment=defconf
add name=LAN comment=defconf

/interface list member
add interface=ether1 list=WAN
add interface=bridge-lan list=LAN

############################
# IP ADDRESS (REQUERIDO)
############################
/ip address
add address=192.168.88.1/24 interface=bridge-lan network=192.168.88.0 comment="LAN Gateway"

############################
# POOLS
############################
/ip pool
add name=default-dhcp ranges=192.168.88.10-192.168.88.254

############################
# DHCP SERVER
############################
/ip dhcp-server
add name=defconf interface=bridge-lan address-pool=default-dhcp disabled=no

/ip dhcp-server network
add address=192.168.88.0/24 gateway=192.168.88.1 dns-server=192.168.88.1

############################
# DNS
############################
/ip dns
set servers=8.8.8.8,1.1.1.1 allow-remote-requests=yes

/ip dns static
add name=router.lan address=192.168.88.1

############################
# L2TP CLIENT - CONEXION AL VPN SERVER
############################
/interface l2tp-client
add name=l2tp-ispwatch connect-to={{VPN_SERVER_IP}} user={{VPN_USERNAME}} password={{VPN_PASSWORD}} use-ipsec=yes ipsec-secret={{IPSEC_SECRET}} disabled=no comment="Conexion VPN a CORE-ISPWATCH"

############################
# API SERVICE - HABILITAR PARA ACCESO REMOTO
############################
/ip service
set api address={{LARAVEL_IP}}/32,192.168.88.0/24 disabled=no port=8728
set api-ssl disabled=yes
set www disabled=yes
set winbox address=192.168.88.0/24

############################
# FIREWALL ADDRESS LIST
############################
/ip firewall address-list
add list=ISPWATCH_SUSPENDIDOS comment="Control ISPWatch - Lista inicial"
add list=ISPWATCH_ALLOWED_API address={{LARAVEL_IP}} comment="Servidor Laravel DigitalOcean"
add list=ISPWATCH_ALLOWED_API address=192.168.88.0/24 comment="LAN Local"

############################
# FIREWALL FILTER
############################
/ip firewall filter
add chain=input action=accept connection-state=established,related comment="Accept established"
add chain=input action=drop connection-state=invalid comment="Drop invalid"
add chain=input action=accept protocol=tcp dst-port=8728 src-address-list=ISPWATCH_ALLOWED_API comment="API MikroTik - ISPWatch"
add chain=input action=accept protocol=udp dst-port=500,1701,4500 comment="L2TP/IPsec Client"
add chain=input action=accept protocol=ipsec-esp comment="IPsec ESP"
add chain=input action=drop src-address=87.120.191.1 comment="Bloqueo ataque API"
add chain=input action=drop in-interface-list=WAN comment="Drop WAN input"

############################
# NAT
############################
/ip firewall nat
add chain=srcnat out-interface-list=WAN action=masquerade comment="Internet NAT"
add chain=dstnat src-address-list=ISPWATCH_SUSPENDIDOS protocol=tcp dst-port=80 action=dst-nat to-addresses=192.168.88.252 to-ports=8000 comment="ISPWatch HTTP Redirect"
add chain=dstnat src-address-list=ISPWATCH_SUSPENDIDOS protocol=tcp dst-port=443 action=dst-nat to-addresses=192.168.88.252 to-ports=8000 comment="ISPWatch HTTPS Redirect"

############################
# SYSTEM IDENTITY
############################
/system identity
set name={{ROUTER_NAME}}
