# ============================================
# RouterOS 6.40.x - Configuracion CORE-ISPWATCH
# MikroTik Central para ISPWatch
# IP Publica VPN MikroTik: 138.197.30.155
# Servidor Laravel DigitalOcean: 162.159.140.98
# ============================================
# NOTA: Los PPP secrets son creados dinamicamente por Laravel
# NO agregar credenciales manualmente aqui
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
add name=vpn-pool ranges=172.31.200.2-172.31.200.254

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
# L2TP + IPSEC (VPN SERVER)
############################
/ppp profile
add name=vpn-profile local-address=172.31.200.1 remote-address=vpn-pool use-encryption=yes

/interface l2tp-server server
set enabled=yes use-ipsec=yes ipsec-secret="Q9fZ7MrL2xSA8DkEpHwCy" authentication=mschap2 default-profile=vpn-profile

# IMPORTANTE: Los /ppp secret son creados por Laravel via API
# NO agregar credenciales aqui manualmente

############################
# API SERVICE - HABILITAR PARA LARAVEL
# IP del servidor Laravel DigitalOcean: 162.159.140.98
############################
/ip service
set api address=162.159.140.98/32,192.168.88.0/24,172.31.200.0/24 disabled=no port=8728
set api-ssl disabled=yes
set www disabled=yes
set telnet disabled=yes
set winbox address=192.168.88.0/24,172.31.200.0/24

############################
# FIREWALL ADDRESS LIST
############################
/ip firewall address-list
add list=ISPWATCH_SUSPENDIDOS comment="Control ISPWatch - Lista inicial"
# BLACKLIST - Atacantes identificados
add list=BLACKLIST address=87.120.191.13 comment="Ataque API Brute force"
add list=BLACKLIST address=109.158.143.240 comment="Ataque Telnet Brute force"
add list=BLACKLIST address=59.12.158.62 comment="Ataque Telnet Brute force"

add list=ISPWATCH_ALLOWED_API address=162.159.140.98 comment="Servidor Laravel DigitalOcean"
add list=ISPWATCH_ALLOWED_API address=192.168.88.0/24 comment="LAN Local"
add list=ISPWATCH_ALLOWED_API address=172.31.200.0/24 comment="VPN Clientes"

############################
# FIREWALL FILTER
############################
/ip firewall filter
add chain=input action=accept connection-state=established,related comment="Accept established"
add chain=input action=drop connection-state=invalid comment="Drop invalid"
add chain=input action=accept protocol=tcp dst-port=8728 src-address-list=ISPWATCH_ALLOWED_API comment="API MikroTik - ISPWatch"
add chain=input action=accept protocol=udp dst-port=500,1701,4500 comment="L2TP/IPsec"
add chain=input action=accept protocol=ipsec-esp comment="IPsec ESP"
add chain=input action=accept src-address=172.31.200.0/24 comment="VPN Access"
add chain=input action=drop src-address-list=BLACKLIST comment="Drop Blacklisted IPs"
add chain=input action=drop in-interface-list=WAN comment="Drop WAN input"

############################
# NAT
############################
/ip firewall nat
add chain=srcnat out-interface-list=WAN action=masquerade comment="Internet NAT"
add chain=srcnat src-address=172.31.200.0/24 dst-address=192.168.88.252 action=masquerade comment="VPN to Portal"
add chain=dstnat src-address-list=ISPWATCH_SUSPENDIDOS protocol=tcp dst-port=80 action=dst-nat to-addresses=192.168.88.252 to-ports=8000 comment="ISPWatch HTTP Redirect"
add chain=dstnat src-address-list=ISPWATCH_SUSPENDIDOS protocol=tcp dst-port=443 action=dst-nat to-addresses=192.168.88.252 to-ports=8000 comment="ISPWatch HTTPS Redirect"

############################
# SYSTEM IDENTITY
############################
/system identity
set name="CORE-ISPWATCH"
