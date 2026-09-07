# Diseño · Permisos granulares y archivado de tickets

> **Estado:** aprobado conceptualmente el 2026-08-27. **PR A implementado**; B a E pendientes.
> **Fuentes:** `docs/cliente/CNO/V1_1/Solicitud_Maestra_ISPwash_CNO_V1_1.docx` (secciones 18 y
> 19) · `SEGUIMIENTO_MODULO_TICKETS.md` · modelo de roles y permisos vigente · `SupportTicket`,
> `support_ticket_history` y rutas actuales.
>
> Este documento **no** modifica los originales del cliente en `V1_1/`.

---

## 1. Hallazgos de la auditoría

| # | Hallazgo | Gravedad | Estado |
|---|---|---|---|
| **H-1** | `DELETE /api/support/{id}` exigía **`view_support`** — el mismo permiso que *leer*. Quien podía ver un ticket podía destruirlo. Los roles `Tecnico` y `Staff` de todos los tenants lo tienen | 🔴 | ✅ **Cerrado (PR A)** |
| **H-2** | `destroy()` borraba físicamente y, desde el PR #3, `support_ticket_history` cuelga del ticket con `ON DELETE CASCADE`: borrar un ticket **destruía su auditoría** | 🔴 | ✅ **Cerrado (PR A)** |
| **H-3** | `destroy()` borraba adjuntos de `Storage::disk('public')`, pero el PR #252 los movió a `s3`. No borraba el objeto del bucket y sí la fila que decía dónde estaba: **archivos huérfanos con datos del cliente** | 🟠 | ✅ **Cerrado (PR A)** |
| **H-4** | `invoices.ticket_id` es `nullOnDelete()`: borrar el ticket dejaba el **cargo huérfano**, sin origen rastreable | 🟠 | ✅ **Cerrado (PR A)** |
| **H-5** | La única barrera era un `confirm()` del navegador en `Support.vue` | 🟠 | ✅ **Cerrado (PR A)** |
| **H-6** | `support_ticket_message.user_id` y `support_ticket_attachment.user_id` son **`ON DELETE CASCADE` sobre `users`**. `CustomerDeletionService` hace `$user->delete()`: **borrar un cliente destruye las notas y adjuntos de todos sus tickets**, mientras el ticket sobrevive con `user_id = NULL` | 🔴 | ⚠️ **Abierto** — requiere PR propio |

**H-6 se descubrió durante el PR A y no se corrigió allí**: cambiar esas dos claves foráneas
afecta al flujo de baja de clientes, que tiene su propio servicio y sus propias pruebas.
Merece un PR con su análisis. Anotado como **P-42**.

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

## 4. Archivado / anulación

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

### Esquema propuesto (PR C)

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

---

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
| **B · Permisos granulares** | Los 20 permisos, middleware por ruta, **backfill que preserva el comportamiento** | **No** | Sí (datos) | ⚪ Listo para iniciar |
| **C · Archivado y restauración** | `deleted_at` + motivo + eventos + reglas | Parcialmente | Sí (esquema) | 🔒 Requiere D-10 |
| **D · UI de archivados** | Vista, filtro, doble confirmación, restauración | No | No | 🔒 Depende de C |
| **E · Mapeo de roles §18** | Roles N1/N2/Campo/Supervisor/Auditor con su matriz | **Sí, bloqueante** | Sí (datos) | 🔒 Requiere D-11 |

**Lo que desbloquea el PR B:** entregar los permisos con un **backfill que dé los 20 nuevos a
todo rol que hoy tenga `view_support`**. Comportamiento idéntico, cero regresión, y a partir de
ahí quitar permisos es configuración del cliente, no un despliegue.

---

## 7. Criterios de aceptación por PR

**PR A** — ningún camino permite destruir un ticket con historial; el `DELETE` responde 403 sin
tocar la base; adjuntos, notas y el vínculo con la factura sobreviven al intento; migración y
rollback verificados en SQLite y PostgreSQL. ✅ *Cumplidos.*

**PR B** — ningún usuario existente gana ni pierde una capacidad respecto al día anterior; cada
permiso concede exactamente su acción; `role_id == 1` sigue pasando; el frontend
(`stores/auth.js`) refleja los mismos permisos que el backend.

**PR C** — un ticket archivado es invisible en la operación ordinaria y **completamente
reconstruible** desde el panel de archivados; archivar sin motivo da 422; `forceDelete()` sobre
un ticket con historial falla; el historial del archivado sigue siendo consultable.

**PR D** — archivar exige dos pasos deliberados y un motivo escrito; la vista de archivados no
aparece sin permiso.

**PR E** — cada rol de la sección 18 tiene exactamente las capacidades que el cliente confirme.

---

## 8. Barreras contra la destrucción accidental

| Barrera | Propuesta | Justificación | Estado |
|---|---|---|---|
| Sin borrado físico | Ruta 403 + guard de modelo + FK `RESTRICT` | Tres capas independientes | ✅ PR A |
| Doble paso | Modal que exige **escribir el número del ticket** | El `confirm()` se acepta por reflejo | PR D |
| Motivo obligatorio | Mín. 10 caracteres, validado en backend | Obliga a pensar y es la evidencia de la decisión | PR C |
| Estados bloqueados | **No archivar** `open` ni `in_progress` | Archivar trabajo activo casi siempre es un error | PR C |
| Cargos | **No archivar** con factura no anulada | El cargo es plata: sin expediente rompe la trazabilidad contable | PR C |
| Restauración | Siempre posible, con motivo y evento, sin límite de tiempo | Si es reversible, un error deja de ser catástrofe | PR C |
| Auditoría | `ticket_archived` / `ticket_restored` | Infraestructura del PR #3 ya disponible | PR C |
| Retención | **No purgar nada**; ligar a D-05 | El cliente aún no definió retención de evidencia | Abierto |

---

## 9. Decisiones pendientes del cliente

| ID | Pregunta | Bloquea |
|---|---|---|
| **D-09** | Modelo de roles de la sección 18: ¿se adoptan los cinco y con qué capacidades? | PR E, mitad de F1-17 |
| **D-10** | ¿Debe existir el archivado? Y de existir, ¿quién archiva y quién restaura? | PR C, PR D |
| **D-11** | ¿Quién **cierra** un ticket? El documento sólo nombra «propuesta de cierre» y «cierre especial» | PR #4 (ciclo de vida), permiso #14 |
| **D-12** | ¿Existe la **reapertura**? No aparece en el documento | Permiso #16 |
| **D-13** | ¿Quién administra los catálogos del ticket? | Permiso #19 |

**Sugerencia de redacción para D-10:** «detectamos que hoy cualquiera con permiso de lectura
podía borrar un ticket, y lo hemos impedido. Proponemos sustituirlo por un archivado reversible
y auditado — ¿lo confirman, o prefieren que no exista ninguna forma de retirar un ticket de la
vista?»
