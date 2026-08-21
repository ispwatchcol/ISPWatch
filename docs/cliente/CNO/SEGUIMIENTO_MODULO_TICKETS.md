# Seguimiento del módulo de tickets — CNO

> **Documento vivo.** Se actualiza en cada release, decisión del cliente o cambio de
> alcance. No es un informe cerrado: la sección *Historial de actualizaciones* registra
> cada modificación.

| Campo | Valor |
|---|---|
| **Inicio del seguimiento** | 2026-08-21 |
| **Requerimiento analizado** | CNO-ISPWASH-ST-API · **V1.1** · 2026-08-10 |
| **Fuente documental** | [`docs/cliente/CNO/V1_1/`](V1_1/) — 6 archivos, integridad verificada |
| **Commit de preservación documental** | `7275e0f` — *«add: Documentación de los requerimientos del cliente…»* ⚠️ **no integrado en `main`** |
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
2. El commit de preservación `7275e0f` **no está en `main`**: vive sólo en la rama local
   `david-module-support-tickets` y no se ha empujado. La preservación existe, pero todavía
   no es visible para el resto del equipo.
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
  `confirmed_cause_id`, `solution_id`, `result_id`. **Sus catálogos están vacíos.**
- `closed_at` separado de `resolved_at`.
- Contrato **OpenAPI 3.0.3** oficial publicado y servido en `GET /v1/partner/openapi.yaml`.

### Parcialmente implementado

Existe **estructura** pero no **funcionalidad operativa**: las columnas de diagnóstico no
se capturan en ninguna pantalla ni se exponen por API; los adjuntos existen pero sin hash
ni control de acceso; las estadísticas calculan promedio pero no percentiles; la
asociación con infraestructura llega sólo hasta `sectorial_id`.

### Pendiente

El **expediente técnico** que motiva el requerimiento: ciclo de vida de 9 estados,
reglas de cierre, historial/auditoría, intervenciones múltiples, pruebas estructuradas,
incidentes padre, duplicados, reincidencias y exportación.

### Lo que NO debe declararse completado todavía

> **Ningún requisito F1 está cumplido en su criterio de aceptación.**

Concretamente, **no** debe reportarse como cumplido:

- **F1-03** por existir las cinco columnas. El criterio exige campos *capturables y
  consultables*; hoy son columnas vacías sin UI ni API.
- **F1-04** por existir `resolved_at` y `closed_at`. El criterio exige estados,
  transiciones **e historial**.
- **F1-11** por existir la tabla de adjuntos. El criterio exige metadatos, y hoy no hay
  hash ni protección de acceso.
- **F1-17** por existir `view_support`. El criterio exige modelo de roles **y auditoría no
  editable**, que no existe.
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
| **F1-03** | Síntoma, causa sospechada, causa confirmada, acción y resultado | 🟡 Parcial | 5 columnas en `support_ticket`; catálogos con **0 filas** | **PR #1** + **PR #2** | Anexo A ya aporta códigos |
| **F1-04** | Estados y transiciones con timestamps e historial | 🟡 Parcial | 4 estados vs 9 + 9 auxiliares (Maestra L139-149); `resolved_at`, `closed_at` | **PR #4** | **Decisión D-03** |
| **F1-05** | Campos condicionales radio / FTTH | ⚪ Pendiente | No existe | Diseño posterior | Tras PR #2 |
| **F1-06** | Asociación zona, nodo, AP/OLT, PON, CPE/ONU | 🟡 Parcial | `support_ticket.sectorial_id` | Ampliar jerarquía | — |
| **F1-07** | Snapshot histórico de infraestructura | ⚪ Pendiente | `sectorial_id` es FK viva, no snapshot | Diseño posterior | Tras F1-06 |
| **F1-08** | Varias intervenciones por ticket | ⚪ Pendiente | `support_ticket_message` son comentarios | **PR #5** | — |
| **F1-09** | Pruebas iniciales y finales estructuradas | ⚪ Pendiente | No existe | Tras PR #5 | — |
| **F1-10** | Reglas de cierre y excepciones auditadas | ⚪ Pendiente | Cualquier transición permitida | **PR #4** | **Decisión D-03** |
| **F1-11** | Adjuntos y evidencia con metadatos | 🟡 Parcial | `support_ticket_attachment`; disco `public` (`SupportTicketController.php:143,251`) | Revisar acceso y hash | **Decisión D-05** |
| **F1-12** | Materiales y equipos retirados/instalados | ⚪ Pendiente | Existe `installation_equipment`, para instalaciones | **PR #5** | — |
| **F1-13** | Detección de duplicados y tickets abiertos | ⚪ Pendiente | No existe | **PR #6** | — |
| **F1-14** | Reincidencias 7/30/90 días (P1) | ⚪ Pendiente | No existe | **PR #6** | — |
| **F1-15** | Incidente padre y tickets relacionados | ⚪ Pendiente | Sin `parent_ticket_id`; `router_outage_events` es base parcial | **PR #6** | — |
| **F1-16** | Servicios afectados y minutos-cliente (P1) | ⚪ Pendiente | No existe | Tras PR #6 | — |
| **F1-17** | Roles, permisos y auditoría | 🟡 Parcial | Sólo `view_support` (`Permissions.php:26`); **0 referencias a `AuditLog`** en `SupportTicketController` | **PR #3** | — |
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
| **F2-05** | Lectura de tickets **e historial** | 🟡 Parcial | `GET /v1/partner/tickets` (`routes/api.php:720`); sin detalle ni historial | **PR #3** habilita historial |
| **F2-07** | Comentarios, intervenciones y adjuntos por API | ⚪ Pendiente | Tablas existen, no expuestas | Tras PR #5 |
| **F2-09** | Webhooks firmados o incremental confiable | 🟢 Cumplido (alternativa) | Feed `/events` | Documentar como alternativa |
| **F2-12** | Errores estructurados, rate limits, reintentos | 🟢 Cumplido | 11 códigos estables; 60/min + 5 000/h | — |
| **F2-13** | Auditoría de operaciones API | 🟢 Cumplido | `api_key_request_logs` | — |
| **F2-16** | Credenciales separadas sandbox / producción | ⚪ Pendiente | No existe sandbox | Decisión de infraestructura |
| **F2-17** | Diccionario de campos, enums y códigos estables por API | 🟡 Parcial | `GET /api/catalogs/ticket` (`routes/api.php:528`); catálogos de diagnóstico vacíos | **PR #1** |
| **F2-18** | Los campos nuevos no quedan sólo en UI | 🟡 Parcial | Columnas existen, sin exposición | **PR #1** + **PR #2** |

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
| **Cubre** | F1-03 (parcial), F2-17, F2-18 |
| **Alcance** | Migración de siembra idempotente con los códigos del Anexo A: síntomas `S01`-`S16`, familias de causa `RF`/`FO`/`CL`/`AA`/`RE`/`EX`/`NF` con subcausas, acciones `AC01`-`AC20`, resultados `R01`-`R15`. Ampliar `CatalogController::ticketCatalogs()` y el OpenAPI. **Sin UI.** |
| **Dependencias** | Ninguna técnica. Los códigos vienen del cliente |
| **Pruebas** | Los códigos sembrados coinciden exactamente con el Anexo A; el endpoint los devuelve ordenados; la versión del catálogo sube; los catálogos siguen sin filas inventadas |
| **Aceptación** | `GET /api/catalogs/ticket` devuelve 16 síntomas, las 7 familias de causa, 20 acciones y 15 resultados con los códigos literales del Anexo A |
| **Estado** | ⚪ Listo para iniciar |
| **Riesgo** | Los códigos son **inmutables** al sembrarse. Si el cliente los cambia en la reunión de la sección 40, habría que retirarlos y crear nuevos. Mientras ningún ticket los referencie el coste es cero — por eso este PR **no** incluye captura |

### PR #2 · Captura del diagnóstico

| Campo | Detalle |
|---|---|
| **Objetivo** | Que el operador registre síntoma, causa sospechada, causa confirmada, acción y resultado |
| **Cubre** | F1-03 (completa), F2-18 |
| **Alcance** | Bloque «Diagnóstico» en `SupportDetail.vue` y `SupportEdit.vue`; validación contra catálogo vigente; exposición en la API de socios |
| **Dependencias** | **PR #1** + visto bueno del cliente sobre los códigos |
| **Pruebas** | Se registran y leen los cinco campos; se rechaza un código retirado; la API los devuelve como código estable |
| **Aceptación** | Un ticket puede registrar los cinco campos por separado y consultarse por cada uno |
| **Estado** | 🔒 Bloqueado por PR #1 |

### PR #3 · Historial y auditoría del ticket

| Campo | Detalle |
|---|---|
| **Objetivo** | Trazabilidad no editable de todo cambio |
| **Cubre** | F1-17 (auditoría), F2-05 (historial) |
| **Alcance** | Registro de cambios con actor, fecha, campo, valor anterior y nuevo; endpoint de historial |
| **Dependencias** | Ninguna |
| **Pruebas** | Todo cambio de estado, prioridad, asignación y diagnóstico deja traza; la traza no es editable desde la operación |
| **Aceptación** | Consultando un ticket se obtiene su cronología completa con actor y valores previos |
| **Estado** | ⚪ Listo para iniciar |
| **Nota** | Principio del cliente «historial inalterable» (Maestra L89). No es reconstruible retroactivamente |

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
| **D-05** | **Acceso, retención y protección de adjuntos.** Hoy se guardan en disco público sin autenticación ni hash | Implica política de datos personales y evidencia probatoria | F1-11 |

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
| 2026-08-21 | El Anexo A se adopta como fuente del vocabulario de diagnóstico | `Solicitud_Maestra` Anexo A | Desbloquea PR #1 | 🟡 Propuesta — requiere confirmación del cliente |

---

## Evidencias de validación

### Pull requests

| Release | PR | Enlace | Checks |
|---|---|---|---|
| R1 + R2 | #233 | `https://github.com/ispwatchcol/ISPWatch/pull/233` | ✅ SQLite + PostgreSQL |
| R2.5 | #235 | `https://github.com/ispwatchcol/ISPWatch/pull/235` | *(pendiente de registrar)* |
| R3 | #236 | `https://github.com/ispwatchcol/ISPWatch/pull/236` | ✅ tras `03136bd` |
| PR #1 | — | *(pendiente)* | — |

### Resultados de CI

| Fecha | Rama / PR | SQLite | PostgreSQL | Nota |
|---|---|---|---|---|
| 2026-08-15 | PR #236 | ✅ 47 s | ❌ → ✅ | Corregido en `03136bd` |
| *(pendiente)* | | | | |

### Validaciones de producción

| Fecha | Qué se validó | Resultado | Quién |
|---|---|---|---|
| 2026-08-20 | Esquema `public`: enums eliminados, catálogos presentes | ✅ 3 columnas ausentes; `ticket_status/priority/category` con 4 filas; catálogos de diagnóstico en 0 | — |
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
| 2026-08-21 | Creación del documento. Auditoría documental, verificación de hashes (6/6), matriz F1-01..F1-20 y F2 aplicables, backlog PR #1-#7, decisiones D-01..D-05 | — | *(sin commit)* |
