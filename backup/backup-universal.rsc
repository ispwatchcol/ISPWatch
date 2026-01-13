# jan/04/1970 07:30:29 by RouterOS 6.40.4
# software id = DYEL-H1M3
#
# model = RouterBOARD 750G r3
# serial number = 8AFF08ACA064
/interface ethernet
set [ find default-name=ether3 ] master-port=ether2
set [ find default-name=ether4 ] master-port=ether2
set [ find default-name=ether5 ] master-port=ether2
/ip neighbor discovery
set ether1 discover=no
/interface list
add comment=defconf name=WAN
add comment=defconf name=LAN
/interface wireless security-profiles
set [ find default=yes ] supplicant-identity=MikroTik
/ip hotspot profile
set [ find default=yes ] html-directory=flash/hotspot
/ip pool
add name=default-dhcp ranges=192.168.88.10-192.168.88.254
add name=vpn-pool ranges=10.10.10.2-10.10.10.254
/ip dhcp-server
add address-pool=default-dhcp disabled=no interface=ether2 name=defconf
/ppp profile
add local-address=10.10.10.1 name=vpn-profile remote-address=vpn-pool \
    use-encryption=yes
/interface l2tp-server server
set authentication=mschap2 default-profile=vpn-profile enabled=yes \
    ipsec-secret=ISPWATCH_SECRET use-ipsec=yes
/interface list member
add comment=defconf interface=ether2 list=LAN
add comment=defconf interface=ether1 list=WAN
/ip address
add address=192.168.88.1/24 comment=defconf interface=ether2 network=\
    192.168.88.0
add address=190.14.255.107/29 interface=ether1 network=190.14.255.104
/ip dhcp-client
add comment=defconf dhcp-options=hostname,clientid disabled=no interface=\
    ether1
/ip dhcp-server network
add address=192.168.88.0/24 comment=defconf gateway=192.168.88.1
/ip dns
set servers=8.8.8.8
/ip dns static
add address=192.168.88.1 name=router.lan
/ip firewall address-list
add comment="Control ISPWatch" list=ISPWATCH_SUSPENDIDOS
add address=10.10.10.253 comment="Cliente suspendido prueba" list=\
    ISPWATCH_SUSPENDIDOS
/ip firewall filter
add action=drop chain=input comment="Bloqueo ataque API" src-address=\
    87.120.191.1
add action=accept chain=input port=500,1701,4500 protocol=udp
add action=accept chain=input protocol=ipsec-esp
add action=accept chain=input src-address=10.10.10.0/24
/ip firewall nat
add action=masquerade chain=srcnat comment="defconf: masquerade" \
    ipsec-policy=out,none
add action=masquerade chain=srcnat dst-address=192.168.88.252 src-address=\
    10.10.10.0/24
add action=dst-nat chain=dstnat comment="ISPWatch Portal HTTP" dst-port=80 \
    protocol=tcp src-address-list=ISPWATCH_SUSPENDIDOS to-addresses=\
    192.168.88.252 to-ports=8000
add action=dst-nat chain=dstnat comment="ISPWatch Portal HTTPS" dst-port=443 \
    protocol=tcp src-address-list=ISPWATCH_SUSPENDIDOS to-addresses=\
    192.168.88.252 to-ports=8000
add action=masquerade chain=srcnat dst-address=192.168.88.252 src-address=\
    10.10.10.0/24
/ip route
add distance=1 gateway=190.14.255.105
/ip service
set www port=4040
set winbox port=1998
/ppp secret
add name=core-isp-1 password=claveSegura profile=vpn-profile service=l2tp
/system identity
set name="Core Prueba"
/system routerboard mode-button
set enabled=no on-event=""
/tool mac-server
set [ find default=yes ] disabled=yes
add interface=ether2
/tool mac-server mac-winbox
set [ find default=yes ] disabled=yes
add interface=ether2
/tool romon
set enabled=yes
