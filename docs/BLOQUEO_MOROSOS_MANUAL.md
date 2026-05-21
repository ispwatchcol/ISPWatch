# Manual: Sistema de Bloqueo de Morosos (ISPWatch)

Cómo configurar y operar el bloqueo automático de clientes morosos en routers MikroTik, usando la **Opción A**: drop unconditional que funciona con cualquier topología (mono-WAN, multi-WAN, PCC, failover, PPPoE upstream, etc.).

---

## 1. Qué se instala en el router cliente

Cuando presionas **"Aplicar Reglas"** en un router, el sistema instala 5 reglas en MikroTik:

### `/ip firewall address-list`
```
list=ISPWATCH_SUSPENDIDOS  address=0.0.0.0  comment="ISPWatch placeholder"
```
Esta es la lista donde se agregan las IPs de los clientes morosos. El `0.0.0.0` es solo un anchor para que la lista exista.

### `/ip firewall filter` (chain=forward, al TOP)
```
[0] accept src-address-list=ISPWATCH_SUSPENDIDOS  dst-address=<PORTAL_IP>     comment="ISPWatch-ALLOW-PORTAL"
[1] drop   src-address-list=ISPWATCH_SUSPENDIDOS                              comment="ISPWatch-DROP-SUSPENDED"
```
- **[0]** permite al cliente moroso llegar al portal de pago (para que pueda pagar)
- **[1]** bloquea TODO lo demás del cliente moroso, sin importar por cuál interfaz salga

### `/ip firewall nat` (chain=dstnat, al TOP)
```
[0] dst-nat src-address-list=ISPWATCH_SUSPENDIDOS  tcp/80   → <PORTAL_IP>:80   comment="ISPWatch-NAT-HTTP"
[1] dst-nat src-address-list=ISPWATCH_SUSPENDIDOS  tcp/443  → <PORTAL_IP>:443  comment="ISPWatch-NAT-HTTPS"
```
Redirige cualquier intento HTTP/HTTPS del cliente moroso al portal de pago.

---

## 2. Pre-requisitos antes de aplicar reglas

### En `.env` del servidor ISPWatch
```
PORTAL_IP=<IP_DEL_PORTAL_DE_PAGO_ALCANZABLE_DESDE_EL_ROUTER_CLIENTE>
```
Esta IP debe ser alcanzable desde el router cliente. Generalmente es la IP pública o privada del servidor donde corre ISPWatch.

**Verifica desde el router cliente:**
```routeros
/ping <PORTAL_IP>
```
Si no hay respuesta, las reglas se instalarán pero el cliente moroso nunca podrá ver el portal.

### En la BD (cada router)
- `router.ip` — IP del router cliente (overlay L2TP/SSTP)
- `router.user_rb` y `router.password_rb` — credenciales API/SSH del router
- `router.wan_interface` — debe estar configurado (aunque ya no se usa para el drop, sirve como señal de que el operador chequeó la red)
- `router.firmware_version` — útil para diagnósticos

### En el router cliente (RouterOS)
- Servicio API activo: `/ip service enable api`
- Servicio SSH activo: `/ip service enable ssh` (fallback si API no responde)
- IP overlay del CORE permitida en `/ip firewall filter` chain=input (sino el CORE no puede entrar)

---

## 3. Procedimiento de instalación (primera vez por router)

### Paso 1: Configurar interfaz WAN en ISPWatch
1. En la app: **Routers** → click en el router → botón **"WAN"**
2. Se abre el modal "Configurar Interfaz WAN"
3. Selecciona la interfaz física principal del router cliente (ej: `ether1`)
4. Click **"Guardar"**

> **Nota**: con la Opción A, esta selección es solo informativa. El drop funciona aunque el cliente tenga 5 WANs distintas. Solo necesitas guardar algo aquí para pasar la validación.

### Paso 2: Aplicar reglas de bloqueo
1. En la app: **Routers** → click en el router → botón **"Aplicar Reglas"**
2. Confirma en el modal
3. Espera el resultado. Debería decir "Reglas aplicadas via API directa" o "via CORE"

### Paso 3: Verificar instalación
Desde WinBox o terminal del router cliente:
```routeros
/ip firewall filter print where comment~"ISPWatch"
/ip firewall nat print where comment~"ISPWatch"
/ip firewall address-list print where list=ISPWATCH_SUSPENDIDOS
```

Deberías ver:
```
filter:
 0  accept  ...  comment="ISPWatch-ALLOW-PORTAL"
 1  drop    ...  comment="ISPWatch-DROP-SUSPENDED"

nat:
 0  dst-nat ...  comment="ISPWatch-NAT-HTTP"
 1  dst-nat ...  comment="ISPWatch-NAT-HTTPS"

address-list:
 0  ISPWATCH_SUSPENDIDOS  0.0.0.0
```

**Las dos reglas filter deben estar en las primeras posiciones (0 y 1) de la chain forward.** Si están abajo, no funcionarán por reglas accept previas. Re-aplicar reglas debería corregirlo (van con `place-before=0`).

---

## 4. Cómo se suspende un cliente

Cuando ISPWatch corta a un cliente (manual o automáticamente por billing):

1. ISPWatch llama a `SuspensionManager::addSuspendedIpViaCore()` con la IP del cliente
2. Se agrega al address-list: `/ip firewall address-list add list=ISPWATCH_SUSPENDIDOS address=<IP_CLIENTE>`
3. **Se matan las conexiones activas**: `/ip firewall connection remove [find where src-address~"<IP>" or dst-address~"<IP>"]`
4. Próximo paquete del cliente → cae en `ISPWatch-DROP-SUSPENDED` → bloqueado
5. Cliente intenta abrir cualquier web HTTP/HTTPS → cae en `ISPWatch-NAT-HTTP/HTTPS` → redirigido al portal

**Tiempo de corte efectivo: segundos** (no minutos/horas como antes).

---

## 5. Cómo se reactiva un cliente

Cuando el cliente paga:

1. ISPWatch llama a `SuspensionManager::removeSuspendedIpViaCore()`
2. Se remueve del address-list: `/ip firewall address-list remove [find list=ISPWATCH_SUSPENDIDOS address=<IP>]`
3. Próximo paquete del cliente ya no matchea el drop → navega normalmente

---

## 6. Verificación rápida (test de corte)

Para probar que el sistema funciona en un router específico:

### A. Con un cliente real (recomendado)
1. Identifica un cliente conectado y navegando
2. En la app, suspéndelo manualmente
3. En menos de 30 segundos, el cliente:
   - No debe poder cargar páginas que no sean HTTP/HTTPS
   - Al abrir cualquier sitio HTTP/HTTPS, debe ver el portal de pago
4. Reactívalo y debe volver a navegar normal

### B. Con una IP de prueba
Si quieres probar sin afectar clientes reales:

1. En el router cliente, agrega manualmente una IP de tu equipo:
   ```routeros
   /ip firewall address-list add list=ISPWATCH_SUSPENDIDOS address=<TU_IP_LAN> comment="test ISPWatch"
   ```
2. Mata tus conexiones:
   ```routeros
   /ip firewall connection remove [find where src-address~"<TU_IP_LAN>" or dst-address~"<TU_IP_LAN>"]
   ```
3. Intenta navegar desde tu equipo → debe redirigir al portal
4. Limpia:
   ```routeros
   /ip firewall address-list remove [find list=ISPWATCH_SUSPENDIDOS address=<TU_IP_LAN>]
   ```

---

## 7. Troubleshooting

### El cliente está en address-list pero sigue navegando

**Causa 1: Las reglas no están en el top de la chain**
```routeros
/ip firewall filter print
```
Las reglas con comment `ISPWatch-*` deben estar arriba. Si están abajo, hay reglas accept antes que las saltan.

**Fix**: Re-aplicar reglas desde la app (van con `place-before=0`).

**Causa 2: El cliente tiene conexiones establecidas viejas**
Si suspendiste antes de aplicar este fix, las conexiones se quedaron activas.

**Fix manual**:
```routeros
/ip firewall connection remove [find where src-address~"<IP_CLIENTE>" or dst-address~"<IP_CLIENTE>"]
```

**Causa 3: La IP del address-list no es la del cliente**
Si el cliente está detrás de NAT en otro punto, la IP visible al router cliente puede no ser la PPPoE/DHCP.

**Verifica**:
```routeros
/ip dhcp-server lease print where active-address=<IP_QUE_LISTAMOS>
/ppp active print where address=<IP_QUE_LISTAMOS>
```

### El cliente no carga el portal

**Causa 1: Portal IP no alcanzable desde el router**
```routeros
/ping <PORTAL_IP>
```
Si no llega, revisa routing/firewall hacia el portal.

**Causa 2: El cliente usa DoH/DoT (DNS over HTTPS)**
Las redirecciones HTTP/HTTPS funcionan para navegadores tradicionales. Si el cliente tiene DoH activo en Firefox/Chrome, primero resuelve dominios sin pasar por el DNS del router → puede no llegar al portal hasta que intente una conexión TCP a 80/443.

Esto es limitación de DoH, no del sistema. El drop seguirá bloqueando todo lo demás.

### Las reglas se duplicaron

Si aplicaste el botón varias veces antes de este fix, hay duplicados:
```routeros
/ip firewall filter print where comment~"ISPWatch"
```

**Limpieza manual** (deja una de cada):
```routeros
/ip firewall filter remove [find comment="ISPWatch - Bloqueo general"]    # las viejas
/ip firewall nat remove    [find comment="ISPWatch Portal HTTP"]
/ip firewall nat remove    [find comment="ISPWatch Portal HTTPS"]
```

Luego re-aplica desde la app — el fix actual es idempotente (no duplica si ya existen).

### Diagnóstico completo desde consola
```bash
php artisan router:diagnose-wan <router_id>
```
Te muestra estado de SSH al CORE, túneles, lectura de interfaces, etc.

---

## 8. Multi-WAN: por qué la Opción A funciona sola

Si un router cliente tiene 2+ salidas a internet (ether1 + ether2, PCC, failover, etc.):

- El drop rule `chain=forward action=drop` **no especifica out-interface**
- RouterOS evalúa la regla **antes** de decidir por cuál WAN sale el paquete
- Cualquier paquete forward del moroso cae al drop, sin importar la WAN destino
- La regla allow-portal usa `dst-address=<PORTAL_IP>`, también independiente de la WAN

Ejemplo: router con ether1 (ISP-A primario), ether2 (ISP-B failover), pppoe-out1 (3G respaldo):
- Cliente moroso intenta navegar → paquete entra al chain forward
- Match: `src-address-list=ISPWATCH_SUSPENDIDOS` ✓
- Action: `drop`
- Paquete descartado. **Nunca llega a la decisión de routing.**

No requiere configuración adicional. **Una sola regla cubre N WANs.**

---

## 9. Resumen ejecutivo

| Pregunta | Respuesta |
|---|---|
| ¿Cuánto tarda el corte después de suspender? | Segundos (gracias al flush de conntrack) |
| ¿Funciona con multi-WAN? | Sí, automáticamente |
| ¿Puedo aplicar reglas varias veces? | Sí, es idempotente (no crea duplicados) |
| ¿Qué pasa si el portal no está alcanzable? | Cliente queda sin internet y sin portal — verifica `/ping PORTAL_IP` |
| ¿Necesito tocar configuración manual en MikroTik? | No, todo se hace desde la app |
| ¿El cliente puede saltarse el bloqueo con VPN/proxy? | Solo si la VPN/proxy usa puertos no estándar Y el cliente ya tiene la conexión configurada antes de suspender. El drop bloquea tráfico nuevo en todos los puertos |
