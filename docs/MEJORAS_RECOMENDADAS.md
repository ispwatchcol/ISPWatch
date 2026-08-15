# BITÁCORA DE MEJORAS — ISPWatch

> Hallazgos de auditoría de código, esquema y configuración, con su estado de resolución.
> Todos están verificados contra el repositorio o contra el esquema real de producción.

**Auditoría inicial:** 2026-07-30 · **Remediación aplicada:** 2026-07-30 · Rama: `feat/first-invoice-free-months`

---

## Índice

1. [Estado actual](#1-estado-actual)
2. [Correcciones a la auditoría inicial](#2-correcciones-a-la-auditoría-inicial)
3. [Resueltos — prioridad crítica](#3-resueltos--prioridad-crítica)
4. [Resueltos — prioridad alta](#4-resueltos--prioridad-alta)
5. [Resueltos — prioridad media](#5-resueltos--prioridad-media)
6. [Resueltos — prioridad baja](#6-resueltos--prioridad-baja)
7. [Pendientes](#7-pendientes)
8. [Tabla consolidada](#8-tabla-consolidada)
9. [Qué hay que ejecutar para cerrar el ciclo](#9-qué-hay-que-ejecutar-para-cerrar-el-ciclo)
10. [Lo que ya estaba bien resuelto](#10-lo-que-ya-estaba-bien-resuelto)

---

## 1. Estado actual

| Estado | Cantidad | Detalle |
|---|---:|---|
| ✅ **Resuelto en código** | 25 | Aplicado y verificado con tests |
| 🔧 **Requiere ejecución** | 4 | El código está listo; falta correr migraciones o rotar credenciales |
| ❌ **Falso positivo** | 2 | Corregidos en §2 |
| 📋 **Pendiente** | 11 | Decisión de producto, infraestructura, o hallazgos posteriores (P-10 a P-12 del repaso del manual del 2026-08-03; P-14 del 2026-08-05; **P-15 del 2026-08-06**). P-3, P-4, P-13 y P-16 se cerraron el 2026-08-06 |

### Resultado medible

| Métrica | Antes | Después |
|---|---:|---:|
| Tests pasando | 180 | **458** |
| Tests fallando | 34 | **0** |
| Endpoints de la API sin control de permisos | ~60 | **0** |
| Límite de peticiones en la API | ninguno | 120/min + 10/min y 5/min en operaciones caras |
| Secretos de producción versionados | 10 | **0** |
| Directivas peligrosas en la CSP | 3 | **0** |

---

## 2. Correcciones a la auditoría inicial

Dos hallazgos de la primera pasada resultaron **incorrectos**. La causa fue usar
`pg_stat_user_tables.n_live_tup` para medir el volumen de las tablas: es una **estimación**
que actualizan `autovacuum`/`ANALYZE`, y en tablas pequeñas o nunca analizadas informa `0`
aunque tengan filas. Se repitió la medición con `COUNT(*)` real.

### ❌ C-3 «La tabla `cut_type` está vacía» — FALSO

`cut_type` tiene **3 filas reales** (`Corte Automático`, `Corte Manual`, `Sin Corte`) y los
**6 routers** tienen un `cut_type_id` válido: 3 en automático y 3 en manual. El corte
automático **sí** estaba operativo. Lo mismo ocurría con `type_billing` (3 filas) y
`script_version` (2), que la primera pasada dio por muertas.

**Lo que sí era cierto** y se ha corregido: la comparación por **nombre literal**
(`$cutType->name === 'Corte Automático'`) es frágil. Un nombre sin tilde, con espacios de
más o en otra caja hace que el router caiga en la rama «sin acción» y deje de cortar **sin
ningún error visible**. Reclasificado de 🔴 crítico a 🟡 medio y resuelto — ver **M-10**.

### ❌ C-4 «Migración pendiente en producción» — YA RESUELTO

`2026_07_30_000000_add_first_invoice_free_months_and_plan_policy` figura **aplicada**
(batch 68) y las cuatro columnas existen en `public`. Estaba pendiente al inicio de la
auditoría y se aplicó durante la misma.

### ⚠️ Precisión sobre A-1 (centro de ayuda)

Se afirmó que la escritura del centro de ayuda estaba «sin ninguna protección». En realidad
`HelpCenterController` ya llamaba a `requireSuperadmin()`, que exige `users.is_superadmin`.
El hueco era la **ausencia de middleware en la ruta**, no la ausencia total de control.
Se añadió `permission:view_settings` como segunda capa; el control del controlador manda.

### ⚠️ Precisión sobre `CheckStaffProfile`

Pese al nombre, **no exige una fila en `staff_profile`**: comprueba
`role.code ∈ {admin, staff}` o `role_id == 1`. Que `staff_profile` esté vacía en producción
no bloquea nada. Corregido en `API_REFERENCE.md` y `BITACORA_TECNICA.md`.

---

## 3. Resueltos — prioridad crítica

### ✅ C-1 · Credenciales de producción en texto plano en el repositorio

`.do/deploy.template.yaml` estaba versionado con 10 secretos en claro: contraseña de la
base de datos, del CORE MikroTik (API y SSH), frase de la clave privada, secreto IPSec,
contraseña VPN, clave SMTP, clave anónima de Supabase y `APP_KEY`.

**Aplicado**

- Plantilla reescrita: cada valor sensible es ahora `<<<CAMBIAR:...>>>` con `type: SECRET`,
  de modo que App Platform lo cifra y nunca lo devuelve en claro.
- `.do/deploy.yaml` y `.do/*.local.yaml` añadidos a `.gitignore`.
- `.env.testing` destrackeado (`git rm --cached`); se versiona `.env.testing.example`.
- Runbook operativo completo: [`RUNBOOK_ROTACION_SECRETOS.md`](RUNBOOK_ROTACION_SECRETOS.md).

> 🔧 **Requiere ejecución.** El repositorio está limpio, pero **las credenciales antiguas
> siguen comprometidas**: están en el historial de Git. Hay que rotarlas siguiendo el runbook.
> Ninguna otra medida sustituye a la rotación.

### ✅ C-2 · El planificador no estaba definido en el despliegue

La especificación definía un servicio web y un worker de cola, pero **ningún componente
ejecutaba `schedule:run`**. Sin él no se factura, no se recuerda, no se corta, no se
reconcilian los cortes y no se recolecta tráfico. Es el fallo de negocio más grave del
sistema y ya se había materializado: el comando `billing:verify-monthly` existe justamente
porque el cron de producción no corría y el failover no podía detectarlo.

**Aplicado.** Tercer componente `scheduler` en `.do/deploy.template.yaml`, con
`php artisan schedule:work`, `LOG_LEVEL=info` (el planificador registra en INFO el resultado
de cada ciclo; con `warning` esa traza se perdía) y las variables de SMTP y CORE que sus
tareas necesitan.

> 🔧 **Requiere ejecución.** Aplicar la especificación en DigitalOcean y verificar con
> `billing:verify-monthly` (debe reportar `ok`, nunca `no_show`).

---

## 4. Resueltos — prioridad alta

### ✅ A-1 · Autorización incompleta en la API

Los `apiResource` de clientes, routers, planes, sectoriales, inventario y soporte —además de
instalaciones, prospectos y documentos— sólo exigían `auth:sanctum`. El control real lo hacía
la guarda de `vue-router`, que es **puramente cosmética**: cualquier usuario autenticado
podía crear, modificar o borrar clientes y routers llamando la API directamente.

**Aplicado.** Permiso en cada endpoint, separando lectura de escritura. `CheckPermission`
acepta ahora **varios permisos con semántica OR** (`permission:a,b`).

**Por qué el OR es necesario y no un atajo:** el formulario de alta de cliente carga planes,
sectoriales y routers, y el rol **Técnico** tiene `add_clients` pero **no** `view_plans`,
`view_sectorials` ni `manage_routers`. Exigir sólo el permiso dueño habría dejado los
desplegables vacíos — habríamos cambiado un agujero de seguridad por una avería funcional.
La **escritura** sí exige el permiso dueño a secas.

**Verificado** con 42 tests en `tests/Feature/Auth/ApiAuthorizationTest.php`: cada endpoint
devuelve 403 sin permiso, deja pasar con él, y la escritura sigue cerrada al técnico.

> **Deuda consciente:** no existe un permiso `delete_clients` en el catálogo. El borrado de
> cliente se apoya en `edit_internet_service` para no inventar un permiso que ningún rol
> sembrado tendría. Anotado como pendiente **P-1**.

**Rescoldo encontrado el 2026-08-06 (ya corregido).** `GET /api/billing/stats` tenía el permiso
correcto pero derivaba el tenant del **query param**: `$request->tenant_id ?? $request->tenant`,
con el frontend enviándolo desde `localStorage`. Cualquiera con `view_billing` podía leer las
finanzas de otra empresa cambiando un número en la URL. Ahora sale de
`$request->user()->tenant_id` y el frontend ya no lo manda; fijado en
`BillingStatsTest::another_tenants_figures_are_never_returned`.

El barrido posterior por el mismo patrón encontró **un solo caso más**, también corregido:
`RouterController::getFreeIps()` leía `?tenant_id`/`?tenant` y, **si no llegaba, no filtraba nada**
(`if ($tenantId) { … }`). Como el frontend nunca envió ese parámetro, en producción venía
funcionando sin filtro: daba por ocupadas las IPs de *todos* los tenants y por tanto **escondía
direcciones libres** del analizador de IPs. No era una fuga de datos de cliente —sólo devuelve
direcciones— pero sí un defecto funcional silencioso. Hoy deriva el tenant del usuario
autenticado y filtra siempre.

### ✅ A-2 · Sin límite de peticiones en la API

**Aplicado.** `$middleware->throttleApi()` en `bootstrap/app.php` y tres limitadores en
`AppServiceProvider`:

| Limitador | Límite | Alcance |
|---|---|---|
| `api` | 120/min | Toda la API, por usuario (o IP si no hay sesión) |
| `router-ops` | 10/min | Aprovisionar, suspender, activar, aplicar reglas, pruebas SSH |
| `bulk-ops` | 5/min | Importaciones y aprovisionamiento masivo |

El límite estricto en `router-ops` no es teórico: **cada llamada abre una sesión SSH al CORE
y tarda 17-34 s**, así que un puñado de peticiones concurrentes agota el pool de conexiones
del CORE y tumba el aprovisionamiento y el corte **para todos los tenants**.

### ✅ A-3 · Credenciales de red en texto plano en la base de datos

`router.password_rb`, `vpn_password`, `sectorial.pass_rb`,
`customer_profile.pppoe_password` y `hotspot_password` estaban en claro — y las columnas
`router.*_encrypted` **también**, pese al nombre.

**Aplicado.** Migración `2026_07_31_000002_encrypt_network_credentials_in_place`:

1. Ensancha a `TEXT` las columnas a cifrar (un valor cifrado ronda 230-250 caracteres y no
   cabe en `varchar(255)`).
2. Cifra **en la misma columna** y **con el modelo**, no con SQL crudo — que fue justo el
   error de la migración de 2026-05-14: el cast `encrypted` cifra al escribir *por modelo*,
   no en un `UPDATE`, y por eso aquellas columnas quedaron en claro y el cast lanzaba
   `DecryptException` en cada lectura.
3. Elimina las columnas `*_encrypted`, que sólo contenían un duplicado en claro.

Es idempotente: un valor que ya descifra se deja intacto. Los casts se activaron en `Router`,
`Sectorial` y `CustomerProfile`, así que **ningún punto de llamada cambia**:
`$router->password_rb` sigue devolviendo el valor en claro.

> **No se cifran los campos por los que se filtra en SQL** (`pppoe_username` tiene índice
> único por router): un valor cifrado no es consultable.

> **Riesgo evitado durante la implementación:** se llegó a añadir `password_rb` a `$hidden`
> para no serializarlo. Se retiró al comprobar que `RouterEdit.vue` prellena el formulario
> con `data.password_rb` y lo reenvía al guardar — ocultarlo habría **borrado la credencial
> del router en la primera edición**. Anotado como pendiente **P-2**.

> 🔧 **Requiere ejecución** con `migrate:both`, y **después** de rotar credenciales (C-1).

### ✅ A-4 · El guardián del frontend no reproducía el bypass de administrador

Había dos implementaciones de `hasPermission` con lógica distinta, y el guard de
`vue-router` usaba precisamente la que **no** tenía el bypass de superadministrador. Un
administrador al que le faltara un permiso concreto quedaba bloqueado en la navegación
aunque el backend sí le hubiera dado acceso — el síntoma exacto del incidente de
`manage_document_templates`.

**Aplicado.** `stores/auth.js` replica ahora el criterio de `CheckPermission`
(`role_id == 1` → acceso total). `resources/js/services/auth.js` se **eliminó**: no lo
importaba nadie y era la única fuente de la divergencia.

### ✅ A-5 · Política de seguridad de contenido permisiva

**Aplicado.** Tres directivas retiradas, **cada una verificada empíricamente antes de
tocarla** — no se quitó nada «por si acaso»:

| Retirado | Verificación |
|---|---|
| `'unsafe-eval'` de `script-src` | Se compiló el bundle de producción y se comprobó que **no contiene** `eval(` ni `new Function(` |
| `https://unpkg.com` | Único CDN externo; sólo lo usaba el mapa para cargar `@googlemaps/markerclusterer`. **Ahora se empaqueta** con la aplicación (`npm i @googlemaps/markerclusterer`) y no aparece en el bundle |
| `'unsafe-inline'` de `script-src` | Los dos únicos scripts en línea eran `onclick="window.location.href=..."` en el portal de pago; convertidos en enlaces `<a>` |

Añadidas además `object-src 'none'`, `base-uri 'self'` y `form-action 'self'`.

`style-src` **conserva** `'unsafe-inline'` a propósito: Vue inyecta estilos en línea para los
componentes con `<style scoped>` y quitarlo rompe el render.

Retirar el CDN cierra también un riesgo de cadena de suministro: un `unpkg` comprometido
inyectaba script arbitrario en una página autenticada.

### ✅ A-6 · `users.permissions` era una columna muerta

La columna existía, estaba en `$fillable` y en `$casts`, y la interfaz permitía asignar
permisos individuales — pero **nada los leía**. Conceder un permiso individual no tenía
ningún efecto ni ningún aviso: fallo de configuración silencioso. En producción hay **6
usuarios** con valores ahí que nunca surtieron efecto.

**Aplicado.** `User::effectivePermissions()` y `User::hasPermission()` hacen la unión
rol + usuario. La unión sólo **concede**, nunca revoca: un permiso individual no puede
quitar lo que el rol ya da. Cubierto por test.

### ✅ A-7 · `getPermissionsByRole()` y `role.permissions` podían divergir

**Aplicado.** Comando `permissions:sync` (`app/Console/Commands/SyncRolePermissions.php`):

- **Aditivo**: sólo añade permisos que falten. Nunca quita, porque un tenant puede haber
  ajustado su rol a mano y una sincronización no debe deshacer ese trabajo.
- Identifica los roles por **`code`**, no por `name`: el nombre es texto libre por tenant
  (`Tecnico` vs `Técnico`).
- **No toca los roles personalizados** (sin `code` canónico).
- Admite `--dry-run` y `--tenant=`.

Ejecutado en seco contra producción: **los 30 roles ya están sincronizados**, lo que
confirma que el backfill manual anterior se hizo bien. El comando es la red para el próximo
permiso. Cubierto por 7 tests.

---

## 5. Resueltos — prioridad media

### ✅ M-1 · Cobertura de pruebas desequilibrada

**Aplicado.** 49 tests nuevos y 10 rescatados:

| Archivo | Tests | Cubre |
|---|---:|---|
| `Feature/Auth/ApiAuthorizationTest.php` | 42 | Permiso por endpoint, semántica OR, bypass de superadmin, unión de permisos, 401 sin sesión |
| `Feature/Auth/ApiLoginTest.php` | 7 | Login real por `email_tenant`, verificación de correo, rate limit, no enumeración, `/auth/me` |
| `Feature/Auth/RolePermissionsSyncTest.php` | 7 | Coherencia del catálogo y comportamiento de `permissions:sync` |
| `Feature/Documents/*` | 10 | **Rescatados**: fallaban porque el test falseaba el disco `public` mientras el código escribe en `s3` |

### ✅ M-2 · Los tests sólo corrían en SQLite

**Aplicado.** `.github/workflows/tests.yml` con dos trabajos: SQLite (rápido) y
**PostgreSQL 16 + PostGIS** (el motor real), este último ejecutando migraciones antes de la
suite. Es lo único que puede detectar el booleano comparado con cadena, el `LIKE` sensible a
mayúsculas y los índices parciales.

**Dos divergencias más, medidas el 2026-08-13** (§ 28 de la bitácora), que conviene tener
presentes al escribir pruebas — ninguna se va a "arreglar", son propiedades del motor:

1. **SQLite pierde el CHECK de un `enum` si la tabla pasa por un `->change()`.** Laravel
   implementa `change()` en SQLite reconstruyendo la tabla, y el CHECK inline no sobrevive a la
   reconstrucción. En PostgreSQL sí sobrevive. Efecto práctico: **un valor de enum inventado pasa
   en local y sólo revienta en el CI real** — fue el caso de `customer_installations.status`, donde
   un test insertaba `'pending'` contra un vocabulario que es `'pendiente' | 'completada' |
   'cancelada'`. Al escribir una prueba, tomar el valor del enum de la migración, no de memoria.

2. **Un `try/catch` no protege una transacción de PostgreSQL.** Una sentencia fallida deja la
   transacción abortada y todo lo posterior revienta con `25P02`, aunque la excepción se haya
   atrapado; sólo un `ROLLBACK TO SAVEPOINT` la recupera. Toda escritura accesoria que no deba
   tumbar la operación principal (bitácora, métricas, notificaciones) tiene que ir envuelta en
   `transaction()` para que emita su propio SAVEPOINT. En sqlite la diferencia es invisible.

### ✅ M-3 · `LIKE` sensible a mayúsculas sin corregir en todas partes

**Aplicado.** `SearchMacrosServiceProvider` añade las macros `whereLike` y `orWhereLike`,
que eligen `ilike` o `like` según el driver y escapan los comodines (`%`, `_`) para que
buscar «100%» no se convierta en un comodín.

Corregidos `SupportTicketController` (usaba `like` → no encontraba «Eliud» buscando «eliud»
en producción) y **`ProspectController`, que tenía el defecto simétrico**: usaba `ilike` a
pelo, operador que SQLite no conoce, así que esa búsqueda reventaba en los tests.

### ✅ M-4 · `inventory_stock.desc` tenía tipo `date`

**Aplicado** en `2026_07_31_000003_clean_up_schema_debt`. En producción está íntegramente a
`NULL`, así que el cambio de tipo no pierde datos.

### ✅ M-5 · Llave foránea duplicada en `service_plan.tenant_id`

**Aplicado.** La migración detecta las FK sobre esa columna, conserva la de `SET NULL`
(coherente con el resto) y elimina la redundante.

### ✅ M-6 · Reglas `ON DELETE` inconsistentes

**Aplicado.** `invoices.tenant_id`, `payments.tenant_id` y `router.tenant_id` pasan de
`NO ACTION` a `CASCADE`. Las tablas hijas de esas tres (`invoice_items`,
`payment_allocations`, `traffic_*`) ya iban en cascada, así que el borrado ya lo era a partir
del segundo nivel: lo único que hacía `NO ACTION` era impedir dar de baja un tenant con un
error de clave foránea sin explicación.

### ✅ M-7 · Índices de rendimiento ausentes

**Aplicado** en `2026_07_31_000004`:

```sql
invoices     (customer_id, status)      -- listado por cliente y filtro por estado
invoices     (tenant_id, period_start)  -- auditoría mensual
invoices     (due_date) WHERE balance_due > 0   -- índice parcial: consulta exacta del corte
user_services(user_id, status)          -- lo pregunta la generación por CADA cliente
payments     (customer_id, payment_date)
```

### ✅ M-8 · Sin índice que garantizase la unicidad de IP por router

La regla se validaba **sólo** en `CustomerProfileController`; cualquier otra vía de escritura
podía duplicarla. **Aplicado** el índice único parcial, gemelo del que ya protege
`pppoe_username`. Verificado antes: **0 duplicados** en producción, así que la creación no
puede fallar.

### ✅ M-9 · Tablas muertas

**Aplicado** en `2026_07_31_000005`: se eliminan `ip_range`, `router_ip_range`,
`ip_assignment` y `activity_log`. Las cuatro cumplen las tres condiciones, verificadas una a
una: `COUNT(*) = 0` **real**, sin modelo ni referencia en `app/`, `routes/` ni
`resources/js/`, y función cubierta por otra cosa (`router.rangos_ip`,
`customer_profile.ip_user`, `audit_logs`).

**No** se eliminan `cut_type`, `type_billing` ni `script_version`: tienen filas reales,
modelo y endpoints de catálogo (ver §2).

La migración lleva salvaguarda: si alguien empezó a usarlas entre la auditoría y el
despliegue, la tabla se deja en su sitio en lugar de perder datos.

### ✅ M-10 · Comparación de `cut_type` por nombre literal *(reclasificado desde C-3)*

**Aplicado.** `CutType` expone constantes (`AUTOMATIC`, `MANUAL`, `NONE`) y un
`matches()` que normaliza **tildes, mayúsculas y espacios** antes de comparar, más los
helpers `isAutomatic()` / `isManual()`. `OverdueSuspensionService` los usa en sus dos puntos
de decisión.

Se añade además `2026_07_31_000001_ensure_cut_type_catalog_rows`, que garantiza el catálogo
en todos los esquemas **por migración y no por seeder**: `migrate:both` nunca siembra
`public`, así que un catálogo que sólo exista por seeder puede acabar vacío en producción.

---

## 6. Resueltos — prioridad baja

### ✅ B-1 · `audit_logs` implementado pero nunca invocado

**Aplicado.** Instrumentadas las cuatro acciones de mayor impacto:

| Acción | Por qué esa |
|---|---|
| `invoice.deleted` | Deja una lápida que impide regenerar la factura: la acción **menos reversible** del módulo. Se audita **antes** de borrar, o se perdería el importe y el periodo |
| `role.permissions_updated` | Cambia lo que puede hacer **todo** el personal con ese rol. Guarda el antes y el después |
| `customer.suspended_manually` | Deja al cliente sin servicio y **no** se revierte solo al pagar |
| `customer.activated_manually` | Contrapartida de la anterior |

### ✅ B-2 · `.env.testing` versionado

**Aplicado.** `git rm --cached` + `.env.testing.example`.

### ✅ B-3 · Parámetro `tenant` residual en el interceptor de axios

**Aplicado.** Interceptor eliminado. Era peor que inútil: sugería que el cliente elige su
propio tenant, que es justo la vulnerabilidad que se corrigió, y llevaba a intentar
«arreglar» cosas cambiando ese parámetro.

### ✅ B-4 · Convención de nombres de tabla inconsistente

**Documentado** en [`BASE_DATOS.md §1`](BASE_DATOS.md#1-convenciones-y-arquitectura-de-datos).
No se renombra: el coste supera al beneficio.

### ✅ B-5 · Documentación desincronizada

**Aplicado.** Los ocho documentos de `docs/` reflejan el código actual y el `README.md` lleva
la tabla de «qué documento actualizar según lo que cambies».

### ✅ B-6 · Restos de Livewire/Volt

**Verificado y resuelto.** `routes/auth.php` **no estaba registrado** en `bootstrap/app.php`,
así que no existía ninguna superficie de autenticación paralela — la sospecha inicial era
infundada. Lo que sí había era código muerto: el archivo referenciaba un controlador
(`App\Http\Controllers\Auth\VerifyEmailController`) y unas vistas Volt que **no existen**.

Eliminado, junto con los **19 tests de andamiaje de Breeze** que probaban esas rutas y
componentes inexistentes y llevaban años en rojo. En su lugar se escribió `ApiLoginTest`,
que cubre el flujo de acceso real.

> **Por qué importa:** una suite con 34 fallos permanentes no es una red de seguridad — nadie
> la mira, y un fallo nuevo se pierde entre el ruido.

---

## 7. Pendientes

### 🟡 P-RADIUS-1 · El snapshot de respaldo puede reconectar a un cortado reciente

**Deuda aceptada conscientemente**, no un descuido. Ver § 32.3 de la bitácora.

El diseño RADIUS usa `rlm_rest` con ISPWatch como fuente de verdad, lo que pone a la API en el
camino crítico de cada autenticación. Para que una caída de ISPWatch no deje a toda la red sin
conectar, FreeRADIUS cae a un **snapshot SQL local** que se sincroniza cada 5 minutos.

La consecuencia: un cliente cortado por mora hace **menos de 5 minutos** puede reconectarse
durante una caída de ISPWatch, porque el snapshot todavía lo tiene al día.

**Por qué se acepta.** La alternativa es rechazar toda autenticación que no pueda confirmarse
contra la BD, o sea dejar sin servicio a los clientes al día para no darle 5 minutos de gracia a
un moroso. El compromiso está deliberadamente del lado de la continuidad del servicio.

**Si alguna vez molesta**, la salida no es bajar el intervalo (multiplica la carga de sync sin
cerrar la ventana): es que el pipeline de corte escriba el estado del moroso directo al snapshot
además de a Postgres, para que la degradación herede el corte al instante.

### 🟡 P-RADIUS-2 · Doble contabilidad de tráfico sin fuente autoritativa

`radius_sessions` (octetos por sesión, vía Accounting) y el historial WAN existente
(`traffic_samples`, contadores por interfaz) miden cosas distintas y **no van a coincidir**: uno
cuenta tráfico de cliente autenticado, el otro todo lo que cruza la WAN del router.

Antes de mostrar ambos en el panel hay que decidir cuál manda para cada pregunta, o soporte va a
recibir llamadas por dos números distintos en dos pantallas de la misma app.

### 🔴 P-00 · 91 clientes con dinero recibido que no respalda nada (producción)

Detectado el 2026-08-13 con el comando nuevo `billing:verify-orphan-payments`, que comprueba
por cliente la invariante `sum(pagos) == sum(aplicado a facturas) + saldo a favor`:

| Medida | Valor |
|---|---|
| Clientes descuadrados | **91** |
| Importe sin respaldo | **$5.709.350** |
| Periodos con lápida (`suppressed`) | 27 — 8 de julio, 19 de agosto |
| Facturas sin ningún ítem | 37 |

Es dinero **recibido de verdad**: el recaudo sigue en la tabla, pero hoy no paga ninguna
factura ni figura como saldo a favor del cliente. La causa dominante es el flujo de "borrar
la factura y crear otra": el borrado devuelve el importe como saldo a favor y después, o se
ajusta ese saldo a mano, o la factura de reemplazo nace sin consumirlo. Los tres huecos de
código ya están tapados (§ 30 de la bitácora); **los datos ya descuadrados no**.

**Recomendación.** No corregirlo en bloque: cada caso necesita criterio, y un script masivo
movería dinero real sobre una suposición. Recorrer la lista por importe descendente
(`php artisan billing:verify-orphan-payments --limit=100`) y por cada cliente decidir entre
devolver el importe al saldo a favor o reasignar el pago a la factura que corresponda.

**Antes de empezar, desplegar `feat/money-audit-trail`**: parte del descuadre puede venir del
bug de anulación de pagos que esa rama arregla, y sin ella las correcciones no quedan
registradas en el libro de auditoría — que es justamente lo que se necesita aquí.

### 📋 P-0 · La devolución de saldo al borrar una factura no des-consume el origen

Al borrar una factura que había sido pagada con saldo a favor, el saldo vuelve al cliente como un
movimiento `adjusted`, pero **no se des-consumen los `earned` originales** que lo habían pagado.

Es el lado conservador a propósito —nunca destruye saldo, que es el error caro— a costa de que
anular después el pago de origen ya no arrastre esa devolución: se devolvería solo la parte que
nunca se consumió, y el saldo restituido se queda con el cliente.

**Recomendación.** Si algún día hace falta exactitud total, `CustomerCredit` tendría que
des-consumir en orden LIFO los `earned` que financiaron esa factura, en vez de compensar con un
ajuste. Hoy no compensa la complejidad: el caso es raro y el error resultante siempre favorece al
cliente, nunca al ISP.

### 📋 P-1 · Falta un permiso `delete_clients`

Borrar un cliente se apoya hoy en `edit_internet_service` porque el catálogo no tiene un
permiso propio para ello. Es más laxo de lo deseable: quien puede editar el servicio puede
borrar al cliente con todo su historial.

**Recomendación.** Añadir `DELETE_CLIENTS` a `Permissions`, incluirlo en los roles
`admin` y `staff` y ejecutar `permissions:sync` (que ya existe justamente para esto). Es
seguro porque el sync es aditivo y cubre los 30 roles canónicos.

### 📋 P-2 · Las contraseñas de router se serializan en la API

`password_rb` y `vpn_password` viajan en la respuesta de `GET /api/routers/{id}`. No se
pusieron en `$hidden` porque `RouterEdit.vue` prellena el formulario con ese valor y lo
reenvía al guardar: ocultarlo **borraría la credencial** en la primera edición.

**Recomendación.** Cambiar el formulario a «dejar en blanco para conservar la contraseña
actual» (el controlador ignora el campo si llega vacío) y sólo entonces añadirlas a
`$hidden`. Es trabajo de frontend, no de backend.

### ✅ P-3 · Un placeholder de otro tipo de documento se blanquea sin avisar

> **Resuelto el 2026-08-06** junto con P-13. `App\Services\Templates\TemplateDiagnostics`
> detecta el caso con su propio `kind` (`wrong_type`) y un mensaje distinto al de un typo:
> *"Existe, pero sólo en la plantilla de Factura. En Contrato sale en blanco."* Cubre tanto
> escalares como bloques de los 3 tipos. El diagnóstico sigue corriendo **fuera** del render:
> el token se sigue blanqueando, exactamente igual que antes.
> Ver `BITACORA_TECNICA.md` § 16. El diagnóstico original se conserva abajo.

Si un tenant pega `{{factura.tabla_items}}` (o cualquier `{{factura.*}}`) dentro de la
plantilla de **contrato**, el sistema lo blanquea a `''` en silencio — mismo camino que un
typo genuino (`{{factura.tabla_item}}` sin la "s"). El mecanismo de aviso `X-Template-Warnings`
**no** lo cubre: sólo reporta bloques que sí correspondían al tipo de documento actual pero no
se pudieron insertar en su posición (ej. dentro de un atributo HTML); un token de otro tipo ni
siquiera llega a generar un marcador, así que `BlockMarkerInjector` nunca lo ve. Verificado
empíricamente el 2026-08-01 (no es una suposición): `lastRenderWarnings()` vuelve `[]` en este
caso.

Es consistente con la regla ya aprobada de "token desconocido → vacío, sin romper el render"
(vigente desde la Fase 1 de plantillas), pero un cross-type es plausiblemente el error más común
en la práctica — copiar y pegar entre plantillas de distinto tipo — y hoy no tiene ninguna señal,
a diferencia de un bloque mal posicionado del mismo tipo.

**Recomendación.** Extender `PlaceholderResolver::apply()` (o una capa antes) para que, cuando
un token no reconocido coincida con un nombre válido en el catálogo de **otro** tipo de
documento (`config/document_placeholders.php` + `config/document_placeholder_blocks.php` de los
3 tipos), lo reporte por el mismo canal que ya existe (`lastRenderWarnings()` /
`X-Template-Warnings`) con un mensaje distinto ("este marcador es de Factura, no de Contrato")
en vez de tratarlo como un typo genérico. No se implementó porque se identificó *después* de
cerrar el alcance de la sesión que construyó el mecanismo de avisos, no por complejidad.

### ✅ P-4 · Modo avanzado: editor de texto plano, sin editor visual ni protección contra typos en el token

> **Resuelto el 2026-08-06**, por un camino distinto al que proponía la recomendación. (a) El
> typo en el token ya no pasa desapercibido: `TemplateDiagnostics` lo reporta con sugerencia por
> cercanía, sin necesidad de un Blot atómico de Quill. (b) La ayuda visual llegó al reemplazar
> Quill por `HtmlDocumentEditor` (iframe editable): ahora el modo normal **muestra el documento
> como va a salir en el PDF**, y el interruptor de modo avanzado es una vista del mismo
> contenido, no otro contenido. El `<textarea>` del modo avanzado se conserva a propósito: para
> editar el código fuente es lo correcto. Ver `BITACORA_TECNICA.md` § 16 y § 17.
> El diagnóstico original se conserva abajo.

El modo avanzado de plantillas (HTML/CSS completo) se implementó con un `<textarea>` simple,
a propósito ("sin editor visual sofisticado, eso se mejora después" — decisión explícita del
2026-08-01 para cumplir un plazo). Un tenant en este modo puede: (a) escribir un placeholder mal
y no enterarse (mismo comportamiento que P-3, pero más fácil de gatillar sin autocompletado),
(b) no tener ninguna ayuda visual de qué placeholders existen mientras escribe.

**Recomendación.** Evaluado y descartado para V1 un Quill Blot custom (chip no editable/atómico
tipo "[📊 Tabla de ítems]") que eliminaría la clase completa de "typo en el token" — más caro
en tiempo de desarrollo, se decidió no construirlo sin evidencia real de que se necesita. Si
soporte empieza a recibir tickets de "mi plantilla avanzada no muestra X", es la señal de
retomarlo.

### 📋 P-5 · Modo avanzado no permite imágenes de fondo vía CSS, ni siquiera desde un host propio

`background-image`, `background` (shorthand) y `list-style-image` están **excluidas a
propósito** del allowlist de `AdvancedTemplateSanitizer` — ninguna propiedad que sólo tenga
sentido con `url()` está permitida, para que `url()` quede bloqueado por diseño del allowlist
y no sólo por el filtro de esquema (`URI.AllowedSchemes`). Efecto secundario: un tenant no
puede poner una imagen de fondo en su factura desde CSS, ni siquiera apuntando a un host
`https://` explícitamente permitido. Sí puede lograr un efecto similar con `<img>` en el body
(sí está permitido, con `src` validado por el mismo filtro de esquema).

**Recomendación.** Si se pide esta capacidad, la opción más segura no es abrir `url()` en CSS
— es agregar un flujo de subida de imagen propio (como ya existe para el logo del tenant,
`POST /api/tenant/logo`) y exponerla como un placeholder de bloque (`{{empresa.imagen_fondo}}`)
en vez de como CSS libre, manteniendo la garantía de "cero `url()` en CSS" intacta.

**Actualización 2026-08-03.** Exactamente este patrón ya se implementó para el logo
(`{{empresa.logo}}`, `BlockPlaceholderResolver::resolveLogo()`, ruta local vía
`public_path('storage/'.$tenant->logo)`, nunca `url()` en CSS ni una URL remota) — disponible en
los 3 tipos de documento. Un `{{empresa.imagen_fondo}}` seguiría el mismo precedente casi sin
trabajo nuevo si se pide.

### 📋 P-6 · La `APP_KEY` de este `.env` local no desencripta los datos cifrados sincronizados desde producción

Detectado 2026-08-04 al investigar por qué `GET /api/tenants/{id}` devolvía 500 y "reseteaba"
la marca (logo/color/pie de página) en cada recarga de **Configuración → Plantillas de
Documentos**. La causa real no tenía nada que ver con plantillas ni con branding:
`TenantController::show()` intentaba leer `$tenant->google_maps_api_key` (columna con cast
`encrypted`, ajena por completo al resto del payload) sólo para calcular un booleano, y el
valor guardado no se puede desencriptar con la `APP_KEY` de este `.env` — `DecryptException:
The MAC is invalid`.

Verificado que **no es un caso aislado**: en `ispwatch_dev` fallan también `Router.password_rb`,
`Router.wg_private_key`, `CustomerProfile.pppoe_password`/`hotspot_password` (todas las columnas
con cast `encrypted` que se probaron). **También falla igual apuntando directo al schema
`public`** (producción, mismo `.env`/misma `APP_KEY` de este equipo, sólo lectura) — es decir,
esta `APP_KEY` local nunca coincidió con la que cifró esos datos, en ninguno de los dos schemas.
La hipótesis con más evidencia es que **no es una incidencia de producción**: la app real en
DigitalOcean App Platform tiene su propia `APP_KEY` en sus variables de entorno (no en este
archivo), separada de la de este equipo por diseño — si production no pudiera desencriptar sus
propios datos, routers y túneles VPN estarían caídos de forma visible en vivo, no sólo en una
pantalla de configuración local. Sigue siendo una hipótesis: nadie ha comparado todavía la
`APP_KEY` real de App Platform contra la de este `.env`.

**Ya corregido (2026-08-04):** `TenantController::show()`/`mapsConfig()` ahora aíslan el acceso a
`google_maps_api_key` en `safeGoogleMapsApiKey()` — un valor no desencriptable ya no tumba el
resto del payload del tenant (branding, nombre, dirección...), sólo hace que
`has_google_maps_key`/`has_key` reporten `false`. Esto resuelve el síntoma inmediato (branding
otra vez visible) pero **no** resuelve el problema de fondo: mientras la `APP_KEY` local no
coincida con la que cifró los datos, este equipo seguirá sin poder leer contraseñas de router,
llaves WireGuard ni contraseñas PPPoE/hotspot sincronizadas desde producción — cualquier feature
de dev que dependa de desencriptar esos campos (ej. probar una conexión SSH a un router real
sincronizado) seguirá fallando en silencio o con `DecryptException` sin capturar.

**Recomendación.** Confirmar contra las variables de entorno reales de App Platform si la
`APP_KEY` de producción coincide con la de este `.env` (sin pegarla en ningún sitio inseguro,
sólo confirmar que son la misma). Si no coincide — lo más probable — no hay nada que "reparar":
es el comportamiento esperado de tener `APP_KEY` distintas entre entornos por seguridad, y
`db:sync-dev`/una copia directa de `public` seguirán trayendo ciphertext indescifrable a menos
que se traiga también la `APP_KEY` real (sólo si el equipo decide que vale la pena tener esa
clave en local, con el riesgo que eso implica). Aplicar el mismo patrón de
`safeGoogleMapsApiKey()` (degradar a `null` + loguear, nunca tumbar todo un endpoint) en
cualquier otro sitio que lea un campo `encrypted` fuera de un flujo que ya lo espera
explícitamente (ej. conexión real a un router).

### ✅ P-7 · El whitelist de contrato no tiene "departamento"/"ciudad" del cliente — RESUELTO 2026-08-05

Detectado 2026-08-04 preparando un HTML real de cliente (exportado de WispHub) para probar contra
el modo avanzado: el contrato original usaba `{{cliente.localidad}}` (Departamento) y
`{{cliente.ciudad}}` (Municipio) — ninguno de los dos existe en
`config/document_placeholders.php` para `contract` (sólo hay `cliente.direccion`, que en la
práctica es sólo la dirección de calle, sin departamento/ciudad por separado). Se reemplazaron por
texto literal `(no disponible en ISPwatch)` en el HTML de prueba corregido, no por un placeholder
real — de lo contrario se habrían blanqueado en silencio sin que nadie notara por qué.

**Resuelto 2026-08-05** (confirmado explícitamente por el usuario). Se agregaron
`cliente.ciudad` (Municipio) y `cliente.departamento` (Departamento) a la whitelist de
`contract` y a `PlaceholderResolver::forContract()`, resueltos desde
`customer_profile.city` / `.state` — las columnas existen desde la migración
`2025_12_22_163903_add_location_fields_to_customer_profile_table`, verificado antes de
implementar en vez de asumir el nombre.

Se eligió `cliente.departamento` y **no** `cliente.localidad` (el nombre de WispHub): la
columna se llama `state` y "departamento" es el término del formato CRC, mientras que
"localidad" en Colombia significa otra cosa (subdivisión de Bogotá). La equivalencia con el
nombre de WispHub quedó documentada en la tabla de migración de marcadores de
`docs/MANUAL_USUARIO.md`. Ver `docs/BITACORA_TECNICA.md` § 15.4.

### 📋 P-8 · dompdf recorta el contenido de una celda de tabla más alta que una página

Detectado 2026-08-04 diagnosticando páginas en blanco en un contrato real exportado de WispHub.
dompdf **no sabe partir una celda de tabla entre páginas**: si el contenido de un `<td>` excede el
alto de la hoja, empuja la celda entera a la página siguiente (dejando la anterior en blanco) y
**descarta en silencio lo que no cabe** — sin excepción, sin log, sin warning.

Medido sobre el mismo documento, con el texto plano verificado idéntico antes y después de la
conversión:

| Bloque "TRATAMIENTO DE DATOS" | Páginas | En blanco | Texto extraído |
|---|---|---|---|
| Como `<table>` (original) | 7 | 1 | 15.847 caracteres |
| Como `<div>` | 6 | 0 | **17.682 caracteres** |

Es decir: **~1.800 caracteres de texto legal se estaban perdiendo del PDF firmado**, no sólo
maquetando mal. En un contrato eso no es cosmético.

**Por qué no se corrige en el sanitizer.** Saber si una celda va a desbordar exige renderizar
primero (depende del contenido resuelto, del tamaño de papel y de la fuente). Convertir toda tabla
de una celda a `<div>` a ciegas cambiaría el diseño de plantillas que hoy funcionan bien. Las otras
dos causas de páginas en blanco sí se automatizaron
(`AdvancedTemplateSanitizer::fixDompdfPaginationQuirks()`) precisamente porque son deterministas y
no dependen del contenido.

**Recomendación.** (a) Documentar la regla para quien edite plantillas en modo avanzado: *no
envuelvas texto largo en una celda de tabla, usa `<div>`* — ya reflejado en `MANUAL_USUARIO.md`.
(b) Evaluar un chequeo en la vista previa que detecte celdas sospechosamente largas y avise por el
header `X-Template-Warnings`, reutilizando el mecanismo que ya existe para bloques huérfanos. Es la
única forma de que el tenant se entere sin tener que comparar el PDF carácter por carácter.

### 📋 P-9 · Auditoría de Finanzas (2026-08-05): deuda restante tras ejecutar el plan

Auditoría de UX/rendimiento sobre Facturación, Pagos/Recaudos, Servicios Adicionales, Gastos y
Categorías de Gasto. Las **Fases 1** (debounce y guard anti-carrera en Facturación, índices
compuestos, búsqueda de texto en Gastos), **2** (paginación y agregados server-side en Gastos) y
**3** (totales en dinero en Facturación y Recaudos), **4** (exportación a CSV de los tres
listados), **5** (unificación visual bajo el acento esmeralda) y **6** (historial de Servicios
Adicionales) están **todas implementadas**. La 6 se resolvió con un atajo a Facturación filtrada
por tipo, no con un listado propio — el razonamiento está en el registro de decisiones de
`BITACORA_TECNICA.md`.

**Lo que queda es deuda identificada, no plan pendiente:**

- La búsqueda por cliente en Facturación y Recaudos usa `whereHas` + `ILIKE`, que no aprovecha
  índice B-tree. Al volumen actual no duele; sería el próximo cuello de botella real y la salida
  sería `pg_trgm`/GIN.
- Quedan otros componentes de `resources/js/components/ui/` sin usar en todo el proyecto
  (`SearchBar`, `StatusBadge`, `LoadingSkeleton`), andamiaje de un intento anterior de sistema de
  diseño. La Fase 5 reescribió y puso en uso `PageHeader` y añadió `StatCard`; los tres restantes
  siguen siendo código muerto: o se adoptan, o conviene borrarlos para que nadie los tome por el
  estándar vigente.
- El patrón "crear un `<a>`, hacerle click y olvidarlo" para descargar blobs está repetido en
  **13 sitios** (PDFs de factura, plantillas, importadores…). Ninguna de esas copias quita el
  elemento del DOM ni llama a `URL.revokeObjectURL()`, así que el blob queda retenido en memoria
  hasta recargar la página. La Fase 4 introdujo `resources/js/utils/download.js`, que sí limpia;
  migrar las 13 llamadas restantes es un cambio mecánico pero toca flujos ajenos a Finanzas
  (PDFs, importación), así que se dejó fuera del alcance de la fase.

### 📋 P-10 · La factura de excepción no cobra el arrastre pendiente

**Contexto.** Un cliente sin plan que cobrar (sin `user_services` activo, o con plan de cortesía
permanente) pero con servicios adicionales recibe una factura sólo con ellos —
`BillingService::issueAdditionalOnlyInvoice()`. Esa factura aplica el saldo a favor del cliente,
pero **no** llama a `applyPendingCarryoversTo()`.

**Consecuencia.** Si ese cliente abonó parcialmente una factura anterior, el saldo arrastrado queda
en `invoice_carryovers` con estado `pending` **para siempre**: el arrastre se cobra en la siguiente
factura mensual, y este cliente nunca recibe una. Es plata que se deja de cobrar sin que nadie se
entere.

**Por qué se dejó así.** La factura de excepción se diseñó para cobrar una cosa concreta e
identificable — el alquiler del equipo —, y mezclarle deuda de un abono viejo sorprendería al
cliente que la recibe. Añadirlo es una línea (`$this->applyPendingCarryoversTo($invoice)`), pero
cambia lo que esa factura significa, y el caso es la intersección de tres situaciones poco
frecuentes: cliente sin plan **y** con adicionales **y** con un abono parcial previo.

**Recomendación.** Decidirlo explícitamente cuando aparezca el primer caso real. Si se opta por
cobrarlo, el aviso al cliente debería desglosar las dos cosas para que no parezca un cobro doble.

### 📋 P-11 · `billing:generate-tenant` sigue siendo una segunda ruta de facturación

`GenerateTenantInvoicesOneOff` crea mensualidades **sin pasar por**
`BillingService::createMonthlyInvoiceFor()`: duplica la creación de la factura, el ítem del plan
y la aplicación del saldo a favor. Su propio encabezado dice *"ONE-OFF ops command (safe to delete
after use)"*.

Ya mordió una vez: al añadir los servicios adicionales recurrentes, este comando habría facturado
de menos y en silencio. Se parcheó llamando a `addRecurringExtrasTo()`, pero el problema de fondo
sigue: **cualquier cosa nueva que entre en la factura mensual hay que acordarse de replicarla
aquí**, y el día que alguien no se acuerde, el error no dará ninguna señal.

**Recomendación.** Borrarlo si ya cumplió su propósito (es la opción limpia), o reescribirlo para
que delegue en `createMonthlyInvoiceFor()` con un flag que suprima las notificaciones — que es lo
único que justificaba tener una copia.
### 📋 P-9 · Los documentos anteriores al paso a S3 pueden estar perdidos, y la interfaz no lo distingue

Hasta el 29-jul-2026 (`828865c`) los documentos de cliente se escribían en el disco `public`
de Laravel y se servían con `asset('storage/…')`. En App Platform el sistema de archivos del
contenedor es **efímero**: en cada despliegue los bytes desaparecían mientras las filas de
`customer_documents` sobrevivían. Ese es el origen del síntoma «subo los documentos y después
no se ven». El almacenamiento ya está corregido (todo va a S3, con URL firmada de 30 minutos),
pero quedan dos cabos:

1. **`documents:migrate-to-s3` sólo rescata lo que siga vivo en el disco local**, y sólo si se
   ejecuta desde la misma instancia que recibió los archivos. Si producción se redesplegó antes
   de correrlo, esos bytes ya no existen en ninguna parte. **No consta que se haya ejecutado.**
2. **La convención de rutas no cambió, sólo el disco**, así que una fila vieja y una nueva se
   ven idénticas: `file_path` no permite distinguirlas. La interfaz pinta la tarjeta igual y el
   enlace devuelve un error del proveedor, sin explicación para el usuario.

**Recomendación.** Un comando de auditoría que recorra `customer_documents` comprobando
`Storage::disk('s3')->exists($file_path)` y, o bien marque las filas huérfanas con una columna
propia, o las liste para decidir si se purgan. Sin eso, el operador no puede distinguir «este
documento se perdió en la migración» de «hay un problema con el almacenamiento ahora mismo», y
cada caso llega a soporte como un bug nuevo.
### 📋 P-10 · Eliminar un cliente no lo desaprovisiona del router

`CustomerProfileController::destroy()` borra `customer_profile` y `users` dentro de una
transacción y nada más: **no llama a `suspendCustomer()` ni a ninguna rutina de limpieza en
RouterOS**. La cola/secret/binding del cliente se queda en el equipo y el cliente **sigue
navegando**, ahora además invisible para el sistema — no aparece en ninguna lista, así que
nadie lo detecta salvo por consumo anómalo.

Es la variante silenciosa de la fuga de ingreso que el producto existe para cerrar. Detectado
al verificar el manual de usuario el 2026-08-03; documentado como advertencia en
`MANUAL_USUARIO.md` §5.5 mientras no se resuelva en código.

**Recomendación.** Antes de borrar, ejecutar el mismo camino que la suspensión manual
(`RouterProvisioningService::suspendCustomer`) o una limpieza dedicada, y registrar el intento
en `suspension_action_logs` para que el failover lo reintente si el equipo no responde. Un
borrado que falla en el router no debería abortar el borrado en BD, pero **sí** debe quedar
registrado: hoy no queda rastro de ninguna clase.

### 📋 P-11 · `$monthlyRevenue` se calcula en el Dashboard y nunca se usa

En `DashboardController::stats()` se consulta la suma de facturas `paid` emitidas en el mes
(`$monthlyRevenue`) y luego la respuesta devuelve `revenue.monthly => $monthlyPayments` — los
**pagos** recibidos en el mes. La variable calculada queda muerta: es una consulta agregada por
petición al Dashboard que no alimenta nada.

Las dos métricas son legítimas pero distintas (facturado-y-cobrado del mes vs. caja del mes), y
hoy no está claro cuál se quiso mostrar. El manual documenta **el comportamiento real** (pagos).

**Recomendación.** Decidir producto: si la tarjeta debe seguir siendo caja, borrar
`$monthlyRevenue`; si debía ser lo facturado, cambiar la clave de la respuesta y avisar del
cambio de significado. No tocarlo a ciegas — el número que hoy ve el operador cambiaría.

### 📋 P-12 · El Centro de Ayuda no tiene forma sancionada de actualizarse en producción

El contenido que el usuario lee dentro de la app vive en `help_categories` / `help_articles` y
lo produce `HelpCenterSeeder`. Hay dos problemas encadenados:

1. **`migrate:both --seed` omite `public` a propósito** (`MigrateBothSchemas`: *"los datos solo
   se crean en ispwatch_dev"*). La regla es correcta para catálogos y data demo, pero el Centro
   de Ayuda **no es data demo: es contenido de producto**. Resultado: no existe un camino
   sancionado para publicar una corrección del manual, hay que correr
   `db:seed --class=HelpCenterSeeder` a mano contra `public`.
2. **`HelpCenterSeeder::run()` empieza con `HelpArticle::query()->delete()` y
   `HelpCategory::query()->delete()`.** Es un reemplazo total, no un upsert. Hoy eso es
   inofensivo — verificado el 2026-08-03 contra `public`: 30 artículos, 9 categorías, **cero**
   con `updated_at > created_at`, o sea nadie ha editado nada desde la UI. Pero el Centro de
   Ayuda **tiene editor de superadmin**: en cuanto alguien escriba un artículo desde la app, el
   siguiente seed lo borra sin aviso.

**Recomendación.** Convertir el seeder en idempotente por clave estable (`updateOrCreate` sobre
un `slug` de categoría/artículo, que hoy no existe) y borrar sólo lo que el propio seeder
gestiona, dejando intacto lo creado desde la UI. Con eso, publicar contenido deja de ser
destructivo y se puede permitir en `public` sin contradecir la separación dev/prod — que es lo
que hoy obliga a elegir entre "no actualizar el manual" y "correr un seeder destructivo contra
producción a mano".



### 📋 Observación menor

El portal de pago (`resources/views/payment-portal.blade.php`) muestra un teléfono de
soporte y un WhatsApp **fijos en el código** (`+573001234567`), iguales para todos los
tenants. Deberían salir de `tenant.billing_phone`.

---

### ✅ P-13 · Migrar una plantilla desde otro sistema no tiene ninguna ayuda dentro de la app

> **Resuelto el 2026-08-06.** `App\Services\Templates\TemplateDiagnostics` inspecciona el
> borrador crudo y devuelve hallazgos con el mensaje ya redactado, por
> `X-Template-Warnings` (vista previa) y por la clave `warnings` del JSON (guardar, que es
> donde más importa: guardar activa la plantilla). Detecta marcadores ajenos con y sin
> llaves, cross-type (P-3), typos con sugerencia por cercanía, e imágenes remotas.
> Equivalencias en `config/document_placeholder_aliases.php`, por tipo de documento.
> **No se traduce automáticamente**, a propósito — ver el razonamiento en
> `BITACORA_TECNICA.md` § 16.3. Contra el HTML real que originó el reporte: 10 hallazgos,
> cero falsos positivos. El diagnóstico original se conserva abajo.

Detectado 2026-08-05 con un tenant que pegó su contrato completo exportado de WispHub en modo
avanzado y reportó que "no toma bien la plantilla". El HTML estaba bien y el sanitizer lo dejaba
prácticamente intacto; lo que fallaba eran los **nombres de los marcadores**, que no son
compatibles entre sistemas. `PlaceholderResolver::apply()` blanquea en silencio cualquier
`{{…}}` desconocido — comportamiento correcto y deliberado (un typo nunca debe romper el render),
pero indistinguible de "el sistema no funciona" desde la interfaz: el usuario ve el HTML correcto
y los datos en blanco, sin ninguna pista de por qué.

La tabla de equivalencias WispHub → ISPwatch quedó documentada en `MANUAL_USUARIO.md`, pero
**vive sólo en la documentación**: nadie la va a leer en el momento en que pega el HTML.

**Recomendación.** Reutilizar el mecanismo de `X-Template-Warnings` que ya existe para bloques
huérfanos: al previsualizar, recolectar los tokens `{{…}}` que no están en la whitelist del tipo
y devolverlos como aviso — *"3 marcadores no se reconocen y saldrán en blanco: `plan_internet.precio`,
`fecha_instalacion`, `cliente_nombre`"*. Cuando el token desconocido tenga un equivalente conocido,
sugerirlo. Es el mismo camino que P-8 propone para las celdas largas, y ataca la causa raíz de esta
clase entera de reportes. Relacionado con la nota existente sobre placeholders *cross-type* que se
blanquean sin aviso — es el mismo agujero de diagnóstico, visto desde otro ángulo.

### 📋 P-14 · Los mocks de dompdf en los tests se rompen con cada método nuevo del wrapper

Detectado 2026-08-05 al agregar `setPaper()` en `TemplateRenderer`: 14 pruebas fallaron con
`BadMethodCallException: Method Mockery_…_PDF::setPaper() does not exist on this mock object`,
apuntando al código de producción y no a la causa real.

`Barryvdh\DomPDF\PDF` **no define** la mayoría de su API fluida: la resuelve por `__call()`
reenviando al `Dompdf` interno. Mockery valida contra los métodos reales de la clase, así que
ningún método mágico existe para el mock. Se resolvió con `->shouldIgnoreMissing(\Mockery::self())`
en los 26 sitios donde se mockea el PDF.

**Por qué sigue siendo deuda.** El arreglo es correcto pero está copiado en 8 archivos de prueba, y
la trampa vuelve en cuanto alguien llame a otro método del wrapper. Un helper en `Tests\TestCase`
(`fakePdf()`) centralizaría la construcción y dejaría el motivo escrito una sola vez, en vez de
obligar a cada quien a redescubrir por qué el mensaje de error miente sobre dónde está el problema.

### 📋 P-15 · La vista previa del editor nunca va a ser idéntica al PDF mientras el motor sea dompdf

Detectado 2026-08-06 (ver `BITACORA_TECNICA.md` § 18 y § 21). El editor visual ya reproduce el **ancho
imprimible real** (calculado por `PdfPageGeometry`, no copiado a ojo), imita los defaults de dompdf
—margen del `body`, `font-size`, `line-height`—, dibuja los cortes de página, muestra el logo puesto
en su sitio y marca las imágenes remotas. Y desde § 21 hay un panel con el **PDF real** al lado, que
es lo que zanja cualquier duda concreta. Pero el editor sigue siendo un navegador y el PDF lo genera
**dompdf**, que implementa un subconjunto pobre de CSS 2.1: `float`, `position`, flexbox, grid y buena
parte del box model se comportan distinto. Cualquier plantilla que se apoye en ellos va a seguir
divergiendo **dentro del editor**, y no hay forma de arreglarlo desde ese lado.

Se suma una limitación que tampoco se puede tapar desde el editor: dompdf **no lee las fuentes del
sistema**. Sólo tiene las 14 base del PDF y las tres DejaVu que trae empaquetadas, así que
`font-family: Calibri` cae a Times y el texto ocupa distinto. Hoy se avisa
(`TemplateDiagnostics`, `kind: unsupported_font`); resolverlo de verdad es o instalar fuentes en
`storage/fonts` con `load_font`, o cambiar de motor.

**Recomendación.** Sustituir dompdf por un renderizador basado en navegador — `spatie/browsershot`
(Puppeteer) o **Gotenberg** (servicio HTTP con Chrome dentro). Con eso la paridad es exacta por
construcción: el PDF lo produce el mismo motor que dibuja el editor. Además desaparecen de un golpe
las tres limitaciones ya registradas (celda de tabla que no se parte y trunca en silencio, alturas
fijas que generan páginas en blanco, `enable_remote = false`).

**Por qué no se hizo ahora.** No es un refactor, es infraestructura: hay que meter Chrome en el
droplet de DigitalOcean (o levantar Gotenberg como componente aparte), con lo que eso implica en
imagen, memoria y despliegue. `TemplateRenderer` ya concentra los 6 caminos de render, así que el
cambio del lado del código está acotado; la decisión es de plataforma y de costo.

### 📋 P-18 · Las plantillas guardadas antes del 2026-08-06 perdieron sus reglas `body`/`html`

Detectado y arreglado el 2026-08-06 (ver `BITACORA_TECNICA.md` § 21.2). Hasta esa fecha,
`AdvancedTemplateSanitizer` descartaba en silencio **toda** regla CSS cuyo selector fuera `body` o
`html`, que es donde una plantilla exportada de Word o de otro panel pone su tipografía base
(`font-family`, `font-size`, márgenes, ancho). El sanitizer ya no lo hace, pero **lo que se descartó
entonces no está en ninguna parte**: `document_templates.body_html` guarda el resultado ya saneado,
no el original.

**Alcance.** Sólo plantillas en modo avanzado guardadas antes del 2026-08-06 y que traían reglas
`body`/`html`. Síntoma: el PDF sale con la letra por defecto de dompdf (Times) aunque el editor
muestre otra cosa.

**Recomendación.** No hay migración posible; el arreglo es volver a pegar el HTML original y guardar.
Está anotado en el manual de usuario. Si algún día importa auditarlo, se puede listar por SQL las
filas con `is_advanced_mode = true` y `updated_at < '2026-08-06'` para avisar a esos tenants.

**Aprendizaje aplicable.** Guardar únicamente el HTML saneado hace que cualquier bug del sanitizer sea
**irreversible**. Conservar el original crudo junto al saneado (una columna más) permitiría re-sanear
tras un arreglo, en vez de pedirle al usuario que rehaga su trabajo.

### ✅ P-16 · Borrar un cliente deja los archivos en S3, la configuración en el router y filas huérfanas

> **Resuelto el 2026-08-06.** `App\Services\CustomerDeletionService` orquesta el borrado
> completo y `App\Services\MikroTik\CustomerDeprovisionManager` es la contrapartida de borrado
> en el router que no existía (todos los managers tenían sólo `ensure*`). Se borran los objetos
> de S3 —incluidas las fotos de instalación, que se recogen por `installation_id` porque su
> `customer_id` puede ser NULL—, se barre la configuración del equipo (secret y sesión PPPoE,
> queue, HotSpot, lease, address-lists, ARP y amarre) y se limpian las tres tablas sin clave
> foránea. El prospecto se desliga en vez de borrarse: es un registro comercial propio.
> Un fallo del router no aborta el borrado pero se reporta como aviso, no como éxito.
> Ver `BITACORA_TECNICA.md` § 19. **Queda abierta la decisión de producto** sobre archivar
> (`SoftDeletes`) en vez de borrar, para conservar la historia contable.
> El diagnóstico original se conserva abajo.

Detectado 2026-08-06 al verificar qué se borra realmente. `CustomerProfileController::destroy()`
hace exactamente dos cosas: `$customer->delete()` y `$user->delete()`. `User` **no** usa
`SoftDeletes`, así que es un borrado real y las claves foráneas en cascada se disparan de verdad.

**Lo que sí se borra en cascada** (verificado contra `information_schema` del esquema `public`, no
supuesto): `customer_profile`, `customer_documents`, `invoices`, `payments`, `invoice_carryovers`,
`user_services`, `customer_additional_services`, `billing_action_logs`, `suspension_action_logs`,
`support_ticket_message`, `support_ticket_attachment`.

**Lo que queda, y es el problema:**

1. **Los archivos en S3 no se borran nunca.** `Storage::disk('s3')->delete()` sólo se ejecuta en
   `CustomerDocumentController::destroy()`, o sea al borrar **un** documento a mano. La cascada
   ocurre dentro de PostgreSQL, que jamás pasa por PHP: al borrar el cliente desaparecen las filas
   de `customer_documents` y los objetos quedan en el bucket **para siempre**, sin nada que apunte a
   ellos. Son contratos firmados y fotos de instalación, o sea datos personales, pagando
   almacenamiento y sin forma de localizarlos.
2. **El cliente sigue configurado en el router.** `destroy()` no llama a ningún manager de MikroTik:
   no elimina el secret PPPoE, la simple queue, el usuario de HotSpot, el lease DHCP ni las reglas de
   bloqueo. **El cliente borrado sigue navegando**, y ahora ya no existe en ISPWatch ningún registro
   de quién era ni qué IP tenía. Fuga de ingreso silenciosa y configuración huérfana en el equipo.
3. **Tres tablas quedan con filas huérfanas** porque no tienen clave foránea, sólo un índice:
   `customer_installations.customer_id` (las actas de instalación), `bulk_provision_runs.customer_id`
   y `prospects.converted_user_id`. Apuntan a un `users.id` que ya no existe.
4. `support_ticket`, `inventory_device` y `audit_logs` quedan con la referencia en `NULL`. En
   `inventory_device` **es lo correcto** (el equipo vuelve a estar libre); en `support_ticket` deja
   tickets sin dueño; en `audit_logs` es deliberado y correcto (la traza no debe borrarse).

**Recomendación.** Un hook `deleting` en el modelo `User` (o mejor, un servicio
`DeleteCustomerService` dentro de la transacción que ya existe) que antes del borrado: (a) recoja
los `file_path` de `customer_documents` y los borre de S3; (b) desaprovisione al cliente en el
router reutilizando los managers que ya existen, registrando el resultado en
`suspension_action_logs` como cualquier otra acción de red; (c) borre o reasigne las instalaciones.
Añadir las claves foráneas faltantes cierra el punto 3 pero **no** el 1 ni el 2 — ésos exigen pasar
por PHP a propósito. Conviene decidir además si el borrado debe ser un archivado (`SoftDeletes`)
en vez de un borrado real: un cliente con facturas pagadas es historia contable, y hoy se va entero.

### 📋 P-17 · La hoja de instalación no captura el puerto NAP ni el modo fibra

Detectado 2026-08-06 al hacer que la conversión de prospecto arrastre los datos técnicos de la
orden (`BITACORA_TECNICA.md` § 20). La hoja (`customer_installations.sheet`) guarda
`sectorial_id`, `router_id`, `plan_id` y `client_ip`, pero **no** el puerto de la caja NAP ni una
marca explícita de fibra. Consecuencias:

1. **El puerto NAP hay que digitarlo a mano en el alta** aunque todo lo demás venga prellenado —
   justo el dato que el técnico tiene delante en la caja y el operador de oficina no.
2. **El modo fibra se deduce**, no se lee: el alta mira si el elemento de red es de tipo `nap` y,
   si lo es, sube por `parent_id` hasta encontrar la OLT. Funciona con el árbol bien armado; una
   caja colgada directamente de un splitter sin OLT arriba deja `olt_id` vacío sin avisar.

**Recomendación.** Agregar `nap_port` (y opcionalmente `olt_id`) a las reglas de
`CustomerInstallationController::sheetValidationRules()` y a la tarjeta *Conexión / Red*, visibles
sólo cuando el elemento elegido es una NAP — el mismo criterio que ya usa `CustomerAdd.vue`. Es
media hora de trabajo; no se hizo en el mismo cambio para no mezclar el arreglo del arrastre de
datos con una ampliación del contrato de la hoja.

### 📋 P-19 · El inventario con custodia deja tres cabos sueltos conocidos

Detectado 2026-08-06 al implementar custodia, consumibles y kardex (`BITACORA_TECNICA.md` § 23).
Nada de esto bloquea el uso, pero conviene tenerlo escrito antes de que aparezca como sorpresa:

1. **Cambiar `is_serialized` de un modelo con existencias no está bloqueado en el backend.** El
   formulario lo ofrece siempre. Si alguien pasa a "por cantidad" un modelo que ya tiene 40 filas
   en `inventory_device`, esas 40 filas quedan sin forma de contarse desde los saldos (y al revés,
   un saldo de un modelo que pasa a serializado queda huérfano). Falta una validación en
   `InventoryStockController::rules()` que rechace el cambio cuando existan `devices` o `balances`
   asociados. La UI ya lo advierte en el comentario, pero un comentario no es una restricción.
2. **Los saldos huérfanos no tienen pantalla.** Borrar una sucursal o un usuario deja su fila en
   `inventory_balances` sin custodio visible (decisión consciente: perder existencias en silencio
   sería peor). Hoy sólo se ven consultando la tabla; faltaría listarlos en Movimientos para
   poder traspasarlos.
3. **La importación masiva registra la entrada por rango de `id`.** `InventoryImport::recordEntries()`
   identifica las filas recién insertadas con `id > max(id) previo AND tenant_id = propio`. Es
   correcto frente a importaciones de otros tenants en paralelo, pero **dos importaciones
   simultáneas del mismo tenant** podrían atribuirse filas entre sí. Ese escenario ya estaba roto
   antes por otro motivo (la deduplicación de seriales se cachea en memoria por instancia), así
   que no se agrava nada; se documenta para que quien arregle lo uno arregle lo otro.

### 📋 P-20 · La allowlist de IPs de las llaves de API es falsificable por cabecera

**Detectado:** 2026-08-07, al implementar la API pública de solo lectura.

`bootstrap/app.php` declara `trustProxies(at: '*')`, que es **necesario** en DigitalOcean App
Platform: sin él Laravel vería siempre la IP del balanceador y no la del cliente. El efecto
secundario es que `$request->ip()` se resuelve a partir de `X-Forwarded-For`, una cabecera que
el emisor de la petición controla. Con todos los proxies confiados, Symfony toma la entrada
más a la izquierda de esa cabecera, que es precisamente la que puede escribir quien llama.

**Consecuencia concreta:** quien tenga una llave filtrada y sepa cuál es una de sus IPs
autorizadas puede saltarse la allowlist enviando `X-Forwarded-For: <ip autorizada>`.

**Lo que esto sí y no significa.** La allowlist sigue valiendo: eleva mucho el listón (hay que
conocer la IP autorizada, no sólo el token) y sirve contra el uso accidental desde el sitio
equivocado. Pero **no es una frontera criptográfica** y no debe presentarse como tal: el
secreto primario es el token. Los controles que no dependen de la IP —caducidad, revocación,
abilities acotadas, rate limit por token y la bitácora— son los que aguantan solos.

**Recomendación.** Sustituir `trustProxies(at: '*')` por la lista real de rangos del
balanceador de DigitalOcean, o por `TrustProxies::HEADER_X_FORWARDED_AWS_ELB` si aplica. No se
hizo aquí porque tocar la confianza de proxies afecta a **toda** la aplicación (sesiones,
`URL::forceScheme`, rate limiting por IP del login) y merece su propio cambio, verificado
contra producción. Mientras tanto está documentado en `ARQUITECTURA.md` § 14 y en el manual
no se promete más de lo que la allowlist da.

### 📋 P-21 · El resto de los managers siguen con la ventana corta para el `ssh-exec` anidado

**Detectado:** 2026-08-13, al arreglar la lectura de interfaces WAN (`BITACORA_TECNICA.md` § 27).

La causa raíz de aquel fallo —15 s de espera para un comando que obliga al CORE a abrir un SSH
**anidado** contra un RouterBOARD por el overlay— no es exclusiva de `InterfaceReader`. Es el
mismo `$this->timeout` por defecto que usan `SuspensionManager`, `QueueManager`,
`PppSecretManager`, `PppProfileManager`, `PcqManager`, `HotspotManager`, `DhcpLeaseManager`,
`IpMacBindingManager` y `CustomerDeprovisionManager` cuando llaman a `executeSsh()` sin segundo
argumento.

**Lo que ya está cubierto:** desde este cambio, una salida truncada por tiempo deja de pasar por
éxito — `executeSsh()` devuelve `success: false` con `timed_out: true`. Ninguno de esos managers
puede volver a dar por aplicado un cambio que nunca se escribió por esta vía.

**Lo que falta:** darles la ventana adecuada. Hoy, contra un router lento, esas operaciones
fallan **correctamente pero de más**: el corte o el alta se reporta fallido cuando habría
funcionado con unos segundos más. No se hizo aquí porque varias de ellas corren en lote
(aprovisionamiento masivo, `billing:auto-cut`) y subir la espera por comando multiplica el
tiempo total del lote — hay que decidir el presupuesto por lote, no solo por comando, y eso
merece su propio cambio medido contra producción.

**Mientras tanto:** `MIKROTIK_CORE_SSH_TIMEOUT` permite subir el valor por defecto de toda la
flota sin tocar código, a costa de alargar los lotes.

### P-13 · El enlace de firma no se envía solo por WhatsApp

**Detectado:** 2026-08-14, al implementar la firma remota (§ 31 de `BITACORA_TECNICA.md`).
**Prioridad:** media · **Estado:** deuda aceptada conscientemente.

Hoy el botón *Enviar por WhatsApp* abre `wa.me` con el mensaje ya escrito y **el operador lo
envía desde su teléfono**. Funciona siempre y no depende de nadie, pero no es automático: no se
puede disparar desde el alta de un cliente ni desde el recordatorio programado, que por eso hoy
sólo llega por correo.

**Por qué no se hizo:** `WhatsAppService` usa la API de Meta, que sólo permite **iniciar**
conversaciones con plantillas aprobadas una a una en Meta Business. Escribir el método era
trivial; lo que no se puede escribir desde el repositorio es la plantilla aprobada, así que el
envío habría fallado en silencio en producción — peor que no ofrecerlo.

**Camino natural:** hacerlo por Converza, que ya tiene la sesión de WhatsApp abierta y ya lee
eventos de ISPWatch por cursor de id para la falla masiva (§ *Falla masiva*). El mismo patrón
—ISPWatch registra el evento, Converza envía— evita depender de plantillas de Meta y cierra de
paso el recordatorio automático por WhatsApp.

### P-14 · Falta el QR del enlace de firma para el técnico en campo

**Detectado:** 2026-08-14 · **Prioridad:** baja · **Estado:** no implementado.

Un QR en pantalla dejaría al técnico pasar el enlace al celular del cliente sin depender de que
al cliente le llegue el WhatsApp ni de que tenga datos. Se dejó fuera porque exige una
dependencia npm nueva (`qrcode`) para un caso que hoy cubren el `wa.me`, el correo y el botón de
copiar — y porque el técnico que está delante del cliente tiene a mano el camino presencial, que
ya funcionaba.


### 📋 P-21 · Un tenant puede pisar un código de catálogo global

Los catálogos extensibles del ticket (`ticket_symptom`, `ticket_cause`, `ticket_solution`)
llevan dos índices parciales que garantizan unicidad **dentro** de cada ámbito: uno para
las filas de plataforma (`tenant_id IS NULL`) y otro por tenant. Ningún índice puede
cruzar los dos ámbitos, así que `(NULL, 'sin_senal')` y `(7, 'sin_senal')` conviven sin
error.

**Consecuencia concreta.** Un integrador que reciba el código `sin_senal` no puede saber
si es el síntoma de plataforma o el propio de ese ISP, y dos tenants podrían estar
reportando cosas distintas bajo el mismo código.

**Por qué no se resolvió ahora.** La R1 no expone administración de catálogos: las únicas
filas que existen las escribió una migración. El agujero sólo se puede explotar cuando
haya una pantalla o un endpoint que permita crear filas por tenant, que es la Fase 3.

**Recomendación.** Validar en aplicación, al dar de alta una fila con `tenant_id`, que el
código no exista ya como global. Y decidir de forma explícita en el contrato qué gana si
llegara a pasar — lo razonable es que el código global tenga prioridad y el propio se
rechace.

### 📋 P-22 · El vocabulario de diagnóstico del ticket está sin acordar

`ticket_symptom`, `ticket_cause`, `ticket_solution` y `ticket_result` existen como tablas
pero están **vacíos a propósito**, y las cinco columnas del ticket que apuntan a ellos
(`symptom_id`, `suspected_cause_id`, `confirmed_cause_id`, `solution_id`, `result_id`)
son nullable y no se capturan en ninguna pantalla.

**Por qué se dejó así.** Los códigos son inmutables por diseño: una vez sembrados, un
ticket puede apuntar a ellos para siempre. Inventar el vocabulario antes de acordarlo con
el ISP y con el integrador significaría o cargar con códigos equivocados de forma
permanente, o retirarlos a las dos semanas dejando basura en el histórico.

**Riesgo mientras tanto.** Son columnas muertas. Si el acuerdo del vocabulario se
demorase mucho, conviene revisar si vale la pena mantenerlas declaradas — se incluyeron
para poder publicar el contrato OpenAPI una sola vez con el juego completo de campos.

**Recomendación.** Cerrar el vocabulario con el ISP y el integrador, sembrarlo en su
propia migración (nunca en un seeder: `migrate:both` no siembra `public`), y sólo entonces
construir la captura en la interfaz.

### 📋 P-23 · La Fase 1 dejó `support_ticket` con los enums y las FK a la vez

Es el estado intencional de la R1 (expandir), no un descuido: mantener las dos
representaciones permite revertir sin pérdida de datos. Pero es un estado transitorio y no
debe quedarse ahí.

**Lo que falta.** R2 — la aplicación pasa a leer y escribir por catálogo, el frontend deja
de tener sus mapas de etiquetas en duro (`Support.vue`, `SupportDetail.vue`,
`SupportEdit.vue`, `CustomerTickets.vue`), `statistics()` toma la etiqueta de la columna
`label` en vez de generarla con `ucfirst()`, y la API pública sigue emitiendo el código
como cadena mediante join. R3 — se eliminan los enums y sus `CHECK`, que es el punto sin
retorno.

**Riesgo de quedarse a medias.** Mientras las dos representaciones coexistan, cualquier
escritura que no pase por el modelo (SQL crudo, una importación) puede desincronizarlas.
Hoy no hay ninguna, pero conviene no alargar la convivencia.
## 8. Tabla consolidada

| ID | Problema | Impacto | Prioridad | Estado |
|---|---|---|---|---|
| **C-1** | Credenciales de producción en texto plano en el repositorio | Compromiso total: BD, CORE MikroTik, SMTP, Supabase | 🔴 Crítica | ✅ Repo limpio · 🔧 **falta rotar** |
| **C-2** | Sin componente que ejecute `schedule:run` | No se factura, no se recuerda, no se corta | 🔴 Crítica | ✅ Definido · 🔧 **falta desplegar** |
| ~~C-3~~ | ~~`cut_type` vacía~~ | — | — | ❌ **Falso positivo** → M-10 |
| ~~C-4~~ | ~~Migración pendiente~~ | — | — | ❌ **Ya estaba aplicada** |
| **A-1** | `apiResource` sin permisos | Cualquier autenticado podía borrar clientes y routers | 🟠 Alta | ✅ Resuelto (42 tests) |
| **A-2** | Sin `throttle` en la API | Enumeración, DoS, agotamiento del pool SSH del CORE | 🟠 Alta | ✅ Resuelto |
| **A-3** | Credenciales de red en texto plano en BD | Un volcado entrega el control de la red | 🟠 Alta | ✅ Código listo · 🔧 **falta migrar** |
| **A-4** | El store no replicaba el bypass de admin | Administradores bloqueados en el frontend | 🟠 Alta | ✅ Resuelto |
| **A-5** | CSP con `unsafe-inline`, `unsafe-eval` y `unpkg` | Protección XSS mermada | 🟠 Alta | ✅ Resuelto y verificado |
| **A-6** | `users.permissions` columna muerta | Permisos individuales sin efecto, en silencio | 🟠 Alta | ✅ Resuelto |
| **A-7** | Catálogo y roles podían divergir | Cada permiso nuevo exigía backfill manual | 🟠 Alta | ✅ Resuelto (`permissions:sync`) |
| **M-1** | Cobertura desequilibrada | Regresiones no detectadas | 🟡 Media | ✅ +49 tests, +10 rescatados |
| **M-2** | Tests sólo en SQLite | Fallos que sólo aparecen en producción | 🟡 Media | ✅ CI con PostgreSQL |
| **M-3** | `LIKE` sensible a mayúsculas (y `ilike` a pelo) | Búsquedas que no encuentran o que revientan | 🟡 Media | ✅ Macros `whereLike` |
| **M-4** | `inventory_stock.desc` de tipo `date` | Campo inutilizable | 🟡 Media | ✅ Migración |
| **M-5** | FK duplicada en `service_plan.tenant_id` | Borrado ambiguo | 🟡 Media | ✅ Migración |
| **M-6** | `ON DELETE` inconsistente | Borrar un tenant fallaba | 🟡 Media | ✅ Migración |
| **M-7** | Faltaban índices en tablas recorridas cada hora | Degradación al crecer | 🟡 Media | ✅ Migración |
| **M-8** | Unicidad de IP sólo en el controlador | Importaciones podían duplicar IPs | 🟡 Media | ✅ Índice único parcial |
| **M-9** | Cuatro tablas muertas | Ruido en el esquema | 🟡 Media | ✅ Migración con salvaguarda |
| **M-10** | `cut_type` comparado por nombre literal | Una tilde deja de cortar, sin error | 🟡 Media | ✅ Constantes + `matches()` |
| **B-1** | `audit_logs` nunca invocado | Sin trazabilidad de acciones sensibles | 🟢 Baja | ✅ 4 acciones instrumentadas |
| **B-2** | `.env.testing` versionado | Higiene | 🟢 Baja | ✅ Destrackeado |
| **B-3** | Parámetro `tenant` residual en axios | Induce a error | 🟢 Baja | ✅ Eliminado |
| **B-4** | Nombres de tabla mezclados | Confusión | 🟢 Baja | ✅ Documentado |
| **B-5** | Documentación desincronizada | Decisiones sobre información falsa | 🟢 Baja | ✅ Resuelto |
| **B-6** | Restos de Livewire/Volt | Código y 19 tests muertos | 🟢 Baja | ✅ Eliminados + test real |
| **P-1** | Falta `delete_clients` | Borrado de cliente demasiado laxo | 🟡 Media | 📋 Pendiente |
| **P-2** | Contraseñas de router en la respuesta JSON | Exposición innecesaria | 🟡 Media | 📋 Pendiente (frontend) |
| **P-3** | Placeholder de otro tipo de documento se blanquea sin avisar | Tickets de soporte confusos ("no aparece mi tabla") | 🟢 Baja | ✅ Resuelto 2026-08-06 (`kind: wrong_type`) |
| **P-4** | Modo avanzado sin editor visual ni protección contra typos | Mismo síntoma que P-3, más fácil de gatillar | 🟢 Baja | ✅ Resuelto 2026-08-06 (`TemplateDiagnostics` + `HtmlDocumentEditor`) |
| **P-5** | Modo avanzado no permite `background-image` vía CSS | Limitación de diseño, no de seguridad | 🟢 Baja | 📋 Pendiente (por diseño, con alternativa propuesta) |
| **P-6** | `APP_KEY` local no desencripta campos `encrypted` sincronizados desde producción | Router passwords, WireGuard keys, PPPoE passwords y Maps key ilegibles en dev; tumbaba `GET /tenants/{id}` entero | 🟡 Media | ✅ Aislado en `TenantController` · 📋 Confirmar `APP_KEY` real de App Platform pendiente |
| **P-7** | Whitelist de contrato sin departamento/ciudad del cliente | Plantillas migradas de WispHub no pueden mostrar `{{cliente.localidad}}`/`{{cliente.ciudad}}` | 🟢 Baja | ✅ Resuelto 2026-08-05 (`cliente.ciudad` + `cliente.departamento`) |
| **P-8** | dompdf recorta el contenido de una celda de tabla más alta que una página | **Pérdida silenciosa de texto legal** en el PDF firmado (~1.800 caracteres medidos), además de páginas en blanco | 🟠 Alta | 📋 Documentado · aviso en vista previa pendiente |
| **P-9** | Documentos anteriores al paso a S3 con enlace roto e indistinguibles de los buenos | El usuario ve la tarjeta y el enlace falla; soporte no puede separar "se perdió en la migración" de "el almacenamiento está caído" | 🟡 Media | 📋 Pendiente |
| **P-10** | Eliminar un cliente no lo saca del router | Fuga de ingreso silenciosa: sigue navegando y ya no aparece en ninguna lista | 🟠 Alta | 📋 Pendiente |
| **P-11** | `$monthlyRevenue` calculado y nunca usado en el Dashboard | Consulta agregada inútil por petición; ambigüedad sobre qué mide la tarjeta | 🟢 Baja | 📋 Pendiente (decisión de producto) |
| **P-12** | El Centro de Ayuda no tiene forma sancionada de publicarse, y el seeder borra todo antes de sembrar | El manual en la app se queda viejo; y en cuanto alguien edite un artículo desde la UI, el próximo seed lo destruye | 🟡 Media | 📋 Pendiente |
| **P-13** | Migrar una plantilla de otro sistema no tiene ayuda en la app | Los marcadores de WispHub se blanquean en silencio; el usuario ve HTML correcto con datos vacíos y no sabe por qué | 🟡 Media | ✅ Resuelto 2026-08-06 (`TemplateDiagnostics`) |
| **P-14** | Los mocks de dompdf se rompen con cada método nuevo del wrapper | Un cambio de una línea en `TemplateRenderer` tumba 14 pruebas con un error que señala el archivo equivocado | 🟢 Baja | 📋 Arreglado en sitio · helper `fakePdf()` pendiente |
| **P-15** | La vista previa nunca será idéntica al PDF mientras el motor sea dompdf | `float`/`position`/flexbox divergen y dompdf no lee las fuentes del sistema; la paridad exacta exige un navegador headless | 🟡 Media | 📋 Mitigado 2026-08-06 (panel con el PDF real + avisos); el motor sigue pendiente |
| **P-16** | Borrar un cliente deja archivos en S3, config en el router y filas huérfanas | El cliente borrado **sigue navegando**; contratos y fotos quedan en el bucket para siempre | 🔴 Alta | ✅ Resuelto 2026-08-06 (`CustomerDeletionService`) |
| **P-17** | La hoja de instalación no captura el puerto NAP ni el modo fibra | En fibra, el puerto de la caja se digita a mano en el alta y la OLT se deduce subiendo por `parent_id` | 🟢 Baja | 📋 Pendiente |
| **P-18** | Las plantillas guardadas antes del 2026-08-06 perdieron sus reglas `body`/`html` | El sanitizer las descartaba en silencio y sólo se guarda el HTML ya saneado: el original no existe | 🟡 Media | 📋 Pendiente (hay que repegar el HTML; sin migración posible) |
| **P-19** | Inventario con custodia: tres cabos sueltos (cambio de `is_serialized` sin validar, saldos huérfanos sin pantalla, importación por rango de `id`) | Existencias que dejan de poder contarse; saldos invisibles | 🟡 Media | 📋 Pendiente |
| **P-20** | La allowlist de IPs de las llaves de API es falsificable por cabecera | Con una llave filtrada, `X-Forwarded-For` salta la restricción por IP | 🟡 Media | 📋 Documentado · el token sigue siendo el secreto primario |
| **P-21** | El resto de los managers MikroTik siguen con 15 s para el `ssh-exec` anidado | Contra routers lentos, cortes y altas se reportan fallidos aunque habrían funcionado con más espera | 🟡 Media | 📋 Pendiente · el falso éxito por truncamiento **sí** quedó cerrado |

---

## 9. Qué hay que ejecutar para cerrar el ciclo

El código está aplicado y verificado. Estos cuatro pasos **no se pueden hacer desde el
repositorio** y quedan en manos del equipo, en este orden:

### 1. Rotar las credenciales expuestas 🔴

Seguir [`RUNBOOK_ROTACION_SECRETOS.md`](RUNBOOK_ROTACION_SECRETOS.md). Mientras no se haga,
las credenciales del CORE y de la base de datos siguen comprometidas en el historial de Git.

### 2. Desplegar el componente `scheduler` 🔴

```bash
doctl apps update <APP_ID> --spec .do/deploy.yaml
# Verificar después:
php artisan billing:verify-monthly   # debe reportar 'ok', nunca 'no_show'
php artisan billing:verify-cuts
```

### 3. Aplicar las migraciones nuevas

```bash
php artisan migrate:both
```

Son cinco, en este orden:

| Migración | Qué hace | Nota |
|---|---|---|
| `..._000001_ensure_cut_type_catalog_rows` | Garantiza el catálogo de tipos de corte | Idempotente |
| `..._000002_encrypt_network_credentials_in_place` | Cifra credenciales y elimina las columnas duplicadas | **Ejecutar DESPUÉS de rotar `APP_KEY`** |
| `..._000003_clean_up_schema_debt` | Tipo de `desc`, FK duplicada, `ON DELETE` | Sólo PostgreSQL |
| `..._000004_add_performance_and_integrity_indexes` | Índices + unicidad de IP por router | 0 duplicados verificados |
| `..._000005_drop_unused_ip_management_tables` | Elimina 4 tablas vacías | Con salvaguarda |

> ⚠️ **Orden crítico entre C-1 y A-3.** Si se rota `APP_KEY` **después** de cifrar, habrá que
> descifrar y re-cifrar también estos valores. Rotar primero, migrar después.

Y las cinco del inventario con custodia (2026-08-06), que van juntas y en este orden:

| Migración | Qué hace | Nota |
|---|---|---|
| `..._130000_add_serialization_to_inventory_stock_table` | `is_serialized` + `unit` | Default `true` = comportamiento actual |
| `..._130100_add_custody_to_inventory_device_table` | `status` + `customer_id` + índices | Hace backfill: lo asignado pasa a `assigned` |
| `..._130200_create_inventory_balances_table` | Saldos de consumibles | — |
| `..._130300_create_inventory_movements_table` | Kardex append-only | — |
| `..._130400_create_installation_equipment_table` | Equipos por instalación | `device_id` **único** |

### 4. Sincronizar permisos tras cada despliegue

```bash
php artisan permissions:sync --dry-run   # revisar
php artisan permissions:sync
```

Conviene añadirlo al comando de arranque del componente web, junto a `migrate --force`.

---

## 10. Lo que ya estaba bien resuelto

Registro de las decisiones acertadas que se encontraron, para que una refactorización futura
no las deshaga sin conocer su motivo.

| Acierto | Por qué importa |
|---|---|
| **`tenant_id` derivado sólo del usuario autenticado** | Cierra la fuga entre tenants por query param (OWASP A01/A04) |
| **Cliente de otro tenant = «no encontrado»** | Evita enumeración entre tenants en el aprovisionamiento |
| **`created_by` sellado desde la sesión** | El cliente no puede falsear quién registró un pago o un gasto |
| **Idempotencia de la facturación** | Las ejecuciones horarias adicionales son no-ops seguras |
| **Recuperación ante caídas** (`today->day >= create_day`) | Si el sistema estuvo caído el día de facturación, recupera al arrancar |
| **Lápida `suppressed`** | Una factura borrada a conciencia no resucita, y sólo afecta a ese mes |
| **Failover con backoff diferenciado** | Cortes cada 30 min (fuga de ingreso), facturas cada 2 h |
| **Auditoría de no-show** (`verify-monthly` / `verify-cuts`) | Cubre el punto ciego que el failover no puede ver por definición |
| **`FirstInvoicePolicy` como fuente única** | Generación, auditoría y vista previa usan la misma fórmula: no puede divergir |
| **`RouterEndpointResolver`** | Resuelve la deriva de IP del overlay leyendo la verdad del CORE |
| **Índice único parcial de `pppoe_username` por router** | Impide que RouterOS sobrescriba en silencio el secret de otro cliente |
| **`config/database.php` sin `url` en `sqlite`** | Hace estructuralmente imposible que los tests escriban en la base real |
| **Aprovisionamiento masivo asíncrono** | Reconoce el coste real (17-34 s/cliente) en vez de pelear con el timeout |
| **`manage_document_templates` separado de `manage_tenant`** | Acota el radio de acción de un rol personalizado sobre texto legal |
| **Comentarios que explican el *porqué*, no el *qué*** | Buena parte de esta auditoría fue posible gracias a ellos |
