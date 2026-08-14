# FreeRADIUS: versión, empaquetado y trampas conocidas

> Documento operativo del subsistema RADIUS. Cubre **qué versión instalar en el
> servidor Ubuntu del cliente y por qué la versión equivocada rompe la
> integración**. El diseño del subsistema está en `ARQUITECTURA.md`.

---

## 1. Versión objetivo

| Componente | Versión | Estado a 2026-08-14 |
|---|---|---|
| FreeRADIUS | **3.2.10** | Última estable de la rama 3.2 (junio 2026) |
| Rama 3.0.x | 3.0.28 | Solo mantenimiento. **No usar** para instalaciones nuevas |
| Rama 4.0 | — | **Sin release estable.** Hay documentación publicada, pero no versión productiva |
| Ubuntu | 24.04 LTS o superior | La distro que ya corre el cliente |

**Regla:** instalar la 3.2.x más alta disponible. No saltar a 4.0 aunque
aparezca — ver §4.

---

## 2. La trampa principal: `rlm_rest` no viene instalado

Esta es la falla que más tiempo hace perder, porque **no da un error claro**.

`apt install freeradius` **no** instala el módulo REST. El paquete base trae el
servidor y los módulos clásicos (`sql`, `files`, `eap`), pero `rlm_rest` viaja
en un paquete aparte. Sin él, FreeRADIUS arranca bien y solo falla al cargar el
sitio que referencia `rest`, con un mensaje sobre un módulo desconocido que no
menciona la palabra "paquete" por ningún lado.

```bash
# El paquete que de verdad hace falta
sudo apt install freeradius freeradius-rest

# Verificar que el módulo existe ANTES de configurar nada
ls -la /usr/lib/freeradius/rlm_rest.so
```

Y hay un segundo paso que también se olvida: instalar el paquete **no** habilita
el módulo. Hay que enlazarlo:

```bash
sudo ln -s /etc/freeradius/3.0/mods-available/rest \
           /etc/freeradius/3.0/mods-enabled/rest
```

> **Ojo con la ruta:** el directorio se llama `3.0` incluso corriendo 3.2.x. Es
> el nombre del directorio de configuración de la rama 3, no de la versión
> instalada. No es un síntoma de que se instaló la versión vieja.

Para diagnosticar cualquier problema de arranque, siempre en primer plano y con
debug — es la única forma de ver por qué un módulo no carga:

```bash
sudo freeradius -X
```

---

## 3. Ubuntu empaqueta versiones viejas: usar el repo oficial

Los repos de Ubuntu congelan la versión al liberar la distro y solo aplican
parches de seguridad. Para tener la 3.2.x actual hay que usar el repositorio
oficial del proyecto (InkBridge Networks, ex NetworkRADIUS):

<https://packages.inkbridgenetworks.com/>

Esto importa por algo concreto: la integración depende de correcciones de
`rlm_rest` y del manejo de timeouts que llegaron a lo largo de la rama 3.2. Una
3.2.0 empaquetada hace dos años se comporta distinto frente a un backend HTTP
lento, que es exactamente el escenario que el diseño de resiliencia contempla.

---

## 4. Por qué NO ir a 4.0

La 4.0 no es "la 3.2 con mejoras": es una reescritura con **sintaxis de
configuración incompatible**. Todo lo que este proyecto documenta —el bloque
`redundant`, la forma de los módulos, el orden de las secciones— cambia de
forma en 4.0.

Además no tiene release estable. Instalarla significaría mantener una
configuración que nadie más está corriendo en producción y que no coincide con
ningún ejemplo publicado.

**Decisión: 3.2.x hasta que 4.0 tenga estable y se migre a conciencia**, con su
propia entrada en `BITACORA_TECNICA.md`.

---

## 5. Diferencias de versión que afectan a ISPWatch

| Aspecto | 3.0.x | 3.2.x (objetivo) | 4.0 |
|---|---|---|---|
| `rlm_rest` | Existe, más limitado | Estable y con timeouts finos | Reescrito |
| Bloque `redundant` con REST | Comportamiento inconsistente ante timeouts | Correcto: cae al siguiente módulo solo ante `fail` | Sintaxis distinta |
| Config | `/etc/freeradius/3.0/` | `/etc/freeradius/3.0/` (mismo nombre) | Otra estructura |
| Recomendación | No usar | **Usar** | Esperar |

La fila que más importa es la segunda. Todo el diseño de degradación depende de
que FreeRADIUS distinga **`fail`** (ISPWatch no contestó → usar el snapshot
local) de **`reject`** (ISPWatch contestó "este cliente no entra" → rechazar de
verdad). Si esa distinción no funciona bien, **un moroso se reconectaría por la
puerta de atrás** cada vez que la API tuviera un hipo. Es la razón técnica
concreta por la que la versión no es un detalle de gusto.

---

## 6. Checklist de verificación antes de dar por buena la instalación

```bash
# 1. Versión: debe decir 3.2.x
freeradius -v

# 2. El módulo REST existe
ls /usr/lib/freeradius/rlm_rest.so

# 3. Está habilitado
ls -la /etc/freeradius/3.0/mods-enabled/rest

# 4. La config es válida (arranca sin errores en modo debug)
sudo freeradius -XC

# 5. El host alcanza la API de ISPWatch
curl -sS -o /dev/null -w '%{http_code}\n' https://<api-ispwatch>/api/health
```

Los cinco tienen que pasar. El punto 5 se verifica **desde el host del
FreeRADIUS**, no desde la laptop del técnico: es el único que importa.

---

## 7. Requisitos de red del host

- **Debe ser par del overlay VPN** (WireGuard del CORE). Sin eso el agente CoA
  no alcanza a los MikroTik y el corte por mora no funciona, aunque la
  autenticación sí.
- **Puertos entrantes:** 1812/udp (auth) y 1813/udp (accounting) desde los NAS.
- **Puerto saliente:** 3799/udp hacia cada NAS, para CoA/Disconnect.
- **Salida HTTPS** hacia la API de ISPWatch.

---

## 8. Pendiente de confirmar con el cliente

- [ ] Versión exacta que está corriendo hoy (`freeradius -v`)
- [ ] Si el host ya es par del overlay WireGuard o hay que agregarlo
- [ ] Si `freeradius-rest` está instalado o hay que sumarlo

---

## Fuentes

- [Releases — FreeRADIUS](https://www.freeradius.org/releases/)
- [FreeRADIUS Packages — InkBridge Networks](https://packages.inkbridgenetworks.com/)
- [Configuration of rlm_rest — privacyIDEA](https://privacyidea.readthedocs.io/en/stable/application_plugins/rlm_rest.html)
- [rlm_rest not compiled — FreeRADIUS issue #5237](https://github.com/FreeRADIUS/freeradius-server/issues/5237)
