# Registro de cambios — ISPWatch

Todas las versiones publicadas de la plataforma, la más reciente arriba.

El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y el
número de versión sigue [SemVer](https://semver.org/lang/es/). La política —qué
cambia MAYOR, MENOR o PARCHE— está en [`config/version.php`](config/version.php)
y explicada en el [manual del desarrollador](docs/MANUAL_DESARROLLADOR.md).

> **Esto no es la bitácora técnica.** Aquí va lo que le cambia al usuario, en su
> idioma. El *porqué* de cada decisión, las causas raíz y la deuda aceptada
> viven en [`docs/BITACORA_TECNICA.md`](docs/BITACORA_TECNICA.md), que es un
> documento distinto y mucho más largo a propósito.

> **Esta versión no es la de la API pública.** `/api/v1/partner` tiene su propio
> contrato y su propio ciclo de vida (ver [`docs/openapi/`](docs/openapi/)).

---

## [1.0.0] — 2026-08-19

Primera versión numerada. La plataforma llevaba en producción desde mayo de 2026
bajo el tag `v1.0.0-beta`, con 395 commits encima y sin forma de saber qué había
desplegado: la pantalla de Sistema mostraba `v1.0.0` escrito a mano. Este release
no "añade" todo lo de abajo — **le pone nombre a lo que ya estaba funcionando** y
abre el registro de aquí en adelante.

### Añadido

- **Contrato OpenAPI de la API pública.** `docs/openapi/ispwatch-partner-v1.yaml`,
  descargable desde la propia API (`GET /api/v1/partner/openapi.yaml`). Es lo que
  se le entrega a un integrador para que conecte su sistema.
- **Centro de Ayuda con índice fijo**, buscador, lectura en la misma página y
  enlace directo por artículo.
- **Sección «Integraciones y API»** en el Centro de Ayuda: cómo emitir una llave,
  qué ve cada permiso y qué revisar cuando el integrador reporta un error.
- **Auto-servicio de llaves de API**: el ISP emite las suyas desde
  **Configuración → Llaves API**, con allowlist de IP y vencimiento obligatorios.
- **Firma remota del contrato** por enlace, sin que el cliente tenga que ir a la
  oficina.
- **Plantillas de documentos** editables por el ISP.
- **Historial de tráfico WAN** por router.
- **Carga masiva de inventario**, espejo de la de clientes.
- **Versionado del producto**: este archivo, `config/version.php` y la pantalla de
  Sistema mostrando la versión real.

### Cambiado

- El aviso de factura, el corte por mora y la reconexión al pagar **respetan el
  interruptor de gestión externa** del router: si un orquestador ajeno administra
  ese equipo, ISPWatch ordena el cambio comercial pero ya no le escribe.
- La pantalla de Sistema muestra la **versión y la fecha de publicación reales**.
  Antes el número estaba escrito a mano y la "última actualización" era la fecha
  de hoy, cualquier día que se mirara.

### Corregido

- **Un cliente que pagaba podía quedarse cortado para siempre**: el corte quedaba
  atrapado en un estado intermedio y el reconciliador lo volvía a cortar.
- **La factura del servicio no salía** en altas de mitad de mes cuando el router
  no tenía configurado el día de facturación; sí salía la de instalación.
- **Borrar un router dejaba a sus clientes huérfanos.** Ahora se rechaza mientras
  tenga clientes vivos.
- **`router:probe-overlay` decía «todos responden» con la flota caída**: los
  routers sin túnel no se contaban en ningún estado.
- **El túnel L2TP colgaba del perfil PPP del cliente** y se quedaba con la IP
  equivocada: ISPWatch perdía el equipo sin ningún error visible.
- **Subir varias fotos de instalación fallaba** por el límite del gateway.
- **Un permiso nuevo no llegaba a los roles de administrador ya existentes**, que
  quedaban sin ver la pestaña correspondiente.

---

## [1.0.0-beta] — 2026-05-18

Primer punto estable en producción: clientes, facturación, corte y reconexión
automáticos contra MikroTik, inventario, soporte e instalaciones.

No tiene entrada detallada: el registro empieza formalmente en 1.0.0.
