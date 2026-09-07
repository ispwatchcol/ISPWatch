# Seguimiento del módulo de tickets — CNO

> **Documento vivo.** Se actualiza en cada release, decisión del cliente o cambio de
> alcance. No es un informe cerrado: la sección *Historial de actualizaciones* registra
> cada modificación.

| Campo | Valor |
|---|---|
| **Inicio del seguimiento** | 2026-08-21 |
| **Requerimiento analizado** | CNO-ISPWASH-ST-API · **V1.1** · 2026-08-10 |
| **Fuente documental** | [`docs/cliente/CNO/V1_1/`](V1_1/) — 6 archivos, integridad verificada |
| **Commit de preservación documental** | `7275e0f` — *«add: Documentación de los requerimientos del cliente…»* ✅ integrado en `main` vía PR **#248** |
| **PR #1 · catálogos del Anexo A** | PR **#250** (merge `79b3501`) — desplegado y validado en producción |
| **PR #2 · captura del diagnóstico** | PR **#251** (merge `9044731`) — desplegado; **cumple F1-03** |
| **R1 · catálogos versionados** | `acd00c9` · PR **#233** (merge `0bca163`) |
| **R2 · lectura/escritura por FK** | `195bbaf` · PR **#233** (merge `0bca163`) |
| **R2.5 · desacoplar escritura de enums** | `9bb760e` · PR **#235** (merge `d989154`) |
| **R3 · eliminar enums heredados** | `74f5d8e` (+ `03136bd` fix CI) · PR **#236** (merge `58f9c4b`) |
| **Fecha de corte de esta evaluación** | 2026-08-21 |
| **Estado general de la entrega** | 🟡 **Base estructural entregada · expediente técnico pendiente** |

## Verificación de integridad documental

`sha256sum -c` sobre [`V1_1/MANIFIESTO_SHA256.txt`](V1_1/MANIFIESTO_SHA256.txt) el 2026-08-21:

| Archivo | Resultado |
|---|---|
| `Solicitud_Maestra_ISPwash_CNO_V1_1.docx` | ✅ OK |
| `Solicitud_2A_Contrato_OpenAPI_ISPwash_CNO_V1_1.txt` | ✅ OK |
| `Anexo_Tecnico_Contrato_API_ISPwash_CNO_V1_1.md` | ✅ OK |
| `Checklist_Entrega_Contrato_ISPwash_CNO_V1_1.csv` | ✅ OK |
| `Registro_Cambios_V1_1.txt` | ✅ OK |
| `LEEME_EXTERNO.txt` | ✅ OK |

**6 de 6 coinciden. Ningún archivo faltante ni alterado.**

Observaciones, registradas sin corregir:

1. `MANIFIESTO_SHA256.txt` no se lista a sí mismo. **Es lo esperado** —un manifiesto no se
   auto-verifica— y no constituye discrepancia.
2. ~~El commit de preservación `7275e0f` **no está en `main`**.~~ **Resuelto**: se integró
   en `main` con el PR **#248** el 2026-08-21. La documentación del cliente ya es visible
   para todo el equipo.
3. Las releases R1-R3 se desarrollaron en la rama `david-support-ticket-module`; el trabajo
   actual ocurre en `david-module-support-tickets`. Nombres distintos, no confundir al
   rastrear historia.

---

## Resumen ejecutivo

### Operativo en producción

La **infraestructura de datos** del ticket reestructurado está desplegada y validada
(verificado el 2026-08-20 contra el esquema `public`):

- Catálogos versionados con código estable inmutable, etiqueta editable y retiro suave
  (`ticket_status`, `ticket_priority`, `ticket_category` con 4 filas cada uno).
- Las tres columnas enum heredadas fueron eliminadas; el catálogo es la única
  representación. `status`, `priority` y `category` siguen viajando como **cadena con el
  código estable** en todas las respuestas.
- Cinco columnas de diagnóstico creadas: `symptom_id`, `suspected_cause_id`,
  `confirmed_cause_id`, `solution_id`, `result_id`. Sus catálogos traen el **vocabulario
  oficial del Anexo A** — 58 códigos — desde el PR #250, validado en producción.
- `closed_at` separado de `resolved_at`.
- Contrato **OpenAPI 3.0.3** oficial publicado y servido en `GET /v1/partner/openapi.yaml`.

### Parcialmente implementado

El diagnóstico **ya es funcionalidad operativa** desde el PR #2: se captura en el alta y la
edición, se valida contra el catálogo vigente del operador y se lee con código y etiqueta.
Lo que sigue parcial es su exposición a **socios** (**D-07**).

Queda con estructura pero sin funcionalidad completa: los adjuntos ya tienen control de
acceso y almacenamiento persistente, pero **sin hash de integridad ni política de
retención**; las estadísticas calculan promedio pero no percentiles; la asociación con
infraestructura llega sólo hasta `sectorial_id`.

### Pendiente

El **expediente técnico** que motiva el requerimiento: ciclo de vida de 9 estados,
reglas de cierre, historial/auditoría, intervenciones múltiples, pruebas estructuradas,
incidentes padre, duplicados, reincidencias y exportación.

### Lo que NO debe declararse completado todavía

> **Sólo F1-03 está cumplido en su criterio de aceptación** (PR #2). El resto, no.

Concretamente, **no** debe reportarse como cumplido:

- ~~**F1-03** por existir las cinco columnas. El criterio exige campos *capturables y
  consultables*; hoy son columnas vacías sin UI ni API.~~ **Resuelto por el PR #2**: los
  cinco campos se capturan en el alta y la edición, se validan contra el catálogo vigente
  y se leen con código y etiqueta. Queda como decisión aparte si se exponen al integrador
  (**D-07**), que no forma parte del criterio de F1-03.
- **F1-04** por existir `resolved_at` y `closed_at`. El criterio exige estados,
  transiciones **e historial**.
- **F1-11** por existir la tabla de adjuntos. La **protección de acceso ya está resuelta**
  (endpoint autenticado con verificación de tenant, disco privado), pero **siguen faltando
  el hash de integridad y la política de retención**, así que el criterio no se cumple.
- **F1-17** por tener ya la auditoría. El PR #3 resuelve la **mitad de auditoría** —historial
  inalterable con actor, campo, valor anterior y valor nuevo—, pero el criterio exige además
  el **modelo de roles** de la sección 18 (Recepción/N1, N2, Técnico de campo, Supervisor,
  Auditor/gerencia), y hoy sólo existe `view_support`.
- **F1-19** por existir un tablero. El criterio exige mediana, P90 y P95.

La distinción que se aplica en toda la matriz:

| Nivel | Significado |
|---|---|
| **Estructura existente** | La columna, tabla o ruta existe |
| **Funcionalidad operativa** | Un usuario o integrador puede usarla de punta a punta |
| **Criterio de aceptación cumplido** | Cumple lo que el cliente escribió, con prueba |

---

## Matriz de avance

Estados: **Cumplido** · **Parcial** · **Pendiente** · **Contradicción** · **Bloqueado**.

### Solicitud 1 — Reestructuración funcional (Anexo B, F1)

| ID | Requisito | Estado | Evidencia | Siguiente acción | Dependencia |
|---|---|---|---|---|---|
| **F1-01** | Ticket asociado a cliente **y servicio específico** | 🔴 Bloqueado | `support_ticket.user_id`; `customer_profile` con PK = `user_id` | Definir modelo de servicio | **Decisión D-01** |
| **F1-02** | Alcance exclusivo soporte; excluir facturación | ⚠️ **Contradicción** | `routes/api.php:368-369` — `POST /support/{id}/charge` | No tocar; escalar | **Decisión D-02** |
| **F1-03** | Síntoma, causa sospechada, causa confirmada, acción y resultado | 🟢 **Cumplido** | 5 columnas en `support_ticket`; catálogos del Anexo A (16+7+20+15); **captura en alta y edición**, validación por catálogo y por tenant, y lectura con código y etiqueta en el detalle y en la API del panel | — | Subcausas sin código: **D-06**. Exposición a socios: **D-07** (no forma parte de F1-03) |
| **F1-04** | Estados y transiciones con timestamps e historial | 🟡 Parcial | 4 estados vs 9 + 9 auxiliares (Maestra L139-149); `resolved_at`, `closed_at` | **PR #4** | **Decisión D-03** |
| **F1-05** | Campos condicionales radio / FTTH | ⚪ Pendiente | No existe | Diseño posterior | Tras PR #2 |
| **F1-06** | Asociación zona, nodo, AP/OLT, PON, CPE/ONU | 🟡 Parcial | `support_ticket.sectorial_id` | Ampliar jerarquía | — |
| **F1-07** | Snapshot histórico de infraestructura | ⚪ Pendiente | `sectorial_id` es FK viva, no snapshot | Diseño posterior | Tras F1-06 |
| **F1-08** | Varias intervenciones por ticket | ⚪ Pendiente | `support_ticket_message` son comentarios | **PR #5** | — |
| **F1-09** | Pruebas iniciales y finales estructuradas | ⚪ Pendiente | No existe | Tras PR #5 | — |
| **F1-10** | Reglas de cierre y excepciones auditadas | ⚪ Pendiente | Cualquier transición permitida | **PR #4** | **Decisión D-03** |
| **F1-11** | Adjuntos y evidencia con metadatos | 🟡 **Parcial** | `support_ticket_attachment` con nombre, tamaño y MIME. **Acceso resuelto** (PR de endurecimiento): disco `s3`, endpoint autenticado con verificación de tenant y ticket, y lista blanca de tipos servibles en línea. **Falta hash de integridad y política de retención** | Definir hash y retención | **Decisión D-05** |
| **F1-12** | Materiales y equipos retirados/instalados | ⚪ Pendiente | Existe `installation_equipment`, para instalaciones | **PR #5** | — |
| **F1-13** | Detección de duplicados y tickets abiertos | ⚪ Pendiente | No existe | **PR #6** | — |
| **F1-14** | Reincidencias 7/30/90 días (P1) | ⚪ Pendiente | No existe | **PR #6** | — |
| **F1-15** | Incidente padre y tickets relacionados | ⚪ Pendiente | Sin `parent_ticket_id`; `router_outage_events` es base parcial | **PR #6** | — |
| **F1-16** | Servicios afectados y minutos-cliente (P1) | ⚪ Pendiente | No existe | Tras PR #6 | — |
| **F1-17** | Roles, permisos y auditoría | 🟡 **Parcial** | **Auditoría resuelta (PR #3)**: `support_ticket_history` inalterable con actor, campo, valor anterior/nuevo, origen y fecha; visible en el detalle. **Falta el modelo de roles** de la sección 18: sólo existe `view_support` | Roles N1/N2/campo/supervisor/auditor | Depende de **D-09** |
| **F1-18** | Exportación completa y filtros por infraestructura | ⚪ Pendiente | Sin export de tickets | **PR #7** | Tras F1-06 |
| **F1-19** | Tableros con mediana, P90 y P95 (P1) | 🟡 Parcial | Sólo `avg_resolution_time` (`SupportTicketController.php:411`); 0 percentiles | **PR #7** | — |
| **F1-20** | Zona horaria America/Bogota | 🟡 Parcial | `config/app.php:70` → `UTC` (almacenamiento correcto); presentación sin fijar | **PR #7** | — |

### Solicitud 2 — Integración API (Anexo B, F2 aplicables)

| ID | Requisito | Estado | Evidencia | Siguiente acción |
|---|---|---|---|---|
| **F2-01** | OpenAPI oficial 3.0.x sanitizado y fechado | 🟢 **Cumplido** | `docs/openapi/ispwatch-partner-v1.yaml` (1 085 líneas, 3.0.3); `GET /v1/partner/openapi.yaml` | Entregar al cliente |
| **F2-02** | OAuth2 o token restringido rotatorio | 🟢 Cumplido (alternativa) | Sanctum + abilities + expiración + allowlist IP | Declarar como alternativa |
| **F2-03** | IDs estables cliente / servicio / router lógico | 🟡 Parcial | `/customers`, `/services`; router lógico sin id expuesto | Exponer `router_id` |
| **F2-04** | Consulta incremental cursor / `updated_since` | 🟢 Cumplido | `updated_since` en `/tickets`; `/events` con cursor | — |
| **F2-05** | Lectura de tickets **e historial** | 🟡 Parcial | `GET /v1/partner/tickets` sirve el listado. El historial **ya existe y es consultable** desde el PR #3, pero sólo por el panel: la API de socios no lo expone | **D-07** |
| **F2-07** | Comentarios, intervenciones y adjuntos por API | ⚪ Pendiente | Tablas existen, no expuestas | Tras PR #5 |
| **F2-09** | Webhooks firmados o incremental confiable | 🟢 Cumplido (alternativa) | Feed `/events` | Documentar como alternativa |
| **F2-12** | Errores estructurados, rate limits, reintentos | 🟢 Cumplido | 11 códigos estables; 60/min + 5 000/h | — |
| **F2-13** | Auditoría de operaciones API | 🟢 Cumplido | `api_key_request_logs` | — |
| **F2-16** | Credenciales separadas sandbox / producción | ⚪ Pendiente | No existe sandbox | Decisión de infraestructura |
| **F2-17** | Diccionario de campos, enums y códigos estables por API | 🟡 Parcial | `GET /api/catalogs/ticket` ya sirve los 4 catálogos de diagnóstico con código, etiqueta y versión — pero es endpoint **del panel**, no de la API de socios | Exponer catálogos al integrador: **D-07** |
| **F2-18** | Los campos nuevos no quedan sólo en UI | 🟡 Parcial | El PR #2 los persiste en columnas propias con clave foránea y los sirve por la API del panel — **no quedan sólo en la interfaz**. Lo que falta es exponerlos a **socios** | **D-07** |

---

## ⚠️ Nota específica sobre F1-02 — contradicción activa

**El requerimiento** (Maestra L86, L714; Anexo B F1-02) limita el módulo a soporte técnico
y **excluye expresamente facturación y cartera**. Es además uno de los principios
obligatorios del cliente: *«El módulo no debe mezclar instalaciones, traslados, retiros,
ventas, cartera ni facturación.»*

**El estado actual** contradice eso en dos puntos verificados:

1. `routes/api.php:368-369` expone `POST /api/support/{id}/charge` y
   `GET /api/support/{id}/charges`: se generan facturas desde el ticket.
2. `ticket_category` incluye el código `billing`.

**No se ha modificado nada de esto en esta tarea, y es deliberado.** Es funcionalidad
existente que otros tenants pueden estar usando; retirarla o restringirla por tenant es una
decisión de producto y de alcance contractual, no un ajuste técnico.

**Queda registrada como riesgo y decisión pendiente D-02.** Hasta resolverse, F1-02 no
puede reportarse al cliente como cumplido ni como pendiente sin más: es una contradicción
declarada.

---

## Hitos realizados

### R1 · Catálogos versionados — `acd00c9` · PR #233

**Objetivo.** Sustituir los tres enums del ticket por catálogos con código estable,
etiqueta editable y versión, e incorporar el vocabulario de diagnóstico.

**Cambios.** 7 tablas de catálogo + `ticket_catalog_version`; 9 columnas nuevas en
`support_ticket` (3 FK + 5 de diagnóstico + `closed_at`); backfill verificado con consulta
anti-join que aborta si queda algún huérfano. Siembra desde migración, nunca desde seeder.

**Evidencia.** `database/migrations/2026_08_14_000001..000003`; `docs/BITACORA_TECNICA.md`
§26. 46 pruebas nuevas en `tests/Feature/Support/`, incluido el contrato congelado de la
API pública.

**Producción.** ✅ Desplegada y verificada.

### R2 · Lectura y escritura por clave foránea — `195bbaf` · PR #233

**Objetivo.** Que la clave foránea sea la fuente de verdad y ningún lector dependa de las
columnas enum.

**Cambios.** Servicio `TicketCatalogs` (resolución código ⇄ id sin N+1); accessors y
mutators en el modelo; filtros, validación y `statistics()` por FK; API de socios con join
emitiendo el código como cadena; endpoint `GET /api/catalogs/ticket`; frontend sin mapas de
etiquetas en duro.

**Evidencia.** `app/Support/TicketCatalogs.php`; `docs/BITACORA_TECNICA.md` §27.
`TicketCatalogReadPathTest` corrompía el espejo a propósito para demostrar que nadie lo leía.

**Producción.** ✅ Desplegada.

### R2.5 · Desacoplar la escritura del espejo — `9bb760e` · PR #235

**Objetivo.** Dejar de escribir las columnas enum para que R3 pudiera eliminarlas sin
romper el contenedor viejo durante el despliegue.

**Cambios.** El mutator escribe sólo la FK; `status`, `priority` y `category` declarados en
`$appends` (sin eso habrían desaparecido del JSON al dropear las columnas).

**Evidencia.** `docs/BITACORA_TECNICA.md` §28; `docs/RUNBOOK_DESPLIEGUE_R3_TICKETS.md`.

**Producción.** ✅ Desplegada.

### R3 · Eliminar los enums heredados — `74f5d8e` (+ `03136bd`) · PR #236

**Objetivo.** Cerrar la transición: el catálogo como única representación.

**Cambios.** Eliminadas `status`, `priority` y `category` con sus tres `CHECK`. La migración
aborta si alguna FK está sin resolver; la divergencia del espejo congelado sólo se registra
en el log. `03136bd` corrigió un test que consultaba las columnas eliminadas y que SQLite
enmascaraba por el *double-quoted string misfeature*.

**Evidencia.** `database/migrations/2026_08_15_000001_drop_ticket_enum_columns…`;
`docs/BITACORA_TECNICA.md` §29; `docs/MANUAL_DESARROLLADOR.md` §11.

**Producción.** ✅ Desplegada y validada. Esquema verificado el 2026-08-20.

### Preservación documental — `7275e0f`

**Objetivo.** Que el compromiso con el cliente viva en el repositorio y no en una descarga
local.

**Evidencia.** `docs/cliente/CNO/V1_1/` — 6 archivos, 6/6 hashes verificados.

**Producción.** ⚠️ **No integrado en `main`.** Sin push.

---

## Backlog priorizado

### PR #1 · Catálogos de diagnóstico del Anexo A

| Campo | Detalle |
|---|---|
| **Objetivo** | Sembrar el vocabulario de diagnóstico y exponerlo por API |
| **Cubre** | F1-03 (parcial), F2-17 (parcial), F2-18 (parcial) |
| **Alcance** | Migración idempotente con los códigos del Anexo A: **16** síntomas `S01`-`S16`, **7** familias de causa `RF`/`FO`/`CL`/`AA`/`RE`/`EX`/`NF`, **20** acciones `AC01`-`AC20`, **15** resultados `R01`-`R15`. Ampliación aditiva de `CatalogController::ticketCatalogs()`. **Sin UI, sin estados nuevos, sin cambios de permisos.** |
| **Dependencias** | Ninguna técnica |
| **Pruebas** | `tests/Feature/Support/TicketDiagnosticCatalogSeedTest.php` — 22 pruebas: conteos, códigos exactos, etiquetas literales, idempotencia, no pisar etiquetas editadas, no tocar tickets, versión, endpoint, **forma exacta de la respuesta**, autorización, **rechazo de llave de socio**, retiro y **aislamiento entre tenants** |
| **Aceptación** | ✅ `GET /api/catalogs/ticket` devuelve 16 síntomas, 7 familias, 20 acciones y 15 resultados con los códigos literales del Anexo A |
| **Estado** | 🟠 **Implementado en local — sin commit, pendiente de revisión y de CI.** No está desplegado ni cumplido. Aplicado en el esquema de **desarrollo** (`ispwatch_dev`, 58 filas); `public` sigue en 0 filas y sin la migración registrada. Verificado en SQLite (suite completa) y la migración además en PostgreSQL real; la suite sobre PostgreSQL sólo puede correrla el CI (ver más abajo) |
| **Riesgo asumido** | Los códigos son **inmutables** al sembrarse. Si el cliente los cambia en la reunión de la sección 40 habría que retirarlos y crear nuevos; mientras ningún ticket los referencie el coste es cero — por eso este PR **no** incluye captura |

**No cierra F2-17/F2-18.** El endpoint ampliado es el del **panel** (`auth:sanctum` +
`deny_api_clients`), no la API de socios. El integrador todavía no puede leer los catálogos.
Exponerlos requiere una ruta nueva bajo `/v1/partner` y su entrada en el OpenAPI: es un
cambio del contrato público y se registra como **D-07**, no se decide aquí.

**Nota de nomenclatura.** El cliente llama «Acción» (Anexo A.3) a lo que el esquema guarda
en `ticket_solution` / `support_ticket.solution_id`. Los códigos son los oficiales
(`AC01`…`AC20`) y el endpoint los publica bajo la clave `actions`, alineada con el
requerimiento; la tabla conserva el nombre que le puso la R1. Renombrarla tocaría el
esquema de una fase ya desplegada. Registrado como **D-08**.

**Alcance de la validación en PostgreSQL.** La *migración* sí se ejecutó contra PostgreSQL
real (18.3 local, base desechable): ciclo `migrate → rollback → migrate`, 58 filas sin
duplicados, acentos íntegros y el índice parcial rechazando un código de plataforma
repetido mientras admite el mismo código para un tenant. Lo que **no** puede correrse en
local es la *suite* sobre PostgreSQL: `migrate` completo exige PostGIS
(`sectorial.coordinates`) y el servidor local no lo tiene — el CI usa la imagen
`postgis/postgis:16-3.4` justamente por eso. **PostgreSQL no queda validado en local para
las 22 pruebas; eso lo cierra el job «PHPUnit (PostgreSQL, motor real)».**

### PR #2 · Captura del diagnóstico

| Campo | Detalle |
|---|---|
| **Objetivo** | Que el operador registre síntoma, causa sospechada, causa confirmada, acción y resultado |
| **Cubre** | **F1-03 (completa)** — captura, persistencia, validación y lectura |
| **Alcance** | Componente `TicketDiagnosisFields.vue` reutilizado en creación y edición; bloque de sólo lectura en `SupportDetail.vue`; validación por catálogo y por tenant en `SupportTicketController`; `diagnosis` con código y etiqueta en la respuesta del panel |
| **Dependencias** | PR #1 ✅ (desplegado como PR #250) |
| **Pruebas** | `tests/Feature/Support/TicketDiagnosisCaptureTest.php` — 28 pruebas: alta con y sin diagnóstico, edición campo a campo, borrado con `null`, lectura, tickets antiguos, código inexistente, catálogo equivocado, subcausa inventada, fila retirada, aislamiento entre ISPs, permisos y no regresión de la R3. Más una prueba nueva en `PartnerTicketContractTest` |
| **Aceptación** | Un ticket puede registrar los cinco campos por separado, borrarlos, y consultarlos con código y etiqueta legible |
| **Estado** | 🟠 **Implementado — PR abierto, pendiente de revisión** |
| **Migraciones** | **Ninguna.** Las cinco columnas existen desde la R1 |

**No toca la API de socios.** El alcance original decía «exposición en la API de socios»; se
retiró de este PR de forma deliberada. El repositorio **no** demuestra que el contrato
vigente exija esos campos: `PartnerSupportController` publica diez claves congeladas desde
la R2 y el OpenAPI no menciona diagnóstico. Añadirlo amplía el contrato público y es la
decisión **D-07**, todavía sin tomar. Hay un test que fija que no se filtre por descuido —
el modelo trae `diagnosis` en `$appends`, así que bastaría un `->get()` mal puesto.

**Las 48 subcausas siguen sin ser seleccionables.** La interfaz las muestra como texto de
referencia bajo el desplegable de causa, tomado de `description`. Ninguna es una opción
codificada (**D-06**).

**Ejemplos de diagnóstico válido** (códigos del Anexo A):

| Caso | Síntoma | Causa sospechada | Causa confirmada | Acción | Resultado |
|---|---|---|---|---|---|
| Intermitencia resuelta en visita | `S02` | `RF` | `CL` | `AC07` | `R02` |
| Corte por fibra cortada | `S01` | `FO` | `FO` | `AC13` | `R03` |
| Sin falla encontrada | `S03` | `CL` | `NF` | `AC01` | `R07` |
| Diagnóstico parcial (aún abierto) | `S05` | `RE` | *(sin definir)* | *(sin definir)* | *(sin definir)* |

El último caso es legal a propósito: el PR #2 **no** impone obligatoriedad ni reglas de
cierre. Eso es el PR #4.

### Endurecimiento posterior al PR #2 · Notas y adjuntos

El humo en producción sobre el ticket #25 destapó dos fallos **ajenos al diagnóstico** que
ya existían antes. Se corrigen en un PR aparte para no mezclarlos con la entrega funcional.

| Campo | Detalle |
|---|---|
| **Objetivo** | Que guardar una nota funcione y que la evidencia adjunta se vea y se descargue |
| **Cubre** | Ninguna F nueva. Desbloquea parte de **F1-11** (acceso) sin cumplirlo |
| **Migraciones** | Ninguna |
| **Estado** | 🟠 **Implementado — PR abierto, pendiente de revisión** |

**Fallo 1 — guardar una nota devolvía HTTP 422.**

La interfaz mandaba el **autor** en el cuerpo (`user_id`), leyéndolo del almacenamiento del
navegador. Pero la sesión sólo queda en `localStorage` si se marcó «recordarme»; en
cualquier otro caso vive en `sessionStorage`. Sin ese dato, el componente caía al literal
`user_id: 1` — un usuario que **no existe** en producción — y la regla `exists:users,id`
rechazaba cada intento.

Arreglar la lectura del almacenamiento habría tapado el síntoma. El problema de fondo era
que la **autoría la decidía el cliente**: cualquiera podía firmar una nota en nombre de otro
cambiando el payload. El autor sale ahora de la sesión y el campo ya no se acepta.

- **Endpoint**: `POST /api/support/{ticket}/message` *(singular, no `messages`)*
- **Payload correcto**: `{ "message": "…", "is_internal": true }` — **sin `user_id`**
- Nota vacía → 422 con *«La nota no puede estar vacía.»*, que la interfaz ahora muestra tal cual
- Reenviar la misma nota en menos de 10 s devuelve la existente en vez de duplicarla

**Fallo 2 — la vista previa del adjunto salía rota.**

Dos causas encadenadas, ambas estructurales:

1. Los adjuntos se guardaban en el **disco local** y se servían por `asset('storage/…')`. El
   `run_command` del despliegue **no ejecuta `storage:link`**, así que esa ruta no existía.
2. Aunque existiera, el sistema de archivos de App Platform es **efímero y por instancia**:
   el archivo subido desaparece en el siguiente despliegue. `sp1.jpg` se subió antes de
   desplegar el PR #2 y se fue con el contenedor — por eso la fila seguía en la lista y la
   imagen no cargaba.

Había además un tercer problema que nadie había reportado: esa URL era **pública**. Las
rutas son adivinables (`support_attachments/{ticket}/…`), así que cualquiera podía leer la
evidencia de otro ISP sin sesión.

**Política de acceso a adjuntos** (implementada):

| Aspecto | Decisión |
|---|---|
| Almacenamiento | Disco `s3` privado, el mismo que ya usan los documentos de cliente |
| Vista previa | `GET /api/support/{ticket}/attachments/{attachment}` — `Content-Disposition: inline` |
| Descarga | `…/attachments/{attachment}/download` — `Content-Disposition: attachment` |
| Autorización | `auth:sanctum` + `permission:view_support`; el ticket pasa por el scope de tenant y el adjunto se busca **dentro** del ticket |
| Fuera de alcance | **404**, nunca 403: no se confirma que el recurso exista |
| Tipos servibles en línea | Lista blanca: JPEG, PNG, GIF, WebP, PDF. Todo lo demás se descarga |
| Por qué lista blanca | `mime_type` se guardó al subir y no se revalida; servir en línea lo que diga esa columna permitiría un `text/html` desde nuestro dominio, es decir XSS almacenado |
| Cabeceras | `Cache-Control: private, no-store` y `X-Content-Type-Options: nosniff` |
| Archivo ausente | 404 con *«El archivo adjunto ya no está disponible.»*, y la interfaz lo explica en vez de mostrar un icono roto |

**F1-11 sigue parcial.** El acceso está resuelto; **el hash de integridad y la política de
retención no** (**D-05**).

**Hallazgo colateral, corregido aquí.** Un usuario del panel con sesión abierta que
navegara a una URL de `/v1/partner` recibía **HTTP 500** en vez de 401: el limitador de
peticiones llamaba `getKey()` sobre un `TransientToken`, que no lo implementa.

**Los adjuntos anteriores a este cambio no se recuperan.** Sus archivos ya no existen en
ningún disco. Las filas se conservan —son parte del histórico del ticket— y el endpoint
responde 404 con mensaje claro.

### PR #3 · Historial y auditoría del ticket

| Campo | Detalle |
|---|---|
| **Objetivo** | Trazabilidad no editable de todo cambio |
| **Cubre** | **F1-17 (mitad de auditoría)**. No cubre el modelo de roles, ni cierra F2-05 |
| **Alcance** | Tabla `support_ticket_history` append-only; observer sobre `SupportTicket`; eventos de nota, adjunto y cargo; endpoint paginado de sólo lectura; sección «Historial» en el detalle |
| **Dependencias** | Ninguna |
| **Migraciones** | `2026_08_25_000001_create_support_ticket_history_table` |
| **Pruebas** | `tests/Feature/Support/TicketHistoryTest.php` — 31 pruebas |
| **Aceptación** | Consultando un ticket se obtiene su cronología con actor, campo, valor anterior y nuevo, y no existe forma de editarla desde la operación |
| **Estado** | 🟠 **Implementado — PR abierto, pendiente de revisión** |

**Criterio literal que cumple** (`Solicitud_Maestra`, sección 18):

> «Cada cambio debe conservar fecha/hora, usuario o aplicación, estado anterior/nuevo, campo
> modificado y valores anteriores/nuevos. La auditoría no debe ser editable desde la
> operación ordinaria.»

| Exigencia | Cómo se cumple |
|---|---|
| fecha/hora | `created_at`, mostrada en la interfaz en **America/Bogota** |
| usuario **o aplicación** | `actor_user_id` + `source`; sin actor humano el origen es `system` y la interfaz dice «Sistema» |
| campo modificado | Columna `field` |
| valores anterior/nuevo | `old_value` / `new_value`, con **código estable** (`open`, `S02`, `AC07`), no ids internos |
| no editable | El modelo lanza en `updating` y `deleting`; no existen rutas de edición ni de borrado |

**Eventos que registra:** alta del ticket · estado · prioridad · categoría · técnico asignado ·
los cinco campos de diagnóstico · nota agregada · adjunto agregado · cargo generado.

**Ejemplos de evento**

| Evento | `field` | `old_value` | `new_value` | Cómo se lee en pantalla |
|---|---|---|---|---|
| `status_changed` | `status` | `open` | `in_progress` | «Cambió el estado: Abierto → En proceso» |
| `confirmed_cause_changed` | `confirmed_cause` | *(vacío)* | `RF` | «Cambió la causa confirmada: sin definir → Radiofrecuencia» |
| `staff_changed` | `staff_id` | *(vacío)* | `47` | «Cambió el técnico asignado: sin definir → Juan Restrepo» |
| `attachment_added` | — | — | — | «Se adjuntó el archivo «sp1.jpg»» |
| `note_added` | — | — | — | «Se agregó una nota interna» |

**Decisiones de diseño que conviene conocer**

- **Se registra el cambio real, no el payload.** Va por observer y no por controlador: la
  pantalla de edición reenvía el formulario entero en cada guardado, y registrar lo recibido
  dejaría un evento por campo cada vez, volviendo el historial ilegible.
- **Códigos, no ids.** Un id no significa nada fuera de esta instalación. La **etiqueta del
  momento** se congela en `metadata`: las etiquetas del catálogo son editables por diseño
  (R1), y el historial debe seguir diciendo lo que el operador vio ese día.
- **Referencias, no copias.** De una nota se guarda su id, no el texto —vive en la bitácora
  de trabajo, que sí es editable, y duplicarlo dejaría dos versiones que divergen—. De un
  adjunto, el nombre visible y **nunca la ruta del bucket**. De un cargo, el número de
  factura y no el importe, que cambia cuando se anula o se paga.
- **Tabla propia y no `audit_logs`.** Aquélla guarda JSON del modelo entero, está detrás de
  `view_audit_log` —permiso de administración, no de soporte— y no tiene clave foránea al
  ticket. Se sigue el patrón de `sectorial_history`, que ya resolvió esto para infraestructura.

**Lo que este PR NO hace**

- **No completa F1-17.** Falta el **modelo de roles** de la sección 18 (Recepción/N1, N2,
  Técnico de campo, Supervisor, Auditor/gerencia); hoy sólo existe `view_support`. Es
  **D-09**.
- **No cierra F2-05.** El historial es del panel; el integrador no lo ve. Es **D-07**.
- **No reconstruye el pasado.** Los tickets anteriores al despliegue arrancan sin historial,
  y así se dice en pantalla. Inventar eventos retroactivos sería falsificar una auditoría.
- No toca estados, reglas de cierre, subcausas, intervenciones, duplicados ni métricas.

### PR A · Impedir el borrado físico del ticket

Correctivo urgente de seguridad e integridad, **independiente** de las decisiones pendientes
del cliente. Nace de la auditoría recogida en
[`DISENO_PERMISOS_Y_ARCHIVADO.md`](DISENO_PERMISOS_Y_ARCHIVADO.md).

| Campo | Detalle |
|---|---|
| **Objetivo** | Que nadie —lectura, técnico, staff o administrador— pueda destruir un ticket, su auditoría, sus adjuntos o su trazabilidad contable |
| **Cubre** | Ningún requisito F nuevo. **Protege** F1-17 (auditoría) y F1-11 (evidencia) ya entregados |
| **Migraciones** | `2026_08_27_000001_restrict_delete_on_support_ticket_history` |
| **Pruebas** | `tests/Feature/Support/TicketDeletionBlockedTest.php` — 17 pruebas |
| **Estado** | 🟠 **Implementado — PR abierto, pendiente de revisión** |

**El agujero que cierra.** `DELETE /api/support/{id}` estaba tras `permission:view_support` —el
**mismo permiso que leer**, que tienen los roles `Tecnico` y `Staff` de todos los ISP— y
borraba de verdad. Desde el PR #3, `support_ticket_history` cuelga del ticket con
`ON DELETE CASCADE`: **un clic se llevaba el ticket y su auditoría entera**. La única barrera
era un `confirm()` del navegador.

Además borraba los adjuntos de `Storage::disk('public')`, un disco donde ya no viven desde el
PR #252: no borraba el objeto del bucket y sí la fila que decía dónde estaba. Y
`invoices.ticket_id` es `nullOnDelete()`, así que un cargo facturado quedaba sin expediente.

**Tres defensas independientes**, para que reactivar una no reabra el agujero:

| Capa | Qué hace | Qué cubre |
|---|---|---|
| Ruta | `DELETE` responde **403** sin tocar la base | El endpoint y la interfaz |
| Modelo | `SupportTicket` lanza en `deleting` | Cualquier camino de Eloquent: controlador, comando, job, acción masiva futura |
| Base de datos | `ON DELETE RESTRICT` en el historial | `where(...)->delete()`, que no pasa por Eloquent |

**No introduce el archivado.** Es el PR C del diseño y depende de **D-10**. Mezclar una
funcionalidad nueva con un correctivo de seguridad retrasa el correctivo.

**`tenant_id` del historial sigue en CASCADE**: dar de baja a un ISP se lleva su auditoría. Es
una decisión distinta, documentada y **no tomada** aquí.

### PR #4 · Ciclo de vida y reglas de cierre

| Campo | Detalle |
|---|---|
| **Objetivo** | Estados reales del cliente y cierre controlado |
| **Cubre** | F1-04, F1-10 |
| **Alcance** | Ampliar `ticket_status` a los 9 estados + auxiliares; tabla de transiciones; enforcement en `updateStatus()`; reglas de cierre; `restablecido_en` ≠ `cerrado_en` |
| **Dependencias** | **PR #3** (las excepciones deben quedar auditadas) · **Decisión D-03** |
| **Pruebas** | No se cierra sin causa confirmada, acción y resultado; la transición inválida se rechaza; la excepción queda auditada |
| **Aceptación** | El cierre incompleto queda bloqueado o exige excepción registrada |
| **Estado** | 🔒 Bloqueado por D-03 |
| **Riesgo** | **Medio** — cambia comportamiento vigente en producción |

### PR #5 · Intervenciones

| Campo | Detalle |
|---|---|
| **Objetivo** | Registrar N intervenciones por ticket con materiales y equipos |
| **Cubre** | F1-08, F1-12, F1-09 (base) |
| **Alcance** | Tabla de intervenciones (tipo, técnico, inicio/fin, hallazgo, acción, resultado, próximo paso) y equipos retirados/instalados |
| **Dependencias** | PR #3 |
| **Pruebas** | Un ticket admite varias intervenciones; cada una conserva su evidencia |
| **Aceptación** | Se registra una visita con técnico, hallazgo, acción, materiales y resultado |
| **Estado** | ⚪ Pendiente |

### PR #6 · Incidentes, duplicados y tickets relacionados

| Campo | Detalle |
|---|---|
| **Objetivo** | Correlacionar fallas comunes y evitar tickets repetidos |
| **Cubre** | F1-13, F1-14, F1-15 |
| **Alcance** | `parent_ticket_id`; aviso de tickets abiertos del mismo servicio; reincidencia 7/30/90 |
| **Dependencias** | PR #4 (el incidente tiene ciclo propio) |
| **Pruebas** | Al crear se avisa de tickets abiertos; N tickets se vinculan a un incidente; la reincidencia se calcula por ventana |
| **Aceptación** | Se crea un incidente padre y se le vinculan tickets individuales |
| **Estado** | 🔒 Bloqueado por PR #4 |

### PR #7 · Métricas y exportación

| Campo | Detalle |
|---|---|
| **Objetivo** | Tableros con percentiles y exportación filtrable |
| **Cubre** | F1-18, F1-19, F1-20 |
| **Alcance** | Mediana, P90 y P95; exportación de tickets; presentación en `America/Bogota` |
| **Dependencias** | PR #3 y PR #4 (los tiempos dependen de los timestamps) |
| **Pruebas** | Los percentiles coinciden con un conjunto conocido; la exportación respeta filtros |
| **Aceptación** | El tablero muestra mediana, P90 y P95, y se exporta filtrando por infraestructura |
| **Estado** | 🔒 Bloqueado por PR #3 y #4 |

---

## Decisiones pendientes del cliente

Ninguna debe resolverse por iniciativa propia.

| ID | Decisión | Por qué no la tomamos | Bloquea |
|---|---|---|---|
| **D-01** | **Servicio específico cuando un cliente tenga varios.** El requerimiento asume `service_id` propio (F2 criterio 3); el esquema actual impone 1 cliente = 1 servicio | Es un cambio de modelo de datos con impacto en facturación, aprovisionamiento e integración | F1-01, F2-03 |
| **D-02** | **Separación soporte / facturación.** El módulo excluye facturación pero hoy el ticket genera facturas | Funcionalidad viva que otros tenants podrían usar; retirarla es decisión de producto | F1-02 |
| **D-03** | **Autoridad para excepciones de cierre.** Quién puede cerrar sin causa confirmada y bajo qué registro | Es una regla operativa y de responsabilidad, no técnica | F1-10, PR #4 |
| **D-04** | **Significado de STI / STM / STS / STR / STN.** Si son campo, cálculo o etiqueta derivada | El cliente los describe como modalidad con atributos calculados, sin definir el mecanismo | F1-15, PR #6 |
| **D-05** | **Retención y hash de adjuntos.** El **acceso** quedó resuelto en el endurecimiento posterior al PR #2 (disco privado `s3`, endpoint autenticado por tenant y ticket). Sigue sin definirse cuánto se conservan y si llevan hash de integridad | Implica política de datos personales y valor probatorio de la evidencia | F1-11 |
| **D-06** | **Códigos de subcausa.** El Anexo A.2 enumera las subcausas en prosa («Señal baja; interferencia; saturación…») y **no les asigna código** | Los códigos son inmutables al sembrarse; improvisarlos fabricaría contrato. Se sembraron sólo las 7 familias, con las subcausas como texto de referencia en `description` | F1-03 completo, PR #2 |
| **D-07** | **¿Se expone al integrador?** Abarca ya tres cosas: los catálogos (PR #1), los cinco campos de diagnóstico (PR #2) y el historial del ticket (PR #3). Los tres viven sólo en la API del panel | Añadir ruta y campos bajo `/v1/partner` amplía el contrato público y obliga a actualizar el OpenAPI. El PR #2 deja un test que impide filtrarlos por descuido | F2-17, F2-18 |
| **D-08** | **Nombre de `ticket_solution` frente a «Acción».** El requerimiento dice acción; el esquema dice solución | Renombrar toca el esquema de la R1, ya desplegada. Los códigos oficiales no cambian en ningún caso | Claridad del diccionario de datos |
| **D-09** | **Modelo de roles de la sección 18.** El requerimiento define Recepción/N1, N2, Técnico de campo, Supervisor y Auditor/gerencia con capacidades distintas; ISPWatch sólo tiene `view_support`, que además hoy habilita lectura y escritura por igual | Partir el permiso afecta a todo el módulo y a los roles ya configurados por cada ISP. Es la mitad de F1-17 que el PR #3 no cubre | F1-17 completo |
| **D-10** | **¿Debe existir el archivado de tickets?** El documento no lo pide en ninguna parte; al contrario, trata el ticket como un expediente que se revisa «sin alterar». El PR A retiró el borrado físico; falta decidir si se sustituye por archivado reversible o por nada | Requiere confirmar quién archiva y quién restaura | PR C y PR D del diseño |
| **D-11** | **¿Quién cierra un ticket?** El documento sólo nombra «propuesta de cierre» (técnico de campo) y «cierre especial» (supervisor); el cierre ordinario no se asigna a ningún rol | Sin esto no se puede definir el permiso ni la regla de transición | PR #4, permiso `ticket_close` |
| **D-12** | **¿Existe la reapertura?** La palabra no aparece en el documento | La R1 declaró `resolved` y `closed` ambos terminales, así que reabrir sería una transición explícita a diseñar | PR #4, permiso `ticket_reopen` |
| **D-13** | **¿Quién administra los catálogos del ticket?** La sección 18 no lo asigna a ningún rol | Hoy cualquiera con `view_support` los lee; nadie los edita por interfaz | Permiso `ticket_manage_catalogs` |

---

## Registro de decisiones

| Fecha | Decisión | Fuente | Impacto | Estado |
|---|---|---|---|---|
| 2026-08-13 | Catálogos híbridos: 4 globales estrictos, 3 extensibles por tenant | Diseño Fase 1, aprobado por el equipo | Determina si un ISP puede añadir vocabulario propio | ✅ Aplicada (R1) |
| 2026-08-13 | Causa sospechada y confirmada **comparten catálogo**, con dos columnas | Diseño Fase 1 | Permite medir el acierto del diagnóstico externo | ✅ Aplicada (R1) |
| 2026-08-13 | `code` inmutable; `label` editable con efecto retroactivo | Diseño Fase 1 | Los tickets históricos nunca pierden su significado | ✅ Aplicada (R1) |
| 2026-08-13 | Transiciones **fuera** de la Fase 1; sólo los flags de semántica del estado | Diseño Fase 1 | Evita publicar una restricción que nadie aplica | ✅ Aplicada (R1) |
| 2026-08-13 | `resolved` y `closed` **ambos terminales**; reapertura como transición explícita | Diseño Fase 1 | Condiciona el modelo de PR #4 | ✅ Aplicada (R1) |
| 2026-08-14 | Catálogos de diagnóstico **vacíos** hasta acordar vocabulario | Diseño Fase 1 | Evita códigos inmutables equivocados | ✅ Aplicada (R1) · **revisable con el Anexo A** |
| 2026-08-15 | Despliegue en 3 pasos (R1+R2 → R2.5 → R3) | Auditoría del pipeline App Platform | Evita romper el contenedor viejo durante el despliegue | ✅ Aplicada |
| 2026-08-15 | La migración R3 **no aborta** por divergencia del espejo, sólo por FK sin resolver | Auditoría R3 | Un aborto por divergencia habría fallado sólo en producción | ✅ Aplicada (R3) |
| 2026-08-21 | El Anexo A se adopta como fuente del vocabulario de diagnóstico | `Solicitud_Maestra` Anexo A | Desbloquea PR #1 | ✅ Aplicada (PR #1) — **pendiente de confirmación del cliente** |
| 2026-08-21 | **No se inventan códigos de subcausa.** Se siembran las 7 familias; las subcausas quedan como texto de referencia | Anexo A.2 no les asigna código | Evita fijar contrato improvisado e inmutable | ✅ Aplicada (PR #1) · abre **D-06** |
| 2026-08-21 | Los síntomas se siembran con `category_id` en NULL | El Anexo A no relaciona síntomas con las categorías de ISPWatch | Evita embeber una decisión que depende de D-02 | ✅ Aplicada (PR #1) |
| 2026-08-21 | Insertar-si-falta en vez de upsert | La etiqueta es editable por diseño (R1) | Un upsert borraría un reetiquetado legítimo en cada despliegue | ✅ Aplicada (PR #1) |
| 2026-08-21 | El diagnóstico entra y sale **por código**, no por id | El módulo entero ya habla por código desde la R2 | Mezclar las dos formas obligaría a saber cuál toca en cada campo | ✅ Aplicada (PR #2) |
| 2026-08-21 | La validación usa el vocabulario **visible para el tenant**, no el catálogo completo | `codigosVigentes()` incluye filas privadas de otros ISP | El aislamiento tiene que valer al escribir, no sólo al listar | ✅ Aplicada (PR #2) |
| 2026-08-21 | Un código repetido entre ISPs resuelve a la fila **propia** | Los índices parciales de la R1 permiten el duplicado a propósito | Sin esto un ticket podía apuntar a vocabulario ajeno sin error visible | ✅ Aplicada (PR #2) |
| 2026-08-21 | El diagnóstico **no** se expone a socios en el PR #2 | El contrato vigente no lo exige y el OpenAPI no lo menciona | Ampliar el contrato público es decisión separada | ✅ Aplicada (PR #2) · **D-07** |
| 2026-08-21 | Los cinco campos siguen siendo **opcionales** | El PR #2 es captura, no reglas de cierre | Imponer obligatoriedad ahora bloquearía tickets en curso | ✅ Aplicada (PR #2) · se revisa en PR #4 |
| 2026-08-25 | El historial se escribe por **observer**, no desde el controlador | La pantalla de edición reenvía el formulario entero en cada guardado | Registrar el payload dejaría un evento por campo cada vez y el historial sería ilegible | ✅ Aplicada (PR #3) |
| 2026-08-25 | Los valores se guardan como **código estable**, no como id interno | Un id no significa nada fuera de esta instalación | El histórico sigue siendo legible aunque se resiembre el catálogo | ✅ Aplicada (PR #3) |
| 2026-08-25 | La **etiqueta del momento** se congela en `metadata` | Las etiquetas del catálogo son editables por diseño (R1) | Reetiquetar un catálogo no reescribe lo que el operador vio ese día | ✅ Aplicada (PR #3) |
| 2026-08-25 | De notas, adjuntos y cargos se guarda **referencia, no copia** | La nota es editable, el importe cambia y la ruta del adjunto es interna | Evita dos versiones divergentes y no filtra rutas del bucket | ✅ Aplicada (PR #3) |
| 2026-08-25 | **Tabla propia** en vez de `audit_logs` | `audit_logs` guarda JSON del modelo, está tras `view_audit_log` y no tiene FK al ticket | Consulta por campo sin recorrer JSON y visible para quien atiende el ticket | ✅ Aplicada (PR #3) |
| 2026-08-25 | **No se reconstruye historial retroactivo** | No existen los datos de lo ocurrido antes | Inventar eventos pasados sería falsificar una auditoría | ✅ Aplicada (PR #3) |

---

## Evidencias de validación

### Pull requests

| Release | PR | Enlace | Checks |
|---|---|---|---|
| R1 + R2 | #233 | `https://github.com/ispwatchcol/ISPWatch/pull/233` | ✅ SQLite + PostgreSQL |
| R2.5 | #235 | `https://github.com/ispwatchcol/ISPWatch/pull/235` | *(pendiente de registrar)* |
| R3 | #236 | `https://github.com/ispwatchcol/ISPWatch/pull/236` | ✅ tras `03136bd` |
| PR #1 | #250 | `https://github.com/ispwatchcol/ISPWatch/pull/250` | ✅ Mergeado y desplegado |
| PR #2 | #251 | `https://github.com/ispwatchcol/ISPWatch/pull/251` | ✅ Mergeado y desplegado |
| Endurecimiento notas/adjuntos | #252 | `https://github.com/ispwatchcol/ISPWatch/pull/252` | ✅ Mergeado y desplegado |
| PR #3 · historial | #253 | `https://github.com/ispwatchcol/ISPWatch/pull/253` | ✅ Mergeado y desplegado |
| PR A · impedir borrado físico | *(por asignar)* | *(abierto para revisión)* | — |

### Resultados de CI

| Fecha | Rama / PR | SQLite | PostgreSQL | Nota |
|---|---|---|---|---|
| 2026-08-15 | PR #236 | ✅ 47 s | ❌ → ✅ | Corregido en `03136bd` |
| *(pendiente)* | | | | |

### Validaciones de producción

| Fecha | Qué se validó | Resultado | Quién |
|---|---|---|---|
| 2026-08-20 | Esquema `public`: enums eliminados, catálogos presentes | ✅ 3 columnas ausentes; `ticket_status/priority/category` con 4 filas; catálogos de diagnóstico en 0 | — |
| 2026-08-21 | **Incidente**: la migración del PR #1 se aplicó por error al esquema `public` y se revirtió el mismo día | ✅ Revertido y verificado: 0 filas de vocabulario, versiones en 1, migración no registrada, `batch` máx. 89, 19 tickets intactos. Ver `BITACORA_TECNICA.md` §51 | — |
| 2026-08-21 | Causa raíz confirmada: **`DB_SCHEMA` comentado** en el `.env` local, que `config/database.php` resuelve por defecto a `public`. Corregido a `ispwatch_dev` | ✅ Verificado en tres niveles: `.env`, configuración resuelta y sesión viva (`current_schema() = ispwatch_dev`) | David Gómez |
| 2026-08-21 | Separación de esquemas tras el arreglo | ✅ `ispwatch_dev`: PR #1 aplicada, 58 filas · `public`: PR #1 **no** aplicada, **0 filas**, versiones en 1. Única migración divergente: la del PR #1, en desarrollo | — |
| *(pendiente)* | Flujo completo crear → cerrar en interfaz | | |
| *(pendiente)* | `GET /v1/partner/tickets` con llave real: códigos como cadena | | |

### Capturas y pruebas funcionales

*(espacio reservado — adjuntar al validar PR #1 y PR #2)*

### Aprobación del cliente

| Ítem | Fecha solicitud | Fecha respuesta | Estado |
|---|---|---|---|
| Matriz F1/F2 del Anexo B respondida | *(pendiente)* | | ⚪ No enviada |
| Contrato OpenAPI entregado | *(pendiente)* | | ⚪ No enviado |
| Códigos del Anexo A confirmados | *(pendiente)* | | ⚪ No solicitado |
| Reunión de la sección 40 | *(pendiente)* | | ⚪ No agendada |

---

## Historial de actualizaciones

| Fecha | Cambio | Responsable | Commit / PR |
|---|---|---|---|
| 2026-08-21 | Creación del documento. Auditoría documental, verificación de hashes (6/6), matriz F1-01..F1-20 y F2 aplicables, backlog PR #1-#7, decisiones D-01..D-05 | — | PR #248 |
| 2026-08-21 | **PR #1 implementado**: vocabulario de diagnóstico del Anexo A sembrado (58 códigos) y expuesto en el endpoint del panel. F1-03 sigue **parcial** —falta captura—. Nuevas decisiones D-06, D-07 y D-08 | — | *(sin commit)* |
| 2026-08-21 | Revisión final del PR #1: transcripción cotejada por programa contra el `.docx` (58/58 literales, 48 subcausas), 2 pruebas nuevas (forma de la respuesta y rechazo de llave de socio), migración validada en PostgreSQL real. Estado del PR #1 corregido a **implementado en local / pendiente de CI**. Registrado el incidente de aplicación accidental en `public` y su reversión | — | *(sin commit)* |
| 2026-08-21 | Verificación final: causa raíz confirmada en `DB_SCHEMA`, esquema local resuelto a `ispwatch_dev`, `public` intacto (0 filas, PR #1 sin registrar), brecha de migraciones entre esquemas cerrada. PR #1 listo para commit | David Gómez | *(sin commit)* |
| 2026-08-21 | **PR #1 mergeado y desplegado** (PR #250). Validado en producción: 16 síntomas, 7 familias, 20 acciones, 15 resultados y las cuatro versiones en 2 | David Gómez | PR #250 |
| 2026-08-21 | **PR #2 implementado**: captura del diagnóstico en alta, edición y detalle; validación por catálogo y por tenant; `diagnosis` con código y etiqueta. **F1-03 pasa a cumplido.** Sin migraciones. La API de socios no se toca (D-07). D-06 y D-08 siguen abiertas | — | *(PR abierto)* |
| 2026-08-23 | **PR #2 mergeado y desplegado** (PR #251). Humo en producción sobre el ticket #25: el diagnóstico funciona; aparecen dos regresiones previas ajenas a él | David Gómez | PR #251 |
| 2026-08-23 | **Endurecimiento posterior al PR #2**: corregido el 422 al guardar notas (el autor lo ponía el cliente) y la vista previa rota de adjuntos (disco efímero, sin `storage:link`, URL pública). Adjuntos movidos a `s3` y servidos por endpoint autenticado con verificación de tenant. Corregido de paso un 500 en `/v1/partner` con sesión del panel. **F1-11 sigue parcial**: falta hash y retención (D-05) | — | *(PR abierto)* |
| 2026-08-25 | **Endurecimiento mergeado y desplegado** (PR #252) | David Gómez | PR #252 |
| 2026-08-25 | **PR #3 implementado**: historial inalterable `support_ticket_history` con actor, campo, valor anterior/nuevo, origen y fecha; observer sobre el ticket; eventos de nota, adjunto y cargo; endpoint paginado de sólo lectura y sección «Historial» en el detalle (hora de Bogotá). **F1-17 sigue parcial**: falta el modelo de roles de la sección 18, registrado como **D-09**. F2-05 sigue parcial (D-07) | — | *(PR abierto)* |
| 2026-08-27 | **PR #3 mergeado y desplegado** (PR #253) | David Gómez | PR #253 |
| 2026-08-27 | **Auditoría de permisos y borrado**: seis hallazgos (H-1 a H-6). Diseño completo en `DISENO_PERMISOS_Y_ARCHIVADO.md` con matriz de roles §18, 20 permisos propuestos, comparación de enfoques de archivado y división en PRs A-E. Nuevas decisiones **D-10 a D-13** | — | *(sin commit)* |
| 2026-08-27 | **PR A implementado**: retirado el borrado físico de tickets (ruta 403, guard en el modelo y clave foránea `RESTRICT` en el historial); corregidos H-3 y H-4; botón «Eliminar» retirado de la interfaz. **H-6 queda abierto** (borrar un cliente destruye notas y adjuntos de sus tickets) como **P-42** | — | *(PR abierto)* |
