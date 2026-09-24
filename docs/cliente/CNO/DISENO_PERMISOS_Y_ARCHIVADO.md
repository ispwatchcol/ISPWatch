# Diseño · Permisos granulares y archivado de tickets

> **Estado:** aprobado conceptualmente el 2026-08-27; **confirmado por CNO el 2026-09-11** (§0).
> **PR A, H-6, P-43a, B y C implementados** (el D queda absorbido por el C); E pendiente.
> **Fuentes:** `docs/cliente/CNO/V1_1/Solicitud_Maestra_ISPwash_CNO_V1_1.docx` (secciones 18 y
> 19) · **Confirmación de CNO por chat — 11/09/2026** · `SEGUIMIENTO_MODULO_TICKETS.md` ·
> modelo de roles y permisos vigente · `SupportTicket`, `support_ticket_history` y rutas
> actuales.
>
> Este documento **no** modifica los originales del cliente en `V1_1/`.

---

## 0. Confirmación de CNO — 11/09/2026

> **Fuente:** Confirmación de CNO por chat — 11/09/2026.

CNO respondió a las decisiones pendientes y **delegó en el equipo el diseño operativo**. Lo que
sigue separa tres cosas que conviene no mezclar: lo que el cliente aprobó, lo que el equipo
tuvo que suponer para poder implementar, y lo que sigue sin definirse.

### Decisiones aprobadas por el cliente

| ID | Decisión de CNO | Efecto |
|---|---|---|
| **D-10** | **El archivado existe**: reversible y auditado, para **Administradores y Propietarios** | ✅ Resuelta. Desbloquea PR C y PR D |
| **D-06** | Las **subcausas se mantienen sólo como texto de referencia**; no se crean códigos individuales | ✅ **Cerrada.** Confirma lo que el PR #1 ya había hecho |
| **D-09** | Roles y permisos: **definirlos según la Solicitud Maestra**, con criterio de simplicidad y permitiendo cambios posteriores | Delegada. Ver supuesto **S-1** |
| **D-11** | Cierre: ídem, según la Solicitud Maestra | Delegada. Desbloquea PR #4 |
| **D-12** | Reapertura: ídem | Delegada. Desbloquea PR #4 |
| **D-13** | Administración de catálogos: ídem, va dentro de «roles y permisos» | Delegada |
| — | **Estados y transiciones** de la Solicitud Maestra: **confirmados** | Desbloquea PR #4 |
| — | **Evidencias accesibles** para quienes manejan tickets | Confirma el modelo del endurecimiento posterior al PR #2 |

**«Delegada» no es «resuelta».** El cliente autorizó al equipo a decidir; la decisión sigue
siendo nuestra y hay que dejarla escrita antes de implementarla. Por eso D-09, D-11, D-12 y
D-13 siguen en la tabla de §9 con el estado cambiado, no borradas.

### Supuestos técnicos del equipo

Ninguno de estos vino del cliente. Se registran aquí precisamente para que puedan rebatirse.

| ID | Supuesto | Por qué |
|---|---|---|
| **S-1** | **«Propietario» no es un rol de ISPWatch.** Los `code` reales son `admin`, `staff`, `technician`, `accounting` y `client` — verificado contra la base, cinco tenants, sin excepciones. «Administrador y Propietario» se implementa como **`code = 'admin'` más el superadministrador global (`role_id == 1`)** | Inventar un rol `owner` habría fabricado un concepto nuevo sin respaldo ni en el requerimiento ni en el esquema. Si CNO llamaba «Propietario» a otra cosa —el dueño del ISP, una figura contractual—, hay que aclararlo **antes** del PR E |
| **S-2** | Archivar y restaurar se gobiernan con `ticket_archive` y `ticket_restore`, ya declarados por el PR B | Se declararon previendo esto y quedaron sin uso. El PR C los activa; no hay permisos nuevos |
| **S-3** | Ver el listado de archivados **no** recibe permiso propio: basta cualquiera de los dos anteriores | Simplicidad, que es el criterio que pidió el cliente. Separar `ticket_view_archived` es trivial si el PR E lo necesita para un rol Auditor que mire sin tocar |
| **S-4** | «Archivado» y *soft delete* son la misma cosa en la implementación, pero **la palabra «eliminar» no aparece en la interfaz** | El requerimiento trata el ticket como expediente. El vocabulario importa tanto como el comportamiento: lo que el operador cree que hizo determina lo que hará después |

### Pendiente: retención de evidencias

CNO **no definió la retención** y dio una instrucción explícita: **no purgar ni borrar
automáticamente**.

Consecuencia directa para el PR C: **archivar un ticket no toca un solo archivo del bucket.**
Los adjuntos siguen donde están, con su endpoint autenticado, y siguen siendo consultables
desde el expediente archivado. **D-05 sigue abierta** en su mitad de retención y hash.

---

## 1. Hallazgos de la auditoría

| # | Hallazgo | Gravedad | Estado |
|---|---|---|---|
| **H-1** | `DELETE /api/support/{id}` exigía **`view_support`** — el mismo permiso que *leer*. Quien podía ver un ticket podía destruirlo. Los roles `Tecnico` y `Staff` de todos los tenants lo tienen | 🔴 | ✅ **Cerrado (PR A)** |
| **H-2** | `destroy()` borraba físicamente y, desde el PR #3, `support_ticket_history` cuelga del ticket con `ON DELETE CASCADE`: borrar un ticket **destruía su auditoría** | 🔴 | ✅ **Cerrado (PR A)** |
| **H-3** | `destroy()` borraba adjuntos de `Storage::disk('public')`, pero el PR #252 los movió a `s3`. No borraba el objeto del bucket y sí la fila que decía dónde estaba: **archivos huérfanos con datos del cliente** | 🟠 | ✅ **Cerrado (PR A)** |
| **H-4** | `invoices.ticket_id` es `nullOnDelete()`: borrar el ticket dejaba el **cargo huérfano**, sin origen rastreable | 🟠 | ✅ **Cerrado (PR A)** |
| **H-5** | La única barrera era un `confirm()` del navegador en `Support.vue` | 🟠 | ✅ **Cerrado (PR A)** |
| **H-6** | `support_ticket_message.user_id` y `support_ticket_attachment.user_id` eran **`ON DELETE CASCADE` sobre `users`**. `CustomerDeletionService` hace `$user->delete()`: **borrar un cliente destruía las notas y adjuntos de todos sus tickets**, mientras el ticket sobrevivía con `user_id = NULL` | 🔴 | ✅ **Cerrado (PR H-6)** |

**H-6 se descubrió durante el PR A** y se corrigió en un PR aparte, porque cambiar esas dos
claves foráneas afecta al flujo de baja de clientes, que tiene su propio servicio y sus propias
pruebas. Ver §4bis.

### Lo que sigue cascadeando desde `users` y **no** se tocó

El inventario completo de claves foráneas hacia `users` encontró once en cascada. Sólo las dos
del expediente del ticket entraban en alcance. Las demás se dejan documentadas:

| Tabla | Columna | Efecto de dar de baja a un cliente | ¿Problema? |
|---|---|---|---|
| `invoices` | `customer_id` | **Borra sus facturas**, incluidos los cargos de ticket | 🔴 **Sí** — es contabilidad, y merece su propio análisis |
| `payments` | `customer_id` | Borra sus pagos | 🔴 Mismo caso |
| `invoice_carryovers` | `customer_id` | Borra sus arrastres | 🟠 Depende de facturas |
| `customer_profile`, `user_services`, `customer_documents`, `customer_credits`, `customer_additional_services` | varias | Borran la ficha comercial | ✅ Correcto: es lo que se está dando de baja |
| `staff_profile` | `user_id` | Borra el perfil de empleado | ✅ Correcto |
| `billing_action_logs`, `suspension_action_logs` | `customer_id` | Borran bitácoras operativas | 🟠 Revisable |

`invoices.customer_id` es la más seria y **queda abierta**: dar de baja a un cliente borra su
histórico de facturación. No se tocó aquí porque no es parte del expediente del ticket y toca
contabilidad. Anotado como **P-43**.

---

## 2. Modelo de roles de la sección 18

El documento titula la columna **«Rol *orientativo*»** — el propio cliente los da como
indicativos, no vinculantes. Podemos proponer nomenclatura; no darla por cerrada.

| Rol | Capacidades **explícitas** (texto literal del documento) | Lo que **se supone** y el documento no dice |
|---|---|---|
| **Recepción / N1** | «Crear, clasificar, registrar pruebas básicas, vincular duplicados y escalar» | Si puede editar un ticket ajeno · si ve evidencias de otros · si puede cerrar |
| **N2 / soporte técnico** | «Diagnóstico avanzado, causa sospechada, intervenciones remotas y asociación de infraestructura» | Si puede *confirmar* causa (el doc se la da al Supervisor) · si cierra · si reasigna |
| **Técnico de campo** | «Visita, evidencias, materiales, equipos, pruebas finales y **propuesta** de cierre» | «Propuesta» sugiere que **no cierra él**, pero no se dice quién aprueba · si ve tickets ajenos |
| **Supervisor** | «Prioridad, reasignación, confirmación de causa, excepciones, cierre especial, incidentes masivos» | Si «cierre especial» es el único cierre o sólo el excepcional · si administra catálogos |
| **Auditor / Gerencia** | «Consulta, exportación, tableros, trazabilidad y **revisión sin alterar el expediente**» | Si puede descargar evidencia · si su alcance es un tenant o varios |

**Vacíos totales del documento** (ninguna mención, en ninguna sección):

- **Reapertura** de un ticket cerrado — no aparece la palabra.
- **Archivar, anular o eliminar** un ticket — **no aparece en todo el documento**. Ver §4.
- **Administración de catálogos** — no se asigna a ningún rol.
- **Cierre ordinario** — sólo se nombran «propuesta de cierre» (campo) y «cierre especial».

**Estado actual del sistema:** 5 roles por tenant (`Administrador`, `Contabilidad`, `Tecnico`,
`Staff`, `Cliente`), ninguno equivalente a los cinco de la sección 18. `Administrador` tiene 38
permisos; `Tecnico` entre 7 y 8, con `view_support` en unos tenants y no en otros — ya hay
deriva entre sedes.

---

## 3. Matriz de permisos propuesta

20 permisos bajo el prefijo `ticket_`. `view_support` se conserva por compatibilidad.

| # | Acción | Permiso | N1 | N2 | Campo | Superv. | Auditor | ¿Decide el cliente? |
|---|---|---|---|---|---|---|---|---|
| 1 | Ver listado/detalle | `ticket_view` | ✅ | ✅ | ⚠️ | ✅ | ✅ | ⚠️ Campo: ¿sólo los suyos? |
| 2 | Crear | `ticket_create` | ✅ | ✅ | ❌ | ✅ | ❌ | — |
| 3 | Editar contenido | `ticket_edit` | ⚠️ | ✅ | ❌ | ✅ | ❌ | ⚠️ N1 sobre ticket ajeno |
| 4 | Asignar / reasignar | `ticket_assign` | ⚠️ | ⚠️ | ❌ | ✅ | ❌ | ⚠️ ¿N1 al escalar? |
| 5 | Cambiar prioridad | `ticket_set_priority` | ❌ | ❌ | ❌ | ✅ | ❌ | — (explícito) |
| 6 | Cambiar categoría | `ticket_set_category` | ✅ | ✅ | ❌ | ✅ | ❌ | ⚠️ «clasificar» = categoría, se asume |
| 7 | Editar diagnóstico | `ticket_diagnose` | ❌ | ✅ | ⚠️ | ✅ | ❌ | ⚠️ Campo registra «pruebas finales» |
| 8 | Confirmar causa | `ticket_confirm_cause` | ❌ | ❌ | ❌ | ✅ | ❌ | — (explícito) |
| 9 | Agregar notas | `ticket_note` | ✅ | ✅ | ✅ | ✅ | ❌ | ⚠️ ¿Auditor comenta sin alterar? |
| 10 | Adjuntar evidencia | `ticket_attach` | ✅ | ✅ | ✅ | ✅ | ❌ | — |
| 11 | Ver/descargar evidencia | `ticket_view_evidence` | ✅ | ✅ | ✅ | ✅ | ⚠️ | ⚠️ ¿«exportación» incluye archivos? |
| 12 | Ver historial | `ticket_view_history` | ⚠️ | ✅ | ⚠️ | ✅ | ✅ | ⚠️ ¿N1 y campo ven la auditoría? |
| 13 | Cambiar estado | `ticket_transition` | ✅ | ✅ | ⚠️ | ✅ | ❌ | ⚠️ ¿«propuesta» = estado propio? |
| 14 | Cerrar | `ticket_close` | ❌ | ⚠️ | ❌ | ✅ | ❌ | 🔴 **Sin definir quién cierra** |
| 15 | Cerrar con excepción | `ticket_close_override` | ❌ | ❌ | ❌ | ✅ | ❌ | — (explícito, criterio 9) |
| 16 | Reabrir | `ticket_reopen` | ❌ | ❌ | ❌ | ✅ | ❌ | 🔴 **No existe en el documento** |
| 17 | Archivar / anular | `ticket_archive` | ❌ | ❌ | ❌ | ⚠️ | ❌ | 🔴 **No existe en el documento** |
| 18 | Restaurar | `ticket_restore` | ❌ | ❌ | ❌ | ⚠️ | ❌ | 🔴 **No existe en el documento** |
| 19 | Administrar catálogos | `ticket_manage_catalogs` | ❌ | ❌ | ❌ | ⚠️ | ❌ | 🔴 **Sin asignar** |
| 20 | Métricas / exportar | `ticket_export` | ❌ | ❌ | ❌ | ✅ | ✅ | — (explícito) |

✅ propuesto · ❌ propuesto denegar · ⚠️ requiere confirmación

---

## 3ter. Permiso 21 · `ticket_intervene` (PR F1 · 2026-09-23)

La matriz de la § 3 se cerró con 20 permisos. El PR F1 añade el **21**.

| # | Acción | Permiso | N1 | N2 | Campo | Superv. | Auditor |
|---|---|---|---|---|---|---|---|
| 21 | Registrar y gestionar intervenciones | `ticket_intervene` | ⚠️ | ✅ | ✅ | ✅ | ❌ |

### Por qué un permiso propio y no `ticket_edit`

La § 18 le da al **Técnico de campo** «visita, evidencias, materiales, equipos, pruebas
finales y propuesta de cierre». Pero la matriz de la § 3 le **niega** `ticket_edit` (fila 3).

Si la intervención viajara dentro de `ticket_edit`, dárselo al técnico de campo le daría de
paso editar asunto, categoría y asignación — justo lo que ese rol no debe tocar. Y no dárselo
lo dejaría sin poder registrar la visita, que es su función principal. El permiso separado es
lo que permite cumplir las dos mitades de la frase del documento.

Lo que **no** necesita permiso nuevo, porque ya existe:

| Capacidad | Permiso reutilizado |
|---|---|
| Adjuntar la evidencia de la visita | `ticket_attach` |
| Ver o descargar esa evidencia | `ticket_view_evidence` |
| Leer las intervenciones | `ticket_view` |
| Proponer el cierre tras la visita | `ticket_transition` (sin cambios) |

`N2` lo recibe porque la § 18 le da «intervenciones remotas» de forma explícita. `N1` queda en
⚠️: el documento no le asigna intervenciones, pero tampoco se las niega. `Auditor` en ❌, por
«revisión **sin alterar** el expediente».

### El backfill, y la lección de P-52

`ticket_close_override`, `ticket_reopen` y `ticket_manage_catalogs` se declararon en el PR B
sin concedérselos a nadie. El resultado está anotado como **P-52**: el cierre con excepción
quedó inalcanzable por cualquier vía. **Un permiso declarado y no repartido no es una
capacidad nueva, es una función muerta.**

Por eso el reparto va en el mismo PR que la funcionalidad:

```
2026_09_23_000003_grant_ticket_intervene_to_support_roles.php
```

Concede `ticket_intervene` a **todo rol que hoy tenga `ticket_attach`**. Es el conjunto más
cercano a «quien atiende el ticket sobre el terreno»: evidencia e intervención salen de la
misma frase de la § 18, así que quien ya podía adjuntar es exactamente quien debe poder
registrar la visita.

No se usa `view_support` como criterio —que es lo que hizo el PR B— porque desde aquel
backfill los permisos granulares ya existen, y `ticket_attach` describe mejor la capacidad
real que el permiso heredado.

Idempotente: sólo añade lo que falta, no reordena, salta los roles con comodín `*` y recorre
`role` fila a fila porque `permissions` es JSON y los operadores difieren entre PostgreSQL y
SQLite. El `down()` retira sólo lo que concedió, lo que es seguro porque el permiso nace aquí.

### Lo que este permiso NO gobierna

**Borrar una intervención.** No existe esa capacidad, para ningún rol, por ninguna vía. El
§ 15.10 exige que el cierre no borre las intervenciones, así que la tabla no tiene
`deleted_at`, no hay endpoint, el modelo bloquea `deleting` y la pantalla no tiene botón.

Corregir una visita finalizada exige **reabrirla** con motivo de 10 a 500 caracteres, lo que
deja `intervention_reopened` en el historial con actor, fecha y motivo. Quien puede reabrir es
quien puede intervenir: separar un `ticket_intervene_reopen` habría multiplicado la matriz sin
que el documento lo pida, y la reapertura ya es auditada, que es la garantía que importa.

---


## 3quater. Permiso 22 · `ticket_equipment` (PR F3 · 2026-09-24)

El PR F3 añade el **22**, y cierra la otra mitad de la frase de la § 18.

| # | Acción | Permiso | N1 | N2 | Campo | Superv. | Auditor |
|---|---|---|---|---|---|---|---|
| 22 | Entregar y retirar equipos en la visita | `ticket_equipment` | ⚠️ | ✅ | ✅ | ✅ | ❌ |

### Por qué otro permiso, habiendo ya tres candidatos

La § 18 le da al Técnico de campo «visita, evidencias, **materiales, equipos**, pruebas finales
y propuesta de cierre». El permiso 21 cubrió «visita» y «evidencias»; éste cubre «materiales» y
«equipos». Los tres candidatos que ya existían fallaban, cada uno por su lado:

| Candidato | Por qué no |
|---|---|
| `ticket_edit` | La matriz de la § 3 se lo **niega** al Técnico de campo (fila 3). Reusarlo dejaba la sección existiendo para todos menos para quien tiene que usarla — y dársela le daría de paso asunto, categoría y asignación |
| `ticket_intervene` | Describe **relatar** la visita. Mover un aparato descuenta existencias y cambia la custodia de un bien: un ISP puede querer que su técnico cuente lo que hizo sin autorizarle a sacar equipos de la bodega |
| `view_support` | Es de **lectura**. La primera versión lo usó en un OR y el resultado fue que un permiso de lectura autorizaba mover inventario |

### La trampa del OR, que es lo que obligó a rehacerlo

`CheckPermission` tiene semántica **OR**, documentada en su propio docblock:
`permission:a,b` deja pasar a quien tenga **cualquiera** de los dos.

Las rutas nacieron con `permission:view_support,ticket_edit`, pensando «hace falta ver soporte
**y** poder editar». Lo que decían de verdad era «basta con `view_support`». Y a la vez la
pantalla exigía `ticket_edit` a secas, con lo que backend y frontend discrepaban **en
direcciones opuestas**: la API dejaba pasar a quien no debía, y la interfaz escondía el bloque
justo a quien el documento se lo asigna.

Regla que queda: **para gobernar una escritura, un solo permiso**. El OR es para datos de
referencia que una pantalla necesita aunque el permiso dueño no sea suyo.

### Reparto

`2026_09_24_000001_grant_ticket_equipment_to_intervene_roles.php` concede `ticket_equipment` a
todo rol que ya tenga `ticket_intervene`. Es el conjunto de quien atiende en campo, que es el
mismo del que sale el permiso 21 — coinciden **hoy**, aunque signifiquen cosas distintas.

No llega a `client` ni a `accounting`, y no por una exclusión escrita: ninguno de los dos
interviene. Idempotente, no toca los roles con comodín `*`, y el `down()` retira sólo lo que
concedió.

**Con backfill en el mismo PR**, por P-52: un permiso nuevo sin reparto no es una capacidad
nueva, es una función muerta.

### Lo que este permiso NO gobierna

La regla de **de dónde** puede tomar cada quien —lo suyo, lo del técnico asignado a la visita,
las bodegas sólo con permiso de inventario— la sigue aplicando `InventoryLedger`, no la ruta.
La ruta dice **quién** entra; el ledger dice **de dónde** puede sacar. Un técnico con
`ticket_equipment` pero sin `view_inventory` sigue sin poder tomar de una bodega.

## 3bis. PR B · Transición a los permisos granulares — **implementado**

Los 20 permisos existen y **cada ruta de ticket exige el suyo**. Lo que **no** se hace aquí es
repartir los roles de la sección 18: esa matriz es **D-09** y sigue pendiente del cliente.

### El mapa que había antes

| Endpoint | Protección anterior | Ahora |
|---|---|---|
| `GET /support`, `GET /support/{id}` | `view_support` | `ticket_view` |
| `POST /support` | `view_support` | `ticket_view` + `ticket_create` |
| `PUT /support/{id}` | `view_support` | `ticket_view` + **autorización por campo** |
| `GET /support/{t}/attachments/{a}` (+`/download`) | `view_support` | `ticket_view_evidence` |
| `GET /support/{t}/history` | `view_support` | `ticket_view_history` |
| `GET /api/catalogs/ticket` | **ninguna** | `ticket_view` |
| `POST /support/{id}/message`, `PUT`/`DELETE /support/messages/{id}` | **sólo `staff_profile`** | + `ticket_note` |
| `PATCH /support/{id}/status` | **sólo `staff_profile`** | + `ticket_transition` (+ `ticket_close` al cerrar) |
| `GET /support/statistics` | **sólo `staff_profile`** | + `ticket_export` |
| `POST`/`GET /support/{id}/charge(s)` | **sólo `staff_profile`** | **sin cambios** — ver abajo |
| `DELETE /support/{id}` | `view_support` | sin cambios: responde 403 (PR A) |

**`view_support` NO se retira.** Gobierna también instalaciones, sectoriales e inventario
(unas 25 rutas), y sigue siendo la llave de compatibilidad durante la transición.

### Autorización por campo en `PUT /support/{id}`

Ese endpoint hace seis cosas y no se puede mapear a un solo permiso. La ruta exige
`ticket_view` —hay que poder ver un ticket para tocarlo— y el controlador comprueba cada campo:

| Campo de la petición | Permiso |
|---|---|
| `subject`, `description`, `sectorial_id` | `ticket_edit` |
| `staff_id` | `ticket_assign` |
| `priority` | `ticket_set_priority` |
| `category` | `ticket_set_category` |
| `symptom`, `suspected_cause`, `solution`, `result` | `ticket_diagnose` |
| `confirmed_cause` | `ticket_confirm_cause` |
| `status` | `ticket_transition`, y `ticket_close` si el destino es `closed` |
| adjuntos | `ticket_attach` |

**Sólo se exige el permiso si el valor CAMBIA.** La pantalla de edición reenvía el formulario
entero en cada guardado; exigir todos los permisos por el mero hecho de que el campo venga en
la petición rompería la pantalla para cualquiera que no los tuviera todos.

Al **crear**, `ticket_create` cubre el asunto, la categoría y la asignación inicial —son el
acto de abrir el ticket—, pero diagnosticar y adjuntar exigen su permiso también ahí: si no,
quien no puede diagnosticar un ticket existente lo haría colando los campos en el alta.

### El algoritmo del backfill

`2026_09_11_000001_backfill_granular_ticket_permissions`. El principio es que **nadie gane ni
pierda nada** con el despliegue, así que el reparto no se inventa: se deduce de las dos puertas
que gobernaban las rutas.

| Paso | Condición del rol | Recibe |
|---|---|---|
| 1 | Tiene `*` | **nada** — ya lo tiene todo |
| 2 | Tiene `view_support` | `ticket_view`, `create`, `edit`, `assign`, `set_priority`, `set_category`, `diagnose`, `confirm_cause`, `attach`, `view_evidence`, `view_history` (11) |
| 3 | `code` ∈ {`admin`, `staff`} | además `ticket_note`, `transition`, `close`, `export` (4) |
| 4 | Ni lo uno ni lo otro | **nada** |

El paso 3 mira el **código de rol** y no `view_support`, porque `staff_profile` es una puerta
independiente: un rol `staff` sin `view_support` sí puede anotar hoy.

**No se concede a nadie** `ticket_close_override`, `ticket_reopen`, `ticket_archive`,
`ticket_restore` ni `ticket_manage_catalogs`: esas acciones **no existen todavía** en el
sistema. Concederlas sería dar capacidades nuevas, justo lo contrario de una transición
compatible.

### Efecto por rol, verificado en SQLite y PostgreSQL

| Rol | `code` | Permisos hoy | `ticket_*` que recibe | ¿Cambia lo que puede hacer? |
|---|---|---|---|---|
| Administrador | `admin` | `view_support` + otros | **15** | No |
| Staff | `staff` | `view_support` + otros | **15** | No |
| Tecnico (con `view_support`) | `technician` | `view_support` | **11** | No — nunca pudo anotar ni transicionar |
| Tecnico (sin `view_support`) | `technician` | — | **0** | No |
| Contabilidad | `accounting` | `view_billing` | **0** | No |
| Cliente | `customer` | — | **0** | No |
| Rol con `*` | cualquiera | `*` | **0** (no se toca) | No |

### Lo que queda fuera

**Los cargos** (`POST`/`GET /support/{id}/charge(s)`) se quedan con `staff_profile` a secas.
Son facturación, no operación del ticket: no aparecen en la matriz de permisos del
requerimiento, y atarlos a uno de facturación —`view_billing`— se lo quitaría a roles que hoy
sí pueden generarlos. Hay un test que fija que son **la única** excepción conocida.

## 4. Archivado / anulación

> **Aprobado por CNO el 2026-09-11** (§0): archivado reversible y auditado, para Administradores
> y Propietarios. Lo que sigue era la propuesta y se conserva íntegra, porque el razonamiento no
> cambió al aprobarse.

### La observación que va primero

**El documento no pide borrar ni archivar tickets. En ninguna parte.** Dice lo contrario, y
repetidamente:

> «expediente técnico estructurado» · «revisión **sin alterar el expediente**» · «el cierre no
> debe borrar la causa sospechada, las intervenciones ni los estados anteriores» · «los estados
> y timestamps se conservan **sin sobrescritura**» · «ISPwash será el **único expediente y
> consecutivo oficial**»

Y el proyecto ya tiene un idioma coherente con eso: **el dinero no se borra, se anula por
estado** (`Expense::STATUS_VOID = 'anulado'`, facturas `void`/`cancelled`).

Por tanto la recomendación no fue «añadir archivado» sino **retirar la capacidad de borrar**.
El `DELETE` era la anomalía, no la funcionalidad que faltaba. Eso es lo que hizo el PR A.

### Comparación de enfoques

| Enfoque | A favor | En contra |
|---|---|---|
| **Soft delete (`deleted_at`)** | `restore()`, `withTrashed()`, `onlyTrashed()` gratis; exclusión automática de listados | El *global scope* oculta filas **en silencio y en todas partes**; hay que auditar cada lectura que **sí** debe ver el archivado |
| **Anulación por estado** | Idioma que el proyecto ya usa para dinero; nada se oculta solo, cada consulta decide | Hay que filtrar a mano en cada listado — se olvida con facilidad |
| **Sin retirada** | Máxima integridad | No hay forma de sacar de la vista un ticket abierto por error |

**Recomendación: soft delete con `deleted_at`**, con dos condiciones:

1. El concepto de cara al usuario es **«archivado»**, nunca «eliminado». El expediente no
   desaparece.
2. Auditar explícitamente cada punto de lectura que **debe** seguir viendo el archivado: el
   endpoint de historial, `invoices.ticket_id` y las estadísticas. Este código ya se quemó con
   ocultamientos silenciosos (bitácora §51).

### Esquema (PR C) — **implementado el 2026-09-13**

```
ALTER TABLE support_ticket ADD:
  deleted_at       timestamp NULL
  archived_by      bigint NULL  FK users(id) ON DELETE SET NULL
  archived_reason  varchar(500) NULL
```

No se añade `archived_at`: sería redundante con `deleted_at`.

| Aspecto | Decisión |
|---|---|
| Motivo | **Obligatorio**, 10–500 caracteres. Sin motivo no hay archivado |
| Evento | `ticket_archived` en `support_ticket_history`, motivo en `metadata`, actor del servidor. **Se escribe antes** del soft delete |
| Restauración | Exige `ticket_restore` **y motivo propio**; evento `ticket_restored` |
| Listados | Excluidos por defecto. Vista «Archivados» sólo con permiso |
| API de socios | Excluidos **siempre**, con `whereNull('deleted_at')` explícito y test |
| Notas, adjuntos, cargos | **Intactos**: no hay borrado físico, nada cascadea |
| Historial | **Intacto y consultable** — es cuando más importa |
| Adjuntos en `s3` | **No se borran.** Su retención es **D-05** |
| Cargos | Un ticket con factura no anulada **no debería poder archivarse** |

### Lo que el PR C implementó, y en qué se apartó de la propuesta

La propuesta se mantuvo entera. Tres cosas se concretaron al implementarla:

| Punto | Propuesta | Implementado |
|---|---|---|
| Estados bloqueados | «No archivar `open` ni `in_progress`» | **Se archiva si es duplicado o error de registro**, con `reason_code` y una confirmación adicional. Un bloqueo absoluto habría dejado sin salida el caso que motivó todo esto: el ticket abierto por error |
| Doble confirmación | Estaba asignada al PR D, como requisito de interfaz | **Se validó también en el servidor** (`confirm_ticket_id`). Una barrera que sólo vive en el navegador la salta un `curl` |
| Vocabulario | «El concepto de cara al usuario es archivado» | Además **`deleted_at` se ocultó del JSON** y el contrato expone `archived_at` e `is_archived`. La palabra no entra en la API, no sólo en los botones |

**Los cuatro puntos de lectura que el diseño obligó a auditar** —detalle, historial, cargos y
adjuntos— usan `withTrashed()` y **sólo** para quien tiene `ticket_archive` o `ticket_restore`.
Para el resto responden 404, no 403: quien no puede ver archivados tampoco debe poder deducir
que ese ticket existe.

**La exclusión en `/v1/partner` se escribió dos veces a propósito:** el scope de `SoftDeletes`
ya la aplica, y encima hay un `whereNull('support_ticket.deleted_at')` explícito con un test que
lo fija. El contrato del integrador está congelado y no debería depender de que nadie añada un
`withTrashed()` por descuido.

---

## 4quater. La otra mitad de la regla: la factura del ticket — 2026-09-19

El PR C impide archivar un ticket que tenga **una factura sin anular**. El humo en producción
destapó que esa regla se apoyaba en algo que no existía: **no había forma ordenada de anular una
factura**, y sí había una de destruirla.

| | Antes | Ahora |
|---|---|---|
| Borrar una factura emitida | `DELETE` detrás de `delete_invoice` — se llevaba número, ítems y el vínculo con el ticket | **422.** Sólo se borra un borrador sin número y sin ticket, que el sistema no genera |
| Anular | `PUT` con `status: cancelled` detrás de **`view_billing`**, un permiso de LECTURA. Sin motivo ni auditoría | `POST /billing/invoices/{id}/void` con **`invoice_void`**, motivo de 10–500 y evento en `audit_logs` |
| Factura anulada | Editable como cualquier otra | **Sólo lectura** |

**Qué significa para el archivado de tickets.** La secuencia que el PR C describe ya se puede
recorrer entera: si el cargo no procede, se **anula** —conservando número, importe y el vínculo
con el ticket— y entonces el ticket se archiva. Antes el operador sólo tenía dos salidas, y las
dos malas: borrar la factura, o dejar el ticket sin archivar.

**No cambia la semántica de los cargos de ticket.** `POST /support/{id}/charge` sigue generando
la misma factura `service_charge`, con el mismo permiso —`staff_profile` a secas, que sigue
siendo **P-44**— y el mismo evento `charge_created` en el historial. Lo único que cambia es qué
se puede hacer con esa factura después.

**Y no toca las claves foráneas de P-43.** `invoices.customer_id` y `invoices.ticket_id` siguen
en `SET NULL` exactamente como quedaron.

---

## 4quinquies. El workflow formal — 2026-09-19

CNO confirmó los estados y transiciones el 11/09/2026 y delegó la definición operativa del
cierre, las excepciones y la reapertura. Esto la implementa.

### Lo que activa de los permisos ya declarados

El PR B declaró veinte permisos y dejó cinco **sin uso** porque su acción no existía. El PR C
activó dos (`ticket_archive`, `ticket_restore`). Éste activa dos más:

| Permiso | Antes | Ahora |
|---|---|---|
| `ticket_close` | Comprobado dentro de `PATCH .../status` cuando el destino era `closed` | Endpoint propio `POST .../close`, con los requisitos del §15 |
| `ticket_close_override` | **Sin uso** | `POST .../close-exception` |
| `ticket_reopen` | **Sin uso** | `POST .../reopen` |
| `ticket_transition` | Movía el estado a cualquier sitio, también desde el `PUT` | Sólo por transición válida, con estado origen comprobado |

Queda **uno solo sin uso**: `ticket_manage_catalogs` (**D-13**).

**Ninguno se concede por migración.** `ticket_close_override` y `ticket_reopen` los recibieron
los roles que ya tenían `*`, y nadie más: conceder una capacidad que antes no existía sería lo
contrario de una transición compatible. Asignarlos es configuración del cliente.

### Supuestos nuevos

| ID | Supuesto | Por qué |
|---|---|---|
| **S-5** | **La propuesta de cierre deja el ticket en «En observación».** El documento no nombra un estado de «propuesto» | `EN OBSERVACIÓN` es el estado que el diagrama coloca inmediatamente antes de CERRADO, y es donde el ticket espera la decisión del supervisor. Inventar un estado nuevo habría añadido vocabulario que el documento no pide |
| **S-6** | **`Duplicado` es terminal.** El §7 lo lista como auxiliar sin decir si cierra | La alternativa —dejarlo abierto para siempre— ensucia toda métrica de pendientes. Si CNO prefiere que un duplicado siga contando como abierto, es una fila del catálogo |
| **S-7** | **Los códigos técnicos de los 18 estados se derivan del nombre** en snake_case sin tildes | El documento no asigna código a ninguno. Mismo criterio que con las subcausas del Anexo A (**D-06**): no se inventa vocabulario, se deriva de la única fuente que hay y se deja cotejable |

### Lo que NO se pudo exigir todavía

De las **diez** reglas obligatorias de cierre del §15 se exigen tres, y una se cumple por
construcción. Las seis restantes necesitan campos de captura que el ticket no tiene —pruebas
finales, infraestructura afectada con valor «no aplica», validación del cliente separada— y son
el alcance del **PR #5**. Por eso **F1-10 queda parcial**, no cumplido: declararlo cumplido
sería decir que se exigen diez reglas cuando se exigen tres.

---

## 4bis. H-6 · El expediente sobrevive a la baja del usuario

**Implementado.** `support_ticket_message.user_id` y `support_ticket_attachment.user_id` pasan
de `ON DELETE CASCADE` a **`ON DELETE SET NULL`**, alineadas con `support_ticket.user_id` y con
`support_ticket_history.actor_user_id`, que ya lo hacían así.

Para que `SET NULL` sea aplicable, las dos columnas pasan además a **nullable**: una constraint
`SET NULL` sobre una columna `NOT NULL` es imposible de satisfacer y el motor la rechaza.

### Autoría congelada

Con `SET NULL` la nota sobrevive pero pierde a su autor, y la bitácora quedaría llena de «—».
Se añade **`author_name`** (`varchar(120)`, nullable) a ambas tablas, que se rellena al escribir
mediante un hook `creating` del modelo —no del controlador— para cubrir todos los caminos.

**Sólo el nombre visible.** Ni correo, ni teléfono, ni documento: el expediente necesita saber
quién escribió, no reconstruir la ficha de alguien que pidió su baja. Guardar de más sería crear
una copia que sobrevive al borrado solicitado.

Congelar es además lo correcto para un expediente: refleja quién firmaba **entonces**, no cómo
se llama hoy. Es el mismo criterio que el historial del PR #3 aplica a las etiquetas de
catálogo.

La lectura prefiere el usuario vivo —puede haberse corregido un apellido— y cae al nombre
congelado; sólo si no hay ninguno de los dos dice «Usuario eliminado», que es información y no
un hueco. Se expone como `author_label`.

### Los archivos del bucket

`CustomerDeletionService::collectFilePaths()` sólo recoge documentos de cliente y firmas de
instalación: **nunca adjuntos de ticket**. Es decir, el efecto anterior era el peor posible —
desaparecía la fila que decía dónde estaba el archivo, y el objeto quedaba huérfano en S3 para
siempre. Ahora la fila se conserva y el archivo sigue siendo alcanzable por el endpoint
autenticado del PR #252. **No se borró ningún archivo existente** y la retención sigue siendo
**D-05**.

### Observación no corregida

Al crear un ticket, `SupportTicketController::store()` atribuye los adjuntos a
`$data['user_id']` —**el cliente**— y no a quien los sube; en `update()` sí usa al usuario
autenticado. Esa inconsistencia es la razón de que la baja de un cliente barriera evidencia
real. Cambiarla altera a quién «pertenece» un adjunto, que es una decisión semántica no
solicitada, así que se documenta y no se toca.

## 4ter. P-43 · Controles de la eliminación de clientes

**Implementado parcialmente.** Este PR **no** resuelve P-43: facturas, pagos, créditos y
arrastres se siguen borrando en cascada. Lo que hace es que la operación deje de ser
silenciosa y esté al alcance de mucha menos gente.

### Lo que se cerró

| Antes | Ahora |
|---|---|
| Autorizado por `edit_internet_service` — lo tenían **20 roles**, incluido `Tecnico` con 7 permisos | Permiso propio **`delete_customers`**, concedido sólo a los roles con `code = 'admin'` (**5 roles**) |
| Sin motivo | Motivo **obligatorio** en el servidor: 10–500 caracteres, no sólo espacios |
| Confirmación sólo en la interfaz | El backend exige además `confirm: "ELIMINAR"`; no confía en el navegador |
| **Sin ninguna traza** en `audit_logs` | Evento `customer_deleted` con actor, tenant, motivo, id de correlación y **conteo de lo que se destruirá** |
| Si la auditoría fallaba, daba igual | Si no se puede escribir la auditoría, **se aborta y no se borra nada** |
| Enlaces de firma huérfanos | Se **desvinculan y revocan**, como `prospects.converted_user_id` |

### Roles afectados

La migración `2026_08_31_000001` concede `delete_customers` **sólo a `code = 'admin'`**. Se
usa el `code` y no el nombre ni el id porque los roles son por tenant: el id varía y el nombre
es editable. Es el mismo criterio de `CheckStaffProfile`.

**Quince roles pierden la capacidad**: `Staff`, `Contabilidad` y `Tecnico` de los cinco
tenants. Es el objetivo, no un efecto colateral: **no se concede por arrastre** a todo el que
tenga `edit_internet_service`, porque eso dejaría el agujero abierto.

### Qué guarda y qué no la auditoría

Guarda **conteos, no contenido**: cuántas facturas, pagos, créditos, documentos e
instalaciones se van a destruir, y cuántos tickets, notas y adjuntos **sobreviven**. Del
cliente sólo nombre y cédula — lo mínimo para saber de quién se habla en una revisión, y que
ya figura en las facturas emitidas.

**Nunca** contraseñas, tokens, datos de pago, documentos ni contenido de adjuntos. Copiarlos
convertiría `audit_logs` en el sitio donde sobreviven precisamente los datos que alguien pidió
eliminar.

El registro se escribe **antes** de destruir y **fuera** de la transacción del borrado: si
compartieran transacción, un fallo al borrar revertiría también la constancia de que se
intentó, y un intento fallido de destruir el histórico de un abonado es justo lo que hay que
poder revisar después.

### Qué pasa con documentos y firmas

- `customer_documents`: se borran las filas **y sus objetos de S3**. Es el comportamiento
  actual desde 2026-08-06 y está cubierto por `CustomerDeletionCleanupTest`. **No se cambió.**
- Firmas de instalación (`customer_signature_path`, `technician_signature_path`): igual, se
  borran de S3 con la instalación. **No se cambió.**
- `contract_signature_links`: **no se borran.** Se desvinculan (`customer_id = NULL`) y se
  revocan. Un enlace es un token de acceso efímero, no evidencia; el contrato firmado vive en
  `customer_documents`. No era un agujero de seguridad —`PublicContractController::customerOf()`
  ya devolvía 404 con el cliente ausente— pero sí una referencia colgante.

### Lo que sigue abierto

**P-43 no está resuelta.** Sigue faltando decidir **D-14** a **D-18** (§9). Mientras tanto, el
daño está acotado a cinco roles administrativos y queda escrito cuánto se destruyó.

## 5. La clave foránea del historial

`support_ticket_history.support_ticket_id` se creó en el PR #3 con **`ON DELETE CASCADE`**.
Con `destroy()` vivo, borrar un ticket destruía su auditoría — lo contrario de la razón de ser
de la tabla.

| Opción | Qué hace | A favor | En contra |
|---|---|---|---|
| **A. Sólo soft delete** | Se deja CASCADE; nunca se dispara | Cambio mínimo | La bomba sigue armada: un `forceDelete()` o un `where()->delete()` futuro la detona |
| **B. RESTRICT** | La base **rechaza** borrar un ticket con historial | Garantía estructural, no disciplina | Un borrado legítimo exige procedimiento explícito |
| **C. Snapshot** | Desnormalizar el ticket en cada evento | El evento sobrevive solo | Duplica datos y **no impide** el borrado |
| **D. Tabla de retención** | Copiar antes de borrar | Permite purgas reales | Infraestructura nueva para un problema que el cliente no ha planteado |

**Recomendado y aplicado en el PR A: B**, con A como complemento cuando llegue el PR C.

No es cinturón y tirantes por gusto: es el criterio que este repositorio ya adoptó para los
catálogos en la R1, escrito en su propia migración —

> «el borrado queda prohibido por diseño: las claves foráneas del ticket se declaran
> `ON DELETE RESTRICT` para que sea **la base de datos, y no la disciplina de quien esté de
> turno**, la que impida perder el histórico.»

**Consecuencia aceptada a conciencia:** una purga real (derecho al olvido) requerirá borrar el
historial primero, de forma deliberada y autorizada. Eso es la finalidad, no un obstáculo.

**`tenant_id` sigue en CASCADE** — dar de baja a un ISP se lleva su auditoría. Probablemente
correcto para una baja de cliente, pero es una decisión distinta y **no se tomó** en el PR A.

---

## 6. División en PRs

| PR | Alcance | ¿Depende del cliente? | Migración | Estado |
|---|---|---|---|---|
| **A · Impedir el borrado físico** | Retirar el `DELETE`, guard en el modelo, FK a `RESTRICT`, corregir H-3 y H-4, quitar el botón | **No** | Sí (FK) | ✅ **Implementado** |
| **H-6 · Preservar el expediente** | Las dos FK a `SET NULL`, `author_name` congelado, UI resistente al autor ausente | **No** | Sí (FK + columna) | ✅ **Implementado** |
| **P-43a · Controles de eliminación de clientes** | Permiso propio, motivo, auditoría previa, enlaces de firma | **No** | Sí (datos + columna) | ✅ **Implementado** |
| **B · Permisos granulares** | Los 20 permisos, middleware por ruta, **backfill que preserva el comportamiento** | **No** | Sí (datos) | ✅ **Implementado** |
| **C · Archivado y restauración** | `deleted_at` + motivo + eventos + reglas + **la UI del PR D** | Ya no: **D-10 aprobada** | Sí (esquema + datos) | ✅ **Implementado** |
| **D · UI de archivados** | Vista, filtro, doble confirmación, restauración | No | No | ↩️ **Absorbido por el PR C** |
| **#4 · Workflow y cierre** | 9 + 9 estados, matriz de transiciones, propuesta, cierre, excepción y reapertura | Ya no: confirmado el 11/09 | Sí (esquema + datos) | ✅ **Implementado** |
| **E · Mapeo de roles §18** | Roles N1/N2/Campo/Supervisor/Auditor con su matriz | Delegado en el equipo (D-09) | Sí (datos) | 🔓 Desbloqueado, sin empezar |

**Por qué el PR D desaparece como PR aparte:** se separó cuando archivar podía no llegar a
existir, para no construir pantallas de algo sin aprobar. Aprobado el archivado, entregar el
backend sin la interfaz dejaría una capacidad que sólo se alcanza por API — es decir,
inalcanzable para quien tiene que usarla. La doble confirmación escribiendo el número del
ticket, que era el corazón del PR D, es una **barrera de seguridad**, y las barreras no se
entregan en un segundo despliegue.

**Lo que desbloquea el PR B:** entregar los permisos con un **backfill que dé los 20 nuevos a
todo rol que hoy tenga `view_support`**. Comportamiento idéntico, cero regresión, y a partir de
ahí quitar permisos es configuración del cliente, no un despliegue.

---

## 6bis. Qué pasa si a un rol le falta un permiso granular

La interfaz **no** hace respaldo a `view_support`. Si un rol conserva el permiso antiguo pero le
faltan los granulares —porque el backfill no se ejecutó, o porque alguien creó un rol a mano
después— la acción simplemente **se oculta**.

Es la elección deliberada: elevar el privilegio en silencio dejaría el panel ofreciendo botones
que la API rechaza con 403, que es peor que no ofrecerlos. `SupportDetail.vue` deja constancia
en consola (`[permisos] …`) para que el operador pueda reportarlo, sin conceder nada.

**Cómo se arregla:** volver a ejecutar la migración de transición, que es idempotente, o
asignar los permisos desde la pantalla de roles.

## 7. Criterios de aceptación por PR

**PR A** — ningún camino permite destruir un ticket con historial; el `DELETE` responde 403 sin
tocar la base; adjuntos, notas y el vínculo con la factura sobreviven al intento; migración y
rollback verificados en SQLite y PostgreSQL. ✅ *Cumplidos.*

**PR B** — ningún usuario existente gana ni pierde una capacidad respecto al día anterior; cada
permiso concede exactamente su acción; `role_id == 1` sigue pasando; el frontend
(`stores/auth.js`) refleja los mismos permisos que el backend.

**PR C** — un ticket archivado es invisible en la operación ordinaria y **completamente
reconstruible** desde el panel de archivados; archivar sin motivo da 422; `forceDelete()` sobre
un ticket con historial falla; el historial del archivado sigue siendo consultable. ✅
*Cumplidos*, con 30 pruebas.

**PR D** *(absorbido por el C)* — archivar exige dos pasos deliberados y un motivo escrito; la
vista de archivados no aparece sin permiso. ✅ *Cumplidos.*

**PR E** — cada rol de la sección 18 tiene exactamente las capacidades que el cliente confirme.

---

## 8. Barreras contra la destrucción accidental

| Barrera | Propuesta | Justificación | Estado |
|---|---|---|---|
| Sin borrado físico | Ruta 403 + guard de modelo + FK `RESTRICT` | Tres capas independientes | ✅ PR A |
| Doble paso | Modal que exige **escribir el número del ticket**, validado también en el servidor | El `confirm()` se acepta por reflejo, y una barrera sólo del navegador la salta un `curl` | ✅ PR C |
| Motivo obligatorio | 10–500 caracteres, validado en backend | Obliga a pensar y es la evidencia de la decisión | ✅ PR C |
| Estados bloqueados | `open` e `in_progress` sólo por **duplicado o error de registro**, con confirmación extra | Archivar trabajo activo casi siempre es un error — pero no siempre | ✅ PR C |
| Cargos | **No archivar** con factura no anulada | El cargo es plata: sin expediente rompe la trazabilidad contable | ✅ PR C · y desde 2026-09-19 la factura se puede **anular** en vez de borrar (§4quater) |
| Restauración | Siempre posible, con motivo y evento, sin límite de tiempo | Si es reversible, un error deja de ser catástrofe | ✅ PR C |
| Auditoría | `ticket_archived` / `ticket_restored` | Infraestructura del PR #3 ya disponible | ✅ PR C |
| Retención | **No purgar nada**; ligar a D-05 | El cliente aún no definió retención de evidencia | Abierto |

---

## 9. Decisiones pendientes del cliente

Actualizado tras la **Confirmación de CNO por chat — 11/09/2026** (§0).

| ID | Pregunta | Estado | Bloquea |
|---|---|---|---|
| **D-09** | Modelo de roles de la sección 18: ¿se adoptan los cinco y con qué capacidades? | 🔓 **Delegada en el equipo.** Definir según la Solicitud Maestra, con simplicidad y reversibilidad | PR E, mitad de F1-17 |
| **D-10** | ¿Debe existir el archivado? ¿Quién archiva y quién restaura? | ✅ **Resuelta.** Sí, reversible y auditado, para Administradores y Propietarios → **S-1** | — |
| **D-11** | ¿Quién **cierra** un ticket? | 🔓 **Delegada en el equipo**, según la Solicitud Maestra | PR #4, permiso `ticket_close` |
| **D-12** | ¿Existe la **reapertura**? | 🔓 **Delegada en el equipo**, según la Solicitud Maestra | PR #4, permiso `ticket_reopen` |
| **D-13** | ¿Quién administra los catálogos del ticket? | 🔓 **Delegada en el equipo** (va dentro de «roles y permisos») | Permiso `ticket_manage_catalogs` |
| **D-05** | Retención y hash de adjuntos | 🟡 **Parcial.** El acceso quedó confirmado («evidencias accesibles para quienes manejan tickets»); la **retención sigue sin definir**, con instrucción explícita de **no purgar ni borrar automáticamente** | F1-11 |

**Lo que sigue necesitando respuesta del cliente, y no del equipo:** cuánto tiempo se conservan
las evidencias y si llevan hash de integridad (**D-05**). Todo lo demás quedó delegado o
resuelto.

**Sobre S-1, que conviene no dejar pasar:** CNO aprobó el archivado «para Administradores y
Propietarios», y en ISPWatch **no existe un rol Propietario**. Se implementó como el rol
`admin` más el superadministrador global. Si para CNO «Propietario» designa a otra figura —el
dueño del ISP frente a un administrador contratado, por ejemplo—, eso es un rol nuevo y debe
entrar por el PR E, no colarse aquí.
