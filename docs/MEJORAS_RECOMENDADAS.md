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
| ✅ **Resuelto en código** | 20 | Aplicado y verificado con tests |
| 🔧 **Requiere ejecución** | 4 | El código está listo; falta correr migraciones o rotar credenciales |
| ❌ **Falso positivo** | 2 | Corregidos en §2 |
| 📋 **Pendiente** | 8 | Decisión de producto, trabajo de frontend, o hallazgos posteriores (P-6, P-7 y P-8, del repaso del manual del 2026-08-03) |

### Resultado medible

| Métrica | Antes | Después |
|---|---:|---:|
| Tests pasando | 180 | **245** |
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

### 📋 P-3 · Un placeholder de otro tipo de documento se blanquea sin avisar

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

### 📋 P-4 · Modo avanzado: editor de texto plano, sin editor visual ni protección contra typos en el token

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

### 📋 P-6 · Eliminar un cliente no lo desaprovisiona del router

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

### 📋 P-7 · `$monthlyRevenue` se calcula en el Dashboard y nunca se usa

En `DashboardController::stats()` se consulta la suma de facturas `paid` emitidas en el mes
(`$monthlyRevenue`) y luego la respuesta devuelve `revenue.monthly => $monthlyPayments` — los
**pagos** recibidos en el mes. La variable calculada queda muerta: es una consulta agregada por
petición al Dashboard que no alimenta nada.

Las dos métricas son legítimas pero distintas (facturado-y-cobrado del mes vs. caja del mes), y
hoy no está claro cuál se quiso mostrar. El manual documenta **el comportamiento real** (pagos).

**Recomendación.** Decidir producto: si la tarjeta debe seguir siendo caja, borrar
`$monthlyRevenue`; si debía ser lo facturado, cambiar la clave de la respuesta y avisar del
cambio de significado. No tocarlo a ciegas — el número que hoy ve el operador cambiaría.

### 📋 P-8 · El Centro de Ayuda no tiene forma sancionada de actualizarse en producción

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
| **P-3** | Placeholder de otro tipo de documento se blanquea sin avisar | Tickets de soporte confusos ("no aparece mi tabla") | 🟢 Baja | 📋 Pendiente |
| **P-4** | Modo avanzado sin editor visual ni protección contra typos | Mismo síntoma que P-3, más fácil de gatillar | 🟢 Baja | 📋 Pendiente (deliberado, evaluar según demanda) |
| **P-5** | Modo avanzado no permite `background-image` vía CSS | Limitación de diseño, no de seguridad | 🟢 Baja | 📋 Pendiente (por diseño, con alternativa propuesta) |
| **P-6** | Eliminar un cliente no lo saca del router | Fuga de ingreso silenciosa: sigue navegando y ya no aparece en ninguna lista | 🟠 Alta | 📋 Pendiente |
| **P-7** | `$monthlyRevenue` calculado y nunca usado en el Dashboard | Consulta agregada inútil por petición; ambigüedad sobre qué mide la tarjeta | 🟢 Baja | 📋 Pendiente (decisión de producto) |
| **P-8** | El Centro de Ayuda no tiene forma sancionada de publicarse, y el seeder borra todo antes de sembrar | El manual en la app se queda viejo; y en cuanto alguien edite un artículo desde la UI, el próximo seed lo destruye | 🟡 Media | 📋 Pendiente |

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
