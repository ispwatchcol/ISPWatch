# API REFERENCE — ISPWatch

> Referencia completa de la API REST. Todas las rutas provienen de
> [`routes/api.php`](../routes/api.php); los parámetros de cuerpo provienen de las reglas de
> validación reales de cada `FormRequest` o `$request->validate()`.
> Cuando un endpoint no declara validación explícita se indica así en lugar de inventar campos.

**Última actualización:** 2026-07-30 (post-remediación) · Prefijo base: `/api`

---

## Índice

1. [Autenticación](#1-autenticación)
2. [Convenciones, errores y códigos](#2-convenciones-errores-y-códigos)
3. [Rutas públicas](#3-rutas-públicas)
4. [Sesión y panel](#4-sesión-y-panel)
5. [Clientes](#5-clientes)
6. [Prospectos e instalaciones](#6-prospectos-e-instalaciones)
7. [Documentos de cliente](#7-documentos-de-cliente)
8. [Routers y red](#8-routers-y-red)
9. [Falla masiva](#9-falla-masiva)
10. [Sectoriales y planta FTTH](#10-sectoriales-y-planta-ftth)
11. [Planes de servicio](#11-planes-de-servicio)
12. [Facturación](#12-facturación)
13. [Bitácoras de failover](#13-bitácoras-de-failover)
14. [Gastos](#14-gastos)
15. [Inventario](#15-inventario)
16. [Soporte](#16-soporte)
17. [Personal, roles y permisos](#17-personal-roles-y-permisos)
18. [Tenant y plantillas de documentos](#18-tenant-y-plantillas-de-documentos)
19. [Catálogos, ayuda y ajustes](#19-catálogos-ayuda-y-ajustes)
20. [Importación masiva](#20-importación-masiva)
21. [Mapa de permisos por endpoint](#21-mapa-de-permisos-por-endpoint)

---

## 1. Autenticación

**Esquema:** Laravel Sanctum en modo **SPA** (cookie de sesión, no bearer token).
`EnsureFrontendRequestsAreStateful` está anexado al grupo `api`.

Secuencia obligatoria desde un cliente web:

```bash
# 1) Obtener la cookie CSRF (fija XSRF-TOKEN)
curl -c cookies.txt https://ispwatch-crm.app/sanctum/csrf-cookie

# 2) Iniciar sesión
curl -b cookies.txt -c cookies.txt \
     -X POST https://ispwatch-crm.app/api/login \
     -H "Content-Type: application/json" \
     -H "X-XSRF-TOKEN: <valor de la cookie>" \
     -d '{"email_tenant":"juan.perez@mi-isp","password":"secreto"}'

# 3) Llamadas autenticadas
curl -b cookies.txt https://ispwatch-crm.app/api/dashboard/stats
```

**Puntos clave:**

- El identificador de acceso es **`email_tenant`**, no `email`.
- El dominio debe estar en `SANCTUM_STATEFUL_DOMAINS` y en `CORS_ALLOWED_ORIGINS`.
- El correo debe estar verificado (`email_verified_at`), o el login devuelve **403**.
- Límite de intentos: **5 por minuto** por combinación IP + email (`RateLimiter`).
- Existe la tabla `personal_access_tokens` y el trait `HasApiTokens`, pero **ninguna ruta
  emite tokens**: el modo token no está habilitado en la práctica.

### Autorización

Dos mecanismos encadenados:

| Middleware | Regla |
|---|---|
| `auth:sanctum` | Debe existir sesión válida |
| `permission:<nombre>` | El usuario debe tener `<nombre>` entre sus **permisos efectivos** (`role.permissions` ∪ `users.permissions`) o `*`. **`role_id == 1` recibe bypass total.** Admite varios permisos con semántica **OR**: `permission:a,b` |
| `throttle:<limitador>` | Límite de peticiones. Ver §2 |
| `staff_profile` | Pese al nombre, **no** exige fila en `staff_profile`: comprueba `role.code ∈ {admin, staff}` o `role_id == 1` |

---

## 2. Convenciones, errores y códigos

### Formato de respuesta

**No hay un envoltorio único.** Conviven dos formas y hay que contemplar ambas:

```jsonc
// Forma A — controladores de auth / dashboard / clientes
{ "success": true, "data": { ... } }

// Forma B — controladores de facturación, gastos, inventario…
{ "id": 12, "number": "0001", ... }
```

### Códigos de estado

| Código | Significado en este sistema |
|---|---|
| `200` | OK |
| `201` | Recurso creado |
| `400` | Entrada sospechosa detectada en el login (patrón de inyección) |
| `401` | Sin sesión o sesión expirada. **El frontend borra `userData` y redirige a `/`** |
| `403` | Sin permiso · correo no verificado · tenant no resoluble · límite de clientes alcanzado |
| `404` | Recurso inexistente o de otro tenant (no se distingue: evita enumeración) |
| `422` | Error de validación **o error de base de datos traducido** por `ErrorMessages` |
| `429` | Rate limit del login |
| `500` | Error interno (los detalles van al log, nunca a la respuesta) |

### Errores de validación

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "ip_user": ["La IP 10.0.0.5 ya está asignada a otro cliente en el mismo router/CORE. Puede repetirse solo en un router distinto."]
  }
}
```

### Error de permiso

```json
{
  "message": "Forbidden - You do not have permission to perform this action",
  "required_permission": "manage_routers"
}
```

### Límites de peticiones

Desde 2026-07-30 toda la API tiene límite. Al superarlo se devuelve **429** con las cabeceras
`X-RateLimit-Limit`, `X-RateLimit-Remaining` y `Retry-After`.

| Limitador | Límite | Aplica a |
|---|---|---|
| `api` | 120/min | Toda la API, por usuario autenticado (o por IP si no hay sesión) |
| `router-ops` | 10/min | `provision`, `suspend`, `activate`, `apply-block-rules`, `verify-vpn`, `test-ssh-connection`, `test-core-connection` |
| `bulk-ops` | 5/min | `bulk-provision`, `bulk-provision-async` y todo `/api/import/*` |

El límite estricto de `router-ops` no es teórico: cada llamada abre una sesión SSH al CORE y
tarda 17-34 s, así que unas pocas peticiones concurrentes agotan el pool de conexiones del
CORE y tumban el aprovisionamiento y el corte para todos los tenants.

Aparte, `POST /api/login` mantiene su propio límite de **5 intentos por minuto** por
combinación IP + email.

### Parámetro `tenant` / `tenant_id`

**Ya no se envía.** El interceptor de axios que lo añadía a toda petición se eliminó: el
backend siempre lo ignoró (el tenant se deriva del usuario autenticado) y su presencia
sugería falsamente que el cliente puede elegir su propio tenant.

---

## 3. Rutas públicas

| Método | Ruta | Descripción |
|---|---|---|
| `POST` | `/api/login` | Inicia sesión |
| `POST` | `/api/register` | Registra un nuevo tenant + administrador |
| `POST` | `/api/register/send-code` | Envía código de verificación al correo |
| `GET` | `/api/verify-email/{id}/{hash}` | Verifica el correo (**URL firmada**) |
| `POST` | `/api/verify-email/resend` | Reenvía el correo de verificación |

### `POST /api/login`

**Cuerpo**

| Campo | Tipo | Reglas |
|---|---|---|
| `email_tenant` | string | requerido, máx. 100 |
| `password` | string | requerido, máx. 100 |

**200 OK**

```json
{
  "success": true,
  "data": {
    "id": 42,
    "user_name": "Juan",
    "user_lastname": "Pérez",
    "email_tenant": "juan.perez@mi-isp",
    "role_id": 1,
    "tenant_id": 3,
    "role_name": "Administrador",
    "role_code": "admin",
    "permissions": ["view_clients", "view_billing", "..."],
    "has_staff_profile": true,
    "is_superadmin": false
  }
}
```

**Errores**

| Código | Cuerpo | Causa |
|---|---|---|
| `429` | `{"success":false,"message":"Demasiados intentos. Espera 43 segundos.","retry_after":43}` | Más de 5 intentos/min |
| `400` | `{"success":false,"message":"Entrada no válida detectada."}` | Patrón de inyección SQL/XSS en el usuario |
| `403` | `{"success":false,"message":"Por favor verifica tu correo electrónico..."}` | Correo sin verificar |
| `401` | `{"success":false,"message":"Credenciales incorrectas."}` | Usuario inexistente o contraseña errónea |

---

## 4. Sesión y panel

### `GET /api/auth/me`

Devuelve el usuario autenticado con sus **permisos actualizados desde la base de datos**.
El frontend lo usa para refrescar permisos sin cerrar sesión (necesario tras cambiar un rol).
Misma estructura de `data` que el login.

### `GET /api/dashboard/stats`

Estadísticas del tenant del usuario. Incluye: total de clientes, clientes activos,
sectoriales, routers, **routers con `falla_general`** (lista con `id`, `name`, `ip`),
tickets abiertos y urgentes, ingresos del mes (facturas `paid`) y pagos del mes.

**403** si el usuario no tiene `tenant_id`:
`{"success":false,"message":"No se pudo determinar el tenant del usuario autenticado."}`

---

## 5. Clientes

Recurso base: `apiResource('customers', CustomerProfileController::class)`.

| Método | Ruta | Permiso | Descripción |
|---|---|---|---|
| `GET` | `/api/customers` | auth | Lista paginada |
| `POST` | `/api/customers` | auth | Crea cliente (+ aprovisionamiento opcional) |
| `GET` | `/api/customers/{id}` | auth | Detalle |
| `PUT/PATCH` | `/api/customers/{id}` | auth | Actualiza |
| `DELETE` | `/api/customers/{id}` | auth | Elimina |
| `GET` | `/api/customers/statistics` | auth | Estadísticas de clientes |
| `GET` | `/api/customers/map` | auth | Datos georreferenciados para el mapa |
| `GET` | `/api/customers/used-ips` | auth | IPs ya asignadas |
| `POST` | `/api/customers/first-invoice-preview` | auth | **Sólo calcula**, no escribe |
| `POST` | `/api/customers/{id}/provision` | `activate_deactivate_clients` | Aprovisiona en el router |
| `POST` | `/api/customers/bulk-provision` | `activate_deactivate_clients` | Masivo **síncrono** (legado) |
| `POST` | `/api/customers/bulk-provision-async` | `activate_deactivate_clients` | Masivo **asíncrono** (job en cola) |
| `GET` | `/api/customers/bulk-provision-status/{jobId}` | `activate_deactivate_clients` | Progreso del masivo |
| `POST` | `/api/customers/{id}/suspend` | `activate_deactivate_clients` | Suspende el servicio |
| `POST` | `/api/customers/{id}/activate` | `activate_deactivate_clients` | Reactiva el servicio |

### `POST /api/customers`

Validado por `App\Http\Requests\StoreCustomerRequest`.

**Cuerpo — acceso**

| Campo | Tipo | Reglas |
|---|---|---|
| `email` | string | **requerido**, email, único en `users.email`. Correo **personal** |
| `email_tenant` | string | opcional, máx. 100, único. Correo de **login**. Si se omite se genera `nombre.apellido@dominio` |
| `password` | string | **requerido**, mín. 6 |
| `tel` | string | opcional, máx. 20 |

**Cuerpo — perfil**

| Campo | Tipo | Reglas |
|---|---|---|
| `name` | string | **requerido**, máx. 255 |
| `last_name` | string | requerido **salvo** si `is_company = true` |
| `is_company` | bool | opcional |
| `cedula` | string | **requerido**, máx. 20 |
| `city`, `state` | string | opcional, máx. 255 |
| `address` | string | opcional, máx. 500 |
| `precinto` | string | opcional, máx. 100 |
| `installation_date` | date | opcional. **Base del prorrateo** |
| `estrato` | int | opcional, entre 1 y 6 |
| `exclude_from_billing` | bool | opcional. Saca al cliente de todo el ciclo automático |
| `first_invoice_mode` | string | opcional: `none` \| `prorated` \| `full`. `null` = hereda |
| `first_invoice_free_months` | int | opcional, 0–24. `null` = hereda |
| `comments` | string | opcional, máx. 2000 |

**Cuerpo — servicio**

| Campo | Tipo | Reglas |
|---|---|---|
| `ip_user` | string | opcional, máx. 45. **Única por `router_id`** |
| `service_id` | int | existe en `service_plan` |
| `sectorial_id` | int | existe en `sectorial` |
| `is_fiber` | bool | opcional |
| `olt_id` | int | existe en `sectorial` |
| `nap_port` | string | opcional, máx. 20 |
| `router_id` | int | existe en `router` |
| `tenant_id` | int | opcional; si falta se toma del usuario |

**Cuerpo — credenciales según método de control**

| Campo | Reglas |
|---|---|
| `create_pppoe_secret` | bool |
| `pppoe_username` | máx. 255. **Único por `router_id`** cuando ambos se envían |
| `pppoe_password`, `pppoe_local_address` | máx. 255 / 45 |
| `hotspot_username`, `hotspot_password` | máx. 255 |
| `mac_address` | máx. 17, regex `^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$` |
| `push_to_router` | bool. `false` = guardar sólo en BD, **sin** tocar el router. Ausente ⇒ `true` |

**Ejemplo**

```bash
curl -b cookies.txt -X POST https://ispwatch-crm.app/api/customers \
  -H "Content-Type: application/json" -H "X-XSRF-TOKEN: ..." \
  -d '{
    "name": "María", "last_name": "Gómez", "cedula": "1098765432",
    "email": "maria.gomez@gmail.com", "password": "Clave123",
    "tel": "3001234567", "address": "Cra 5 #12-30", "city": "Tocaima",
    "estrato": 3, "installation_date": "2026-07-16",
    "service_id": 60, "router_id": 4, "sectorial_id": 22,
    "ip_user": "10.20.30.45",
    "first_invoice_mode": "prorated", "first_invoice_free_months": 1,
    "push_to_router": true
  }'
```

**Errores propios**

| Código | Cuerpo | Causa |
|---|---|---|
| `403` | `{"success":false,"message":"...","limit":30,"current":30,"upgrade_required":true}` | Se alcanzó `tenant.max_customers` |
| `422` | `errors.ip_user` | IP repetida en el mismo router |
| `422` | `errors.pppoe_username` | Usuario PPPoE repetido en el mismo router |

### `POST /api/customers/first-invoice-preview`

Calcula qué se le cobraría al cliente en sus primeros meses **sin escribir nada**.
Lo usa el formulario de alta/edición para mostrar prorrateo y meses de cortesía antes de
guardar. Resuelve la cascada **cliente → plan → router** mediante
`App\Billing\FirstInvoicePolicy` y devuelve además el **origen** de cada eje
(`customer`, `plan`, `router`, `default`).

### `POST /api/customers/bulk-provision-async`

Encola un job por cliente y devuelve un `jobId` (UUID). El progreso se consulta con
`GET /api/customers/bulk-provision-status/{jobId}`, que lee `bulk_provision_runs`:
`status`, `total`, `processed`, `success_count`, `fail_count`, `pppoe_skipped_count`,
`results` y `finished_at`.

> Cada aprovisionamiento hace SSH al CORE y SSH anidado al router: **17–34 s por cliente**.
> Por eso el camino masivo real es el asíncrono; el síncrono provoca timeout del gateway.

---

## 6. Prospectos e instalaciones

### Prospectos

| Método | Ruta | Descripción |
|---|---|---|
| `GET` | `/api/prospects` | Lista |
| `POST` | `/api/prospects` | Crea |
| `GET` | `/api/prospects/{id}` | Detalle |
| `PUT` | `/api/prospects/{id}` | Actualiza |
| `DELETE` | `/api/prospects/{id}` | Elimina |
| `POST` | `/api/prospects/{prospect}/mark-converted` | Marca como convertido |
| `POST` | `/api/prospects/{prospect}/installations` | Agenda instalación al prospecto |

**Cuerpo de creación/actualización**

| Campo | Reglas |
|---|---|
| `name` | requerido, máx. 120 |
| `last_name` | máx. 120 |
| `cedula` | máx. 40 |
| `email` | email, máx. 180 |
| `tel` | máx. 40 |
| `address` | máx. 255 |
| `city`, `state` | máx. 120 |
| `estrato` | entero 1–6 |
| `notes` | máx. 2000 |
| `status` | `interesado`\|`agendado`\|`instalado`\|`convertido`\|`rechazado` |

**`mark-converted`** — cuerpo `{ "user_id": 123 }`. Devuelve **422** si el usuario no
pertenece al tenant. En transacción, fija `status = convertido`, `converted_user_id` y
`converted_at`.

### Instalaciones

| Método | Ruta | Permiso | Descripción |
|---|---|---|---|
| `GET` | `/api/installations` | auth | Todas las instalaciones |
| `POST` | `/api/installations` | auth | Crea instalación **junto con** su prospecto |
| `GET` | `/api/installations/technicians` | auth | Técnicos disponibles |
| `GET` | `/api/installations/customers` | auth | Clientes elegibles |
| `GET` | `/api/installations/{installation}` | auth | Detalle |
| `PUT` | `/api/installations/{installation}/prospect` | auth | Actualiza el prospecto asociado |
| `PUT` | `/api/installations/{installation}/billing` | `edit_discount` | Costos, adicionales y descuento |
| `PUT` | `/api/installations/{installation}/sheet` | auth | Guarda el acta (JSON) |
| `POST` | `/api/installations/{installation}/photos` | auth | Sube fotos |
| `POST` | `/api/installations/{installation}/sign` | auth | Firma del cliente y del técnico |
| `GET` | `/api/customers/{customer}/installations` | auth | Instalaciones del cliente |
| `POST` | `/api/customers/{customer}/installations` | auth | Agenda instalación |
| `PUT` | `/api/customers/installations/{installation}` | auth | Actualiza |
| `DELETE` | `/api/customers/installations/{installation}` | auth | Elimina |

> ⚠️ **Límite operativo conocido:** subir varias fotos en una sola petición produce
> `413`/`504` sin JSON en el gateway. El frontend comprime en el navegador y envía
> **una foto por petición**.

---

## 7. Documentos de cliente

| Método | Ruta | Descripción |
|---|---|---|
| `GET` | `/api/customers/{customer}/documents` | Lista documentos |
| `POST` | `/api/customers/{customer}/documents` | Sube documento (`cedula`, `instalacion`, `contrato`, `otros`) |
| `DELETE` | `/api/customers/documents/{document}` | Elimina |
| `GET` | `/api/customers/{customer}/contract-data` | Datos para render del contrato |
| `POST` | `/api/customers/{customer}/contract-sign` | Firma del contrato |

Los archivos residen en **S3 privado**. Cada documento expone un atributo `url` que es una
**URL firmada válida 30 minutos** (`Storage::disk('s3')->temporaryUrl`).

---

## 8. Routers y red

| Método | Ruta | Permiso | Descripción |
|---|---|---|---|
| `GET` | `/api/routers` | auth | Lista |
| `POST` | `/api/routers` | auth | Crea |
| `GET` | `/api/routers/{id}` | auth | Detalle |
| `PUT` | `/api/routers/{id}` | auth | Actualiza |
| `DELETE` | `/api/routers/{id}` | auth | Elimina |
| `GET` | `/api/routers/{router}/free-ips` | auth | IPs libres del rango |
| `GET` | `/api/routers/{router}/interfaces` | auth | Interfaces leídas del equipo |
| `GET` | `/api/routers/{router}/traffic` | auth | Historial de tráfico WAN |
| `GET` | `/api/routers/{router}/verify-block-rules` | auth | Verifica reglas de bloqueo |
| `GET` | `/api/routers/{router}/test-ssh-connection` | auth | Prueba SSH al router cliente |
| `GET` | `/api/routers/test-core-connection` | auth | Prueba SSH al CORE |
| `GET` | `/api/routers/{router}/test-queue-sync` | auth | Prueba de sincronía de colas |
| `GET` | `/api/routers/{router}/vpn-script` | `manage_routers` | Genera script L2TP/IPSec |
| `POST` | `/api/routers/{router}/verify-vpn` | `manage_routers` | Verifica el túnel |
| `POST` | `/api/routers/{router}/set-wan-interface` | `manage_routers` | Fija la interfaz WAN |
| `GET`/`POST` | `/api/routers/{router}/test-secret-sync` | `manage_routers` | Prueba de sincronía de secrets |
| `POST` | `/api/routers/{router}/apply-block-rules` | `activate_deactivate_clients` | Instala reglas de bloqueo |

> Nota de seguridad presente en el código: la variante `GET` de `test-secret-sync` exige
> **el mismo permiso** que la `POST`; de lo contrario sería un bypass de autorización por método.

### `POST /api/routers` — cuerpo (`StoreRouterRequest`)

| Campo | Reglas |
|---|---|
| `name` | **requerido**, máx. 255 |
| `ip` | **requerido**, formato IP |
| `ipv6`, `failover`, `external_id` | máx. 255 |
| `user_rb`, `password_rb` | **requeridos**, máx. 255 |
| `puerto_api`, `puerto_www`, `puerto_ssh` | entero 1–65535 |
| `lan_interface`, `wan_interface` | máx. 255 |
| `vpn_username`, `vpn_password` | máx. 255 |
| `rangos_ip`, `comments` | texto |
| `cut_type_id`, `billing_router_id` | entero |
| `firmware_version` | **requerido**, máx. 100 |
| `status` | **requerido**, máx. 50 (`active`\|`inactive`\|`maintenance`) |
| `coordinates` | libre |
| `agregar_cliente_mkt`, `historial_trafico` | bool |
| `simple_queue`, `control_pcq`, `hotspot`, `pppoe`, `dhcp_leases` | bool — **métodos de control, excluyentes entre sí** |
| `pppoe_limit_mode` | `dynamic` \| `queue` |
| `ip_bindings`, `amarre` | bool — aditivos |
| `falla_general` | bool |

---

## 9. Falla masiva

Registro **append-only** consumido en solo lectura por el sistema externo **Converza**,
que difunde el aviso por WhatsApp a los clientes del core.

| Método | Ruta | Permiso | Descripción |
|---|---|---|---|
| `GET` | `/api/routers/{router}/outage` | auth | Último evento del router |
| `POST` | `/api/routers/{router}/outage/notify` | `manage_routers` | Registra evento `outage` |
| `POST` | `/api/routers/{router}/outage/resolve` | `manage_routers` | Registra evento `restored` |

Cada acción crea una fila nueva en `router_outage_events` (nunca actualiza). Los índices
`(router_id, id)` y `(tenant_id, id)` soportan el consumo por **cursor de id**.

---

## 10. Sectoriales y planta FTTH

| Método | Ruta | Descripción |
|---|---|---|
| `GET/POST` | `/api/sectorials` | Lista / crea elemento de red |
| `GET/PUT/DELETE` | `/api/sectorials/{id}` | Detalle / actualiza / elimina |
| `GET/POST` | `/api/sectorials/{sectorial}/photos` | Fotos |
| `DELETE` | `/api/sectorials/{sectorial}/photos/{photo}` | Elimina foto |
| `GET/POST` | `/api/sectorials/{sectorial}/notes` | Notas |
| `PUT/DELETE` | `/api/sectorials/{sectorial}/notes/{note}` | Edita / elimina nota |
| `GET` | `/api/sectorials/{sectorial}/history` | Bitácora de cambios |
| `GET` | `/api/sectorials/{sectorial}/tickets` | Tickets asociados al elemento |

`element_type` admite: `sectorial`, `switch`, `nodo`, `olt`, `splitter`, `nap`, `mufa`.
Los cuatro últimos forman el **árbol de fibra** por `parent_id`. Los puertos usados
(`ports_used`) se **calculan** (hijos + clientes), no se almacenan.

---

## 11. Planes de servicio

| Método | Ruta | Descripción |
|---|---|---|
| `GET/POST` | `/api/plans` | Lista / crea |
| `GET/PUT/DELETE` | `/api/plans/{id}` | Detalle / actualiza / elimina |
| `POST` | `/api/plans/{plan}/sync-pppoe-profile` | Sincroniza el `/ppp profile` en el router |
| `POST` | `/api/plans/{plan}/sync-hotspot-profile` | Sincroniza el perfil HotSpot |
| `POST` | `/api/plans/{plan}/sync-pcq-engine` | Sincroniza el motor PCQ |

### `POST /api/plans` — cuerpo (`StorePlanRequest`)

| Campo | Reglas |
|---|---|
| `name` | **requerido**, máx. 255 (único por tenant) |
| `speed_down`, `speed_up` | **requeridos** |
| `cost_product` | **requerido**, numérico |
| `type` | **requerido** |
| `type_plan_id` | **requerido**, existe en `type_plans` |
| `tenant_id` | **requerido**, entero |
| `commit` | texto |
| `priority` | entero 1–8 *(Queue)* |
| `burst_download`, `burst_upload` | texto *(Queue/PPPoE)* |
| `pppoe_pool`, `local_address` | texto *(PPPoE)* |
| `shared_users` | entero ≥ 1 *(HotSpot)* |
| `session_timeout`, `idle_timeout` | texto *(HotSpot)* |
| `pcq_rate`, `address_mask` | texto *(PCQ)* |
| `is_courtesy` | bool — el servicio del cliente queda en `gratis` y **nunca se factura** |
| `first_invoice_mode` | `none`\|`prorated`\|`full`. `null` = hereda del router |
| `first_invoice_free_months` | entero 0–24 |

> ⚠️ El plan llamado **"Gratis"** está bloqueado en la interfaz como solo-cortesía.

---

## 12. Facturación

Todo el bloque exige **`view_billing`**; algunos endpoints añaden permisos.

### Facturas

| Método | Ruta | Permiso extra | Descripción |
|---|---|---|---|
| `GET` | `/api/billing/stats` | — | Indicadores de facturación |
| `GET` | `/api/billing/invoices` | — | Lista con filtros |
| `GET` | `/api/billing/invoices/{id}` | — | Detalle |
| `POST` | `/api/billing/invoices` | — | Crea factura manual |
| `PUT` | `/api/billing/invoices/{id}` | — | Actualiza |
| `POST` | `/api/billing/invoices/{id}/mark-unpaid` | — | Revierte pagos y restaura el saldo |
| `DELETE` | `/api/billing/invoices/{id}` | **`delete_invoice`** | Elimina (deja lápida) |
| `POST` | `/api/billing/invoices/{id}/items` | — | Añade ítem |
| `GET` | `/api/billing/invoices/{id}/pdf` | — | Descarga el PDF |

**`POST /api/billing/invoices`**

| Campo | Reglas |
|---|---|
| `customer_id` | **requerido**, existe en `users` |
| `tenant_id` | **requerido** |
| `issue_date`, `due_date`, `period_start`, `period_end` | **requeridos**, fecha |
| `total` | numérico ≥ 0 |
| `notes` | texto |
| `invoice_type` | opcional, slug **activo** del catálogo del tenant (`GET /api/billing/invoice-types`). Por defecto `monthly` |

El servidor fija `status = issued`, `subtotal = total = balance_due`, `currency = COP` y
genera `number` con `BillingService::generateInvoiceNumber()` (seguro ante concurrencia).
**Responde `201`** con la factura.

> `invoice_type` se valida contra el catálogo: un slug inexistente, inactivo o de
> **otro tenant** devuelve `422` con el error en `errors.invoice_type`.

**`PUT /api/billing/invoices/{id}`** — campos `sometimes`: `status`
(`issued`\|`pending`\|`paid`\|`overdue`\|`cancelled`), `issue_date`, `due_date`,
`period_start`, `period_end`, `total`, `balance_due`, `notes`.

**`POST /api/billing/invoices/{id}/items`** — `description` y `amount` requeridos;
`type` (por defecto `adjustment`), `quantity`, `unit_price` opcionales. Recalcula los
totales de la factura.

> **`DELETE` deja una lápida.** Borrar una factura escribe una fila `suppressed` en
> `billing_action_logs` para ese par (cliente, periodo). La generación mensual
> **nunca la regenerará**; el mes siguiente se factura con normalidad.

### Pagos

| Método | Ruta | Descripción |
|---|---|---|
| `GET` | `/api/billing/payments` | Lista de pagos |
| `POST` | `/api/billing/payments` | Registra un pago |
| `PUT` | `/api/billing/payments/{id}` | Actualiza |
| `DELETE` | `/api/billing/payments/{id}` | Elimina (revierte asignaciones) |
| `GET` | `/api/billing/customers/{customerId}/balance` | Saldo del cliente |
| `PATCH` | `/api/billing/customers/{customerId}/credit` | Ajusta el saldo a favor |

**`GET /api/billing/payments`** — listado paginado (por defecto 15 por página,
orden `payment_date` descendente). Todos los parámetros son opcionales y se
combinan con `AND`:

| Parámetro | Reglas | Filtra |
|---|---|---|
| `search` | texto | Búsqueda general: referencia **o** cliente |
| `customer` | texto | Nombre, apellido, **nombre completo**, cédula, usuario o correo |
| `customer_id` | entero | Un cliente exacto |
| `reference` | texto | Referencia (coincidencia parcial) |
| `method` | texto | Forma de pago exacta |
| `registered_by` | texto | Quién lo registró. `sistema` \| `system` \| `automatico` = pagos sin `created_by` |
| `invoice` | texto | Número de alguna factura cubierta por el recaudo (`allocations.invoice.number`) |
| `date_from`, `date_to` | fecha | Rango de `payment_date`, inclusive |
| `amount_min`, `amount_max` | numérico | Rango de `amount`, inclusive |
| `sort_by` | `payment_date`\|`amount`\|`method`\|`reference`\|`created_at` | Columna de orden |
| `sort_dir` | `asc`\|`desc` | Sentido (por defecto `desc`) |
| `per_page` | entero 1–200 | Tamaño de página |
| `page` | entero | Página |

Las búsquedas de texto son insensibles a mayúsculas en PostgreSQL y en SQLite
(macros `whereLike`/`orWhereLike`, ver `SearchMacrosServiceProvider`).

> El **tenant sale siempre del usuario autenticado**. `tenant`/`tenant_id` por
> query param se ignora: antes permitía leer los recaudos de otro tenant.

**`POST /api/billing/payments`**

| Campo | Reglas |
|---|---|
| `customer_id` | **requerido**, existe en `users` |
| `amount` | **requerido**, numérico ≥ 0.01 |
| `payment_date` | **requerido**, fecha |
| `method` | **requerido** |
| `reference`, `notes` | opcionales |

`created_by` se sella desde la sesión, **nunca** desde el cuerpo.

**Efectos secundarios importantes:**
1. El pago se **asigna automáticamente** a las facturas pendientes (más antigua primero).
2. El excedente pasa a `customer_profile.credit_balance`.
3. **Un abono parcial cierra la factura**: si el pago no alcanza a cubrirla, la factura
   queda en `paid` con `balance_due = 0` y el faltante se registra en
   `invoice_carryovers` (`status = pending`) para cobrarse en la **siguiente factura
   mensual**. Ver "Arrastre de saldo" más abajo.
4. Si el cliente queda al día, `BillingService::reactivateIfCleared()` **lo reconecta
   automáticamente en el router** — sólo si el corte fue de facturación. Como el punto 3
   deja la factura sin saldo vencido, **un abono parcial también reconecta**.

**201** con el pago y sus `allocations`. **500** con
`{"message":"No se pudo registrar el pago: ..."}` ante fallo del servicio.

**`GET /api/billing/customers/{customerId}/balance`**

```json
{
  "balance": 50000,            // suma de balance_due de sus facturas
  "credit_balance": 0,         // saldo A FAVOR del cliente (pagos de más)
  "net_balance": 50000,        // lo que debe hoy = balance − crédito
  "carryover_balance": 20000   // deuda arrastrada: se cobra en la PRÓXIMA factura
}
```

`carryover_balance` **no** se suma a `net_balance`: hoy el cliente no lo debe y no
cuenta para la mora ni para el corte. Es informativo para el cajero.

### Arrastre de saldo por abono parcial

Regla de operación: *el cliente abona menos del total → la factura queda pagada y el
faltante se suma a la próxima factura*.

| Momento | Qué pasa |
|---|---|
| Se registra el abono | La factura pasa a `paid`, `carried_out = faltante`, y nace una fila `pending` en `invoice_carryovers` |
| Corre la facturación mensual | La factura nueva suma un ítem `carryover` con todo lo pendiente, `carried_in = total arrastrado`, y las filas pasan a `applied` |
| Se borra / edita el pago, o `mark-unpaid` | Los arrastres **`pending`** vuelven a la factura original y se borran. Los **`applied`** se quedan donde están: ya los cobra otra factura |
| Se borra la factura que cobraba el arrastre | Las filas vuelven a `pending` para que la deuda no se pierda |

Excepciones: los **cargos adicionales** y las **facturas manuales** no absorben
arrastres, y un **mes de cortesía** (factura en cero) tampoco — el saldo espera a la
siguiente factura cobrable.

### Operaciones del ciclo

| Método | Ruta | Descripción |
|---|---|---|
| `POST` | `/api/billing/run-monthly` | Dispara la generación mensual manualmente |
| `POST` | `/api/billing/run-overdue` | Procesa morosos |
| `POST` | `/api/billing/run-auto-cut` | Dispara el corte automático |
| `GET` | `/api/billing/configs` | Configuraciones de facturación (tabla `billing`) |
| `PUT` | `/api/billing/configs/{id}` | Actualiza una configuración |
| `POST` | `/api/billing/additional-charges` | Cargo adicional sin ticket |

**`PUT /api/billing/configs/{id}`** — todos los campos son opcionales; sólo se actualiza lo
que llega. Además de los días/horas del ciclo acepta `overdue_invoices` (≥1, umbral de corte)
y `stop_invoicing_extra` (0–60, margen del **tope de facturación**; `null` = sin tope, se
factura indefinidamente).

**`POST /api/billing/additional-charges`** — `customer_id` e `items[]`
(`description`, `quantity`, `unit_price`; opcionales `unit`, `type`) requeridos;
`due_date`, `notes` e `invoice_type` opcionales. `invoice_type` acepta cualquier slug
activo del catálogo (`equipos`, `tv`…); por defecto `additional`.

### Recordatorios y formas de pago

| Método | Ruta | Descripción |
|---|---|---|
| `POST` | `/api/billing/invoices/{id}/send-reminder` | Recordatorio individual |
| `POST` | `/api/billing/invoices/bulk-reminders` | Recordatorios masivos |
| `GET` | `/api/billing/whatsapp-status` | Estado de la integración WhatsApp |
| `GET/POST` | `/api/billing/payment-methods` | Lista / crea forma de pago |
| `PUT/DELETE` | `/api/billing/payment-methods/{id}` | Actualiza / elimina |

### Tipos de factura (catálogo)

Mismo permiso que las formas de pago: **`view_billing`**.

| Método | Ruta | Descripción |
|---|---|---|
| `GET` | `/api/billing/invoice-types` | Tipos del sistema + los del tenant, ordenados |
| `POST` | `/api/billing/invoice-types` | Crea un tipo propio |
| `PUT` | `/api/billing/invoice-types/{id}` | Actualiza uno propio |
| `DELETE` | `/api/billing/invoice-types/{id}` | Elimina uno propio |

**`POST`** — `name` requerido (≤100); `color` opcional (`blue`, `emerald`, `purple`,
`amber`, `rose`, `cyan`, `indigo`, `orange`, `teal`, `slate`); `description`,
`is_active` opcionales. El `slug` se **deriva del nombre** ("Factura de Equipos" →
`factura_de_equipos`) y no se puede renombrar después: es lo que queda grabado en las
facturas emitidas.

**`PUT`** cambia sólo la etiqueta (`name`, `color`, `description`, `is_active`).

Errores propios:

| Código | Cuándo |
|---|---|
| `403` | El tipo es del sistema (`monthly`, `installation`, `additional`, `service_charge`) o de otro tenant |
| `422` | El nombre choca con un tipo existente, o se intenta borrar un tipo **ya usado en facturas** (hay que desactivarlo) |

---

## 13. Bitácoras de failover

Todo el bloque exige **`execute_mass_actions`**.

| Método | Ruta | Descripción |
|---|---|---|
| `GET` | `/api/billing/action-logs` | Facturas fallidas (`failed`/`exhausted`) |
| `GET` | `/api/billing/action-logs/stats` | Conteos por estado |
| `POST` | `/api/billing/action-logs/{id}/retry` | Reintenta una |
| `POST` | `/api/billing/action-logs/retry-all` | Reintenta todas las elegibles |
| `GET` | `/api/billing/suspension-logs` | Cortes/reconexiones fallidos |
| `GET` | `/api/billing/suspension-logs/stats` | Conteos por estado |
| `POST` | `/api/billing/suspension-logs/{id}/retry` | Reintenta un corte |
| `POST` | `/api/billing/suspension-logs/reconcile` | **Reconcilia DB ⇄ RouterBoard** |

`reconcile` recorre los clientes suspendidos en la base cuyo corte no quedó confirmado en el
router y vuelve a aplicarlo. Es el mismo motor que ejecuta `billing:reconcile-suspensions`
cada hora.

---

## 14. Gastos

| Método | Ruta | Permiso |
|---|---|---|
| `GET` | `/api/expenses` | `view_expenses` |
| `GET` | `/api/expense-categories` | `view_expenses` |
| `POST` | `/api/expenses` | `add_expense` |
| `POST` | `/api/expense-categories` | `add_expense` |
| `PUT` | `/api/expenses/{expense}` | `edit_expense` |
| `PUT` | `/api/expense-categories/{expenseCategory}` | `edit_expense` |
| `DELETE` | `/api/expense-categories/{expenseCategory}` | `edit_expense` |

**Cuerpo de gasto**

| Campo | Reglas |
|---|---|
| `expense_category_id` | existe en `expense_categories` |
| `user_id` | existe en `users` (beneficiario; puede ser nulo: arriendo, servicios…) |
| `expense_date` | **requerido**, fecha |
| `amount` | **requerido**, numérico ≥ 0 |
| `description` | máx. 255 |
| `notes` | texto |

> **No existe borrado de gastos.** Se anulan poniendo `status = 'anulado'` vía `PUT`.
> `created_by` se sella desde la sesión.

---

## 15. Inventario

| Método | Ruta | Permiso | Descripción |
|---|---|---|---|
| `GET/POST` | `/api/inventory` | auth | Equipos (con serial y MAC) |
| `GET/PUT/DELETE` | `/api/inventory/{id}` | auth | Detalle / actualiza / elimina |
| `GET/POST` | `/api/inventory-stock` | auth | Modelos / stock |
| `PUT/DELETE` | `/api/inventory-stock/{id}` | auth | |
| `GET/POST` | `/api/inventory-providers` | auth | Proveedores |
| `PUT/DELETE` | `/api/inventory-providers/{id}` | auth | |
| `GET/POST` | `/api/inventory-branches` | auth | Sucursales |
| `PUT/DELETE` | `/api/inventory-branches/{id}` | auth | |

Todos con alcance de tenant vía `BelongsToTenant`. Sustituyeron al acceso directo a Supabase.
`tenant_id` **no es asignable en masa**: se establece desde el usuario autenticado.

---

## 16. Soporte

Recurso base `apiResource('support', SupportTicketController::class)` bajo `auth:sanctum`.
Las operaciones de conversación y cargo exigen además **`staff_profile`**.

| Método | Ruta | Requisito | Descripción |
|---|---|---|---|
| `GET/POST` | `/api/support` | auth | Lista / crea ticket |
| `GET/PUT/DELETE` | `/api/support/{id}` | auth | Detalle / actualiza / elimina |
| `GET` | `/api/support/statistics` | `staff_profile` | Estadísticas |
| `POST` | `/api/support/{id}/message` | `staff_profile` | Añade mensaje |
| `PUT` | `/api/support/messages/{id}` | `staff_profile` | Edita mensaje |
| `DELETE` | `/api/support/messages/{id}` | `staff_profile` | Elimina mensaje |
| `PATCH` | `/api/support/{id}/status` | `staff_profile` | Cambia el estado |
| `POST` | `/api/support/{id}/charge` | `staff_profile` | Genera cargo (factura `service_charge`) |
| `GET` | `/api/support/{id}/charges` | `staff_profile` | Cargos del ticket |

Dominios: `status` ∈ {`open`,`in_progress`,`resolved`,`closed`};
`priority` ∈ {`low`,`medium`,`high`,`urgent`};
`category` ∈ {`technical`,`billing`,`services`,`general`}.

---

## 17. Personal, roles y permisos

| Método | Ruta | Permiso |
|---|---|---|
| `GET/POST` | `/api/staff` | `view_staff` |
| `GET/PUT/DELETE` | `/api/staff/{id}` | `view_staff` |
| `GET` | `/api/roles` | **solo autenticación** |
| `POST/PUT/DELETE` | `/api/roles[/{id}]` | `manage_roles` |
| `GET` | `/api/roles/permissions` | `manage_roles` |

> `GET /api/roles` quedó abierto a cualquier usuario autenticado a propósito: los desplegables
> de personal lo necesitan. La creación/modificación sigue protegida.

`GET /api/roles/permissions` devuelve el catálogo agrupado de
`App\Constants\Permissions::getAllPermissions()`:

```
Clientes · Facturas · Contabilidad · Infraestructura · Inventario · Soporte · Facturación · Sistema
```

---

## 18. Tenant y plantillas de documentos

| Método | Ruta | Permiso | Descripción |
|---|---|---|---|
| `GET` | `/api/tenant/maps-config` | auth | Config de mapas (para que un no-admin vea el mapa) |
| `GET` | `/api/tenants/{id}` | `manage_tenant` | Datos del tenant |
| `PUT` | `/api/tenants/{id}` | `manage_tenant` | Actualiza |
| `PUT/PATCH` | `/api/tenant/config` | `manage_tenant` | Configuración |
| `POST` | `/api/tenant/logo` | `manage_tenant` | Sube el logo |
| `GET` | `/api/document-templates` | `manage_document_templates` | Lista plantillas |
| `GET` | `/api/document-templates/{type}` | `manage_document_templates` | Obtiene una |
| `PUT` | `/api/document-templates/{type}` | `manage_document_templates` | Actualiza el HTML |
| `POST` | `/api/document-templates/{type}/reset` | `manage_document_templates` | Restaura la plantilla por defecto |
| `POST` | `/api/document-templates/{type}/preview` | `manage_document_templates` | Vista previa renderizada |

`{type}` ∈ {`invoice`, `contract`, `installation`}. **No hay restricción `->where()` en la
ruta a propósito**: un valor inválido debe llegar al controlador para que
`assertValidType()` devuelva un `404` JSON limpio en vez de caer en el catch-all de la SPA.

**`GET /api/document-templates/{type}`** — además de `body_html`/`is_active`/`has_draft`,
devuelve:
- `is_advanced_mode` (bool) — si la plantilla usa el shell fijo o el documento HTML completo.
- `placeholders` — whitelist de placeholders **escalares** para el tipo (`config/document_placeholders.php`), `{token: etiqueta}`.
- `block_placeholders` — whitelist de placeholders **de bloque** para el tipo (`config/document_placeholder_blocks.php`), mismo formato. Los 3 tipos incluyen `empresa.logo` (auditoría 2026-08-03) — `contract` ya no está vacío. `contract` también incluye `contrato.firma_cliente` (auditoría 2026-08-04) — necesario en modo avanzado, donde no hay shell fijo que la imprima por su cuenta (ver `docs/ARQUITECTURA.md`).

**`PUT /api/document-templates/{type}`** y **`POST .../preview`** — cuerpo:

```json
{ "body_html": "<p>…</p>", "is_advanced_mode": false }
```

`is_advanced_mode` es `sometimes|boolean` (default `false`). Determina **qué sanitizer** se
usa para sanear `body_html` — `TemplateSanitizer` (acotado) o `AdvancedTemplateSanitizer`
(amplio, documento completo). En `preview`, refleja el modo que el tenant tiene seleccionado
*en ese momento* en el editor, no necesariamente lo persistido (puede estar probando antes de
guardar).

**Header `X-Template-Warnings`** (sólo en la respuesta de `preview`, sólo si aplica) — JSON
array de `{token, label}` con los placeholders **de bloque** que no se pudieron insertar en el
documento (ej. quedaron dentro de un atributo HTML). Informativo: el PDF se genera igual, sin
ese contenido. Ausente si no hay huérfanos — el frontend sólo lo lee si el header existe.

```json
[{ "token": "factura.tabla_items", "label": "Tabla de ítems de la factura…" }]
```

Al ser un header no estándar, va declarado en `exposed_headers` de `config/cors.php`: sin eso
el navegador lo oculta a JavaScript en cualquier llamada cross-origin (el servidor de Vite en
`:5173` contra la API en `:8000`), y el aviso nunca se dispararía en desarrollo. En producción
front y API comparten origen, donde la restricción no aplica.

> Un placeholder **escalar** desconocido, con typo, o de **otro tipo de documento**
> (ej. `{{factura.tabla_items}}` dentro de un contrato) no genera este header — se blanquea a
> `''` en silencio, mismo criterio que cualquier token no reconocido. Ver
> `docs/MEJORAS_RECOMENDADAS.md`.

> **Por qué `manage_document_templates` es un permiso aparte de `manage_tenant`:**
> `manage_tenant` sólo cubre campos de identidad de empresa con validación estricta. El cuerpo
> de una plantilla es texto legal/fiscal libre. Como el sistema admite roles personalizados,
> un rol creado para "actualizar el teléfono" no debe poder reescribir cláusulas de contrato.
> **Nota:** el permiso se añadió después, así que los roles admin existentes necesitaron una
> migración de backfill (`2026_07_27_120000`).

---

## 19. Catálogos, ayuda y ajustes

### Catálogos (datos globales, solo lectura)

| Método | Ruta |
|---|---|
| `GET` | `/api/catalogs/cut-types` |
| `GET` | `/api/catalogs/script-versions` |
| `GET` | `/api/catalogs/type-billings` |
| `GET` | `/api/catalogs/users` |

Reemplazan las lecturas directas del frontend a Supabase.

### Centro de ayuda

| Método | Ruta |
|---|---|
| `GET` | `/api/help-center` |
| `POST/PUT/DELETE` | `/api/help-center/categories[/{id}]` |
| `POST/PUT/DELETE` | `/api/help-center/articles[/{id}]` |

> La **lectura** queda abierta a cualquier usuario autenticado (es el manual del producto).
> La **escritura** exige `permission:view_settings` en la ruta **y**, además,
> `users.is_superadmin` por una comprobación dentro de `HelpCenterController`. Tener
> `view_settings` no basta.

### Ajustes

| Método | Ruta | Permiso |
|---|---|---|
| `POST` | `/api/settings/cache/clear` | `view_settings` |

---

## 20. Importación masiva

Todo el bloque exige **`execute_mass_actions`** y va bajo el prefijo `/api/import`.

| Método | Ruta | Descripción |
|---|---|---|
| `GET` | `/api/import/template` | Descarga la plantilla unificada (clientes + planes + routers + sectoriales) |
| `POST` | `/api/import/upload` | Sube y procesa la plantilla unificada |
| `GET` | `/api/import/docs` | Documentación de campos |
| `POST` | `/api/import/errors-excel` | Exporta a Excel los errores de una importación |
| `GET` | `/api/import/customers-update-template` | Plantilla de **actualización** masiva de clientes |
| `POST` | `/api/import/customers-update` | Procesa la actualización masiva |
| `GET` | `/api/import/customers-update-docs` | Documentación de campos |
| `GET` | `/api/import/inventory-template` | Plantilla de inventario |
| `POST` | `/api/import/inventory` | Carga masiva de equipos |
| `GET` | `/api/import/inventory-docs` | Documentación de campos |

> **Regla de rendimiento establecida:** los importadores **no deben ejecutar consultas por
> fila**. Con 200 filas eso producía `504` del gateway. Los modelos se precargan en bloque.

---

## 21. Mapa de permisos por endpoint

| Permiso | Endpoints que lo exigen |
|---|---|
| `view_clients` | Lista y detalle de clientes, estadísticas, mapa, documentos, datos de contrato |
| `add_clients` | `POST /api/customers`; **además abre por OR** la lectura de planes, sectoriales, routers, IPs libres y la vista previa de primera factura |
| `edit_internet_service` | `PUT/PATCH` y `DELETE` de cliente, borrado de documentos, firma de contrato |
| `activate_deactivate_clients` | `customers/{id}/provision`, `bulk-provision*`, `suspend`, `activate`, `routers/{router}/apply-block-rules`, `verify-block-rules` |
| `manage_routers` | CRUD de routers, `vpn-script`, `verify-vpn`, `set-wan-interface`, `interfaces`, `traffic`, `test-*`, `outage/notify`, `outage/resolve`, sincronización de perfiles de plan |
| `view_client_traffic` | `routers/{router}/traffic` (por OR con `manage_routers`) |
| `view_plans` | Escritura de planes y sincronización al router; lectura por OR |
| `view_sectorials` | Escritura de sectoriales, fotos y notas; lectura por OR |
| `view_inventory` | Escritura de inventario, stock, proveedores y sucursales; lectura por OR con `view_support` |
| `view_support` | Tickets (CRUD), instalaciones, prospectos; lectura de fotos/notas/historial de sectorial |
| `delete_installations` | `DELETE /api/customers/installations/{installation}` (por OR con `view_support`) |
| `edit_discount` | `installations/{installation}/billing` |
| `view_billing` | Todo `/api/billing/*` (facturas, pagos, configs, recordatorios, formas de pago, **tipos de factura**) |
| `delete_invoice` | `DELETE /api/billing/invoices/{id}` |
| `execute_mass_actions` | `/api/billing/action-logs*`, `/api/billing/suspension-logs*`, `/api/import/*` |
| `view_staff` | `/api/staff*` |
| `manage_roles` | `POST/PUT/DELETE /api/roles`, `/api/roles/permissions` |
| `manage_tenant` | `/api/tenants/{id}`, `/api/tenant/config`, `/api/tenant/logo` |
| `manage_document_templates` | `/api/document-templates*` |
| `view_settings` | `/api/settings/cache/clear`, escritura del centro de ayuda (+ `is_superadmin`) |
| `view_expenses` / `add_expense` / `edit_expense` | Lectura / alta / edición de gastos y categorías |
| *(sólo `staff_profile`)* | `/api/support/statistics`, mensajes, cambio de estado y cargos de ticket |
| *(sólo autenticación)* | `GET /api/roles`, `/api/auth/me`, `/api/dashboard/stats`, `/api/catalogs/*`, `GET /api/help-center`, `/api/tenant/maps-config` |

> **Cobertura completa desde 2026-07-30.** Todos los `apiResource` y sub-recursos llevan ya
> su permiso. Los de **lectura** declaran varios permisos con semántica OR porque son datos
> de referencia que otras pantallas necesitan: el formulario de alta de cliente carga planes,
> sectoriales y routers, y el rol Técnico tiene `add_clients` pero no `view_plans`,
> `view_sectorials` ni `manage_routers`. La **escritura** exige el permiso dueño a secas.
> Contrato fijado por 42 tests en `tests/Feature/Auth/ApiAuthorizationTest.php`.
