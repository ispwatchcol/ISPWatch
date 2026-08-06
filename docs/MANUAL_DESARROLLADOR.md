# MANUAL DE DESARROLLADOR — ISPWatch

> Todo lo necesario para instalar, ejecutar, probar, desplegar y extender el sistema.
> Complementa a [`ARQUITECTURA.md`](ARQUITECTURA.md) (diseño) y
> [`BITACORA_TECNICA.md`](BITACORA_TECNICA.md) (inventario de código).

**Última actualización:** 2026-07-30 (post-remediación)

---

## Índice

1. [Requisitos](#1-requisitos)
2. [Instalación](#2-instalación)
3. [Variables de entorno](#3-variables-de-entorno)
4. [Ejecución local](#4-ejecución-local)
5. [Base de datos y migraciones](#5-base-de-datos-y-migraciones)
6. [Pruebas](#6-pruebas)
7. [Compilación y despliegue](#7-compilación-y-despliegue)
8. [Comandos Artisan](#8-comandos-artisan)
9. [Convenciones de desarrollo](#9-convenciones-de-desarrollo)
10. [Cómo añadir funcionalidad](#10-cómo-añadir-funcionalidad)
11. [Trampas conocidas](#11-trampas-conocidas)
12. [Solución de problemas](#12-solución-de-problemas)

---

## 1. Requisitos

| Herramienta | Versión mínima | Notas |
|---|---|---|
| PHP | 8.2 | Extensiones: `pdo_pgsql`, `mbstring`, `openssl`, `zip`, `gd`, `bcmath` |
| Composer | 2.0 | |
| Node.js | 18 | |
| NPM | 9 | |
| PostgreSQL | 14 | O una cuenta Supabase. PostGIS necesario para `sectorial.coordinates` |
| Git | — | |

**Acceso adicional para trabajar con la red:** credenciales SSH del router CORE MikroTik.
Sin ellas todo el módulo de aprovisionamiento y corte queda inoperativo en local
(el resto del sistema funciona con normalidad).

---

## 2. Instalación

```bash
git clone https://github.com/ispwatchcol/ISPWatch.git
cd ISPWatch

composer install
npm install

cp .env.example .env      # si no existe, parte de las claves de la sección 3
php artisan key:generate
```

### Configurar la base de datos

**Opción A — Base local (recomendada para desarrollo aislado)**

```bash
createdb ispwatch
psql -d ispwatch -c "CREATE EXTENSION IF NOT EXISTS postgis;"
```

Ajusta `DB_HOST=127.0.0.1`, `DB_DATABASE=ispwatch`, `DB_SCHEMA=public` y ejecuta:

```bash
php artisan migrate
php artisan db:seed
```

**Opción B — Supabase compartida, esquema de desarrollo**

Apunta las credenciales a Supabase y usa **`DB_SCHEMA=ispwatch_dev`**.

> ⚠️ **`DB_SCHEMA=public` es PRODUCCIÓN.** Con ese valor, cualquier `migrate`, `db:seed`
> o escritura desde tinker impacta datos reales. Si tu `.env` apunta a `public`, limítate a
> comandos de solo lectura (`migrate:status`).

### Orden del seeder

`DatabaseSeeder` ejecuta: roles → tenant demo → tipos de plan → tipos de corte →
planes de servicio → routers → usuarios base → clientes de ejemplo.

---

## 3. Variables de entorno

### Aplicación

| Clave | Ejemplo | Descripción |
|---|---|---|
| `APP_NAME` | `ISPWatch` | |
| `APP_ENV` | `local` \| `production` | Controla CSP y HSTS en `SecurityHeaders` |
| `APP_KEY` | `base64:…` | **Obligatoria.** Cifra la clave de Google Maps del tenant |
| `APP_DEBUG` | `true` en local, **`false`** en producción | |
| `APP_URL` | `http://localhost:8000` | |
| `FRONTEND_URL` | `http://localhost:8000` | Respaldo de CORS |

### Base de datos

| Clave | Ejemplo | Descripción |
|---|---|---|
| `DB_CONNECTION` | `pgsql` | |
| `DB_HOST` | `aws-0-us-east-1.pooler.supabase.com` | |
| `DB_PORT` | `5432` | |
| `DB_DATABASE` | `postgres` | |
| `DB_USERNAME` / `DB_PASSWORD` | — | |
| `DB_SCHEMA` | `ispwatch_dev` \| `public` | **`public` = producción** |
| `DB_SSLMODE` | `require` (por defecto) | Supabase exige TLS. Sólo se baja a `disable` en CI, cuyo contenedor de PostgreSQL no tiene SSL |

> `DB_URL` **no debe definirse.** `ConfigurationUrlParser` la mezcla en *cualquier* conexión
> que se resuelva, incluida `sqlite`, y podría redirigir los tests a la base real.
> `config/database.php` omite la clave `url` en `sqlite` justamente por eso.

### Sesión, cache y cola

| Clave | Local | Producción |
|---|---|---|
| `SESSION_DRIVER` | `file` | `database` |
| `CACHE_STORE` | `database` | `database` |
| `QUEUE_CONNECTION` | `database` | `database` |
| `SESSION_LIFETIME` | `120` | `120` |
| `SESSION_ENCRYPT` | — | `true` |
| `SESSION_SECURE_COOKIE` | — | `true` |

### Autenticación SPA

| Clave | Descripción |
|---|---|
| `SANCTUM_STATEFUL_DOMAINS` | Dominios que reciben cookie de sesión. **Añade el host de Vite** en local |
| `CORS_ALLOWED_ORIGINS` | Lista separada por comas. Local: `http://localhost:8000,http://localhost:5173,http://127.0.0.1:8000,http://127.0.0.1:5173` |

### MikroTik

| Clave | Descripción |
|---|---|
| `MIKROTIK_CORE_SSH_HOST` / `_PORT` / `_USER` / `_PASS` | Acceso SSH al CORE |
| `MIKROTIK_CORE_SSH_KEY_PASSPHRASE` | Frase de la clave privada |
| `MIKROTIK_CORE_API_HOST` / `_PORT` / `_USER` / `_PASS` | Acceso API (8728) |
| `MIKROTIK_CORE_VPN_IP` | IP del CORE en el overlay |
| `MIKROTIK_USE_CORE_TUNNEL` | `true` en producción |
| `MIKROTIK_VPN_PASSWORD`, `MIKROTIK_IPSEC_SECRET` | Secretos de los scripts VPN generados |
| `PORTAL_IP` | IP del portal de pago. **La regla de firewall la deja accesible al cliente cortado** |

En producción la clave privada llega como `MIKROTIK_CORE_SSH_KEY_B64` (base64) y el comando
de arranque la materializa en `storage/keys/mikrotik_core_id_ed25519` con permisos `600`.

### Correo, S3 y WhatsApp

| Clave | Descripción |
|---|---|
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | SMTP (Brevo en producción) |
| `FILESYSTEM_DISK` | `local` en desarrollo, `s3` para documentos |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_USE_PATH_STYLE_ENDPOINT` | S3 |
| `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_BUSINESS_ACCOUNT_ID` | WhatsApp Cloud API. **Sin ellas, el servicio registra un aviso y devuelve error controlado** |

### Frontend (Vite)

| Clave | Descripción |
|---|---|
| `VITE_APP_NAME` | Nombre visible |
| `VITE_API_URL` | Base de la API. Por defecto `/api` |
| `VITE_SUPABASE_URL`, `VITE_SUPABASE_ANON_KEY` | **Residuales.** El frontend ya no accede a Supabase directamente |

---

## 4. Ejecución local

```bash
# Backend + Vite en un comando
npm run dev

# Full-stack con cola y logs (recomendado para depurar)
composer run dev        # serve + queue:listen + pail + vite

# Terminales separadas
php artisan serve
npm run vite
```

Aplicación en **http://localhost:8000**.

### Probar las tareas programadas

```bash
php artisan schedule:work                       # ejecuta el planificador en primer plano
php artisan billing:generate-monthly 2026-07    # una tarea concreta
php artisan billing:simulate                    # simulador del ciclo completo
```

---

## 5. Base de datos y migraciones

### Regla número uno

> **Nunca uses `php artisan migrate` a secas contra Supabase.**
> El comando correcto es **`php artisan migrate:both`**, que aplica el cambio en
> `ispwatch_dev` **y** en `public`.

```bash
php artisan migrate:both                  # ambos esquemas
php artisan migrate:both --path=database/migrations/2026_07_30_000000_....php
php artisan migrate:both --seed           # el seed sólo corre en ispwatch_dev
php artisan migrate:both --fresh          # pide confirmación por esquema
php artisan db:sync-dev                   # copia public → ispwatch_dev
```

El comando cambia `database.connections.pgsql.schema`, purga y reconecta antes de cada
esquema. **Los seeders nunca corren sobre `public`**: crean filas de demostración que
contaminarían producción.

### Escribir migraciones

1. **Portables a SQLite.** Los tests corren sobre SQLite en memoria. SQL exclusivo de
   PostgreSQL (`information_schema`, `REGEXP_REPLACE`, índices parciales) rompe **toda** la
   suite Feature. Protégelo por driver:

   ```php
   if (DB::getDriverName() === 'pgsql') {
       DB::statement('CREATE UNIQUE INDEX ... WHERE ...');
   }
   ```

2. **Idempotentes.** Usa `Schema::hasColumn()` / `hasTable()` antes de alterar: las
   migraciones se aplican sobre esquemas que pueden diferir.

3. **Backfill explícito para permisos.** Un permiso nuevo en `Permissions.php` **no llega
   solo** a los roles ya existentes: el frontend lee `role.permissions` de la base. Añade
   una migración de backfill (ver `2026_07_27_120000_backfill_admin_roles_manage_document_templates`).

### Secuencias desincronizadas

PostgreSQL puede quedar con secuencias por detrás del `MAX(id)` tras importaciones
manuales. El trait `FixesSequences` lo corrige al crear, y hay comando manual:

```bash
php artisan db:fix-sequences --all
php artisan db:fix-sequences --table=customer_profile
```

---

## 6. Pruebas

```bash
php artisan test                          # toda la suite
composer run test                         # limpia config y ejecuta
php artisan test tests/Feature/Billing    # sólo facturación
php artisan test --filter=FirstInvoice    # por nombre
```

**Entorno de pruebas:** SQLite `:memory:`, `BCRYPT_ROUNDS=4`, cache/sesión en array,
cola `sync`, correo `array`. Definido en `phpunit.xml` y reforzado en `.env.testing`
(que no se versiona: parte de `.env.testing.example`).

**Estado:** 358 tests, **0 fallos** (2026-08-03). Hasta 2026-07-30 había 34 fallos permanentes —
19 de andamiaje de Breeze que probaba rutas y componentes inexistentes, 10 de documentos que
falseaban el disco `public` mientras el código escribe en `s3`, y el resto residuos del
esqueleto de Laravel. Se eliminaron los muertos y se arreglaron los reales: una suite con
fallos crónicos no es una red de seguridad, porque nadie la mira.

**CI:** `.github/workflows/tests.yml` ejecuta la suite dos veces, en SQLite y en
**PostgreSQL 16 + PostGIS**. Sólo el segundo puede detectar el booleano comparado con
cadena, el `LIKE` sensible a mayúsculas y los índices parciales.

**La salvaguarda de `tests/TestCase.php`.** La suite usa `RefreshDatabase`, que ejecuta
`migrate:fresh`: apuntarla por error a la base real la deja vacía, y el `.env` local
apunta a Supabase. Por eso cada `setUp` comprueba la conexión **ya resuelta** (no la
configuración escrita, que un `DB_URL` perdido puede reescribir sin cambiar el nombre de
la conexión) y aborta salvo en dos casos:

| Motor | Se admite si |
|---|---|
| `sqlite` | la base es `:memory:` — nunca un archivo del proyecto |
| `pgsql` | es **desechable**: host local (`127.0.0.1`, `localhost`, `::1`, `postgres`) y base terminada en `_test` — o sea, el contenedor del CI |

> Hasta el 2026-07-31 la salvaguarda exigía driver `sqlite` **y nada más**, así que el job
> de PostgreSQL abortaba en todos los tests con base de datos y nunca llegó a ejecutar una
> sola aserción. Si necesitas correrlo en local, levanta un Postgres desechable
> (`ispwatch_test` en `127.0.0.1`); apuntar la suite a Supabase seguirá abortando, y debe
> seguir haciéndolo.

### Cobertura actual (46 archivos, 358 tests)

| Suite | Archivos |
|---|---|
| `Feature/Billing` | `AutoCutoffTest`, `AutoReconnectOnPaymentTest`, `BillingEventTimeTest`, `BillingModuleTest`, `DeleteInvoiceTest`, `FirstInvoiceFreeMonthsTest`, `FirstInvoiceProrationTest`, `MarkInvoiceUnpaidTest`, `PaymentReminderTest`, `PaymentsListFilterTest`, `ReconcileSuspensionsTest`, `RouterMonthlyBillingTest`, `VerifyAutomaticCutsTest`, `VerifyMonthlyBillingTest` |
| `Feature/Documents` | `BillingPdfDownloadTest`, `CustomerContractSignTest`, `DocumentTemplateControllerTest`, `InstallationSheetSignTest` |
| `Feature/Auth` | `ApiAuthorizationTest` (42: permiso por endpoint, OR, bypass admin, unión de permisos), `ApiLoginTest` (7: login real, verificación, rate limit), `RolePermissionsSyncTest` (7) |
| `Feature/Router` | `RouterOutageTest` |
| `Feature/Inventory` | `InventoryImportTest` |
| `Feature` (raíz) | `BillingTest`, `StaffDeletionTest`, `TemplateRendererFallbackTest`, `TemplateRendererBlockPlaceholdersTest`, `TemplateRendererAdvancedModeTest`, `TenantBrandingConfigTest`, `TenantLogoUploadTest` |
| `Unit` | `CoreSshExecTest`, `FirewallRulesManagerTest`, `InterfaceReaderTest`, `NormalizesRouterCommentTest`, `PppProfileManagerTest`, `WireguardTransportTest` |
| `Unit/Services` | `PlaceholderResolverTest`, `BlockPlaceholderResolverTest`, `BlockMarkerInjectorTest`, `TemplateSanitizerTest`, `AdvancedTemplateSanitizerTest` |
| `Unit/Spikes` | `CssTidyExtractStyleBlocksSpikeTest` (prueba aislada de `Filter.ExtractStyleBlocks`, no forma parte del sanitizer de producción) |

**Zona sin cobertura relevante:** la lógica de negocio de clientes, prospectos, sectoriales,
soporte y gastos. La **autorización** de todos ellos sí está cubierta.

### Cuidado con SQLite

Diferencias que los tests **no** detectan y sí rompen en producción:

| Aspecto | PostgreSQL | SQLite |
|---|---|---|
| Comparar `boolean` con `'active'` | `SQLSTATE 22P02` | Coincide con cero filas, silenciosamente |
| `LIKE` | **Sensible** a mayúsculas | Insensible |
| Índices parciales | Soportados | No |
| Ids tras el rollback de `RefreshDatabase` | La secuencia **no** se revierte: el siguiente test empieza donde quedó el anterior | El `AUTOINCREMENT` se recicla: cada test vuelve a empezar en 1 |

Cuando el filtrado sea insensible a mayúsculas, usa `ilike` seleccionado por driver.

**Nunca des por hecho un id concreto.** Un test daba por sentado que la primera fila de
`role` recibía el id 1 —el que `CheckPermission` trata como superadministrador—, y en
PostgreSQL el rol nacía con id 77 a partir del segundo test del proceso, así que el
bypass "fallaba" con el código intacto. Si un test necesita un id fijo, fíjalo a mano
(`$role->id = 1; $role->save();`, ver `ApiAuthorizationTest::crearRol()`).

---

## 7. Compilación y despliegue

### Build

```bash
npm run build            # assets optimizados en public/build/
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### DigitalOcean App Platform

La app se define en `.do/deploy.template.yaml`: buildpack `php`, Ubuntu 22, región `atl`,
**egress con IP dedicada** (necesario para la lista blanca del CORE), despliegue automático
al hacer push a `main`.

| Componente | Comando de arranque |
|---|---|
| `ispwatch` (web) | Materializa la clave SSH → `php artisan migrate --force` → `heroku-php-apache2 public/` |
| `worker` | Materializa la clave SSH → `php artisan queue:work --tries=1 --timeout=120 --sleep=3 --max-time=3600` |

### El planificador

Desde 2026-07-30 la especificación incluye un tercer componente, `scheduler`, que ejecuta
`php artisan schedule:work`. **Antes no existía**, y sin él nada del ciclo automático
ocurre: no se factura, no se recuerda, no se corta, no se recolecta tráfico. Es un fallo que
ya se materializó en producción.

> 🔧 Falta **aplicar** la especificación en DigitalOcean para que el componente exista de
> verdad. Alternativa si se prefiere un cron externo:
> `* * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1`

Verificación de que está funcionando:

```bash
php artisan billing:verify-monthly        # debe reportar 'ok', no 'no_show'
php artisan billing:verify-cuts
```

### Checklist previo a producción

- [ ] `APP_DEBUG=false`
- [ ] `APP_KEY` definida y **la misma** que cifró los datos existentes
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`
- [ ] `SANCTUM_STATEFUL_DOMAINS` y `CORS_ALLOWED_ORIGINS` con el dominio real
- [ ] Migraciones aplicadas en **ambos** esquemas
- [ ] Worker de cola activo
- [ ] **Planificador activo**
- [ ] Clave SSH del CORE materializada y con permisos `600`
- [ ] IP de egreso en la lista blanca del CORE

---

## 8. Comandos Artisan

### Facturación

| Comando | Descripción |
|---|---|
| `billing:generate-monthly {period?}` | Genera facturas. Sin argumento deriva el periodo por router; `YYYY-MM` fuerza uno y **salta el gate de hora** |
| `billing:retry-failed` | Reintenta filas `failed` con `next_retry_at` vencido |
| `billing:verify-monthly` | Auditoría de no-show. **No escribe nada** |
| `billing:send-reminders` | Recordatorios de pago |
| `billing:void-courtesy {period?}` | Anula facturas de planes de cortesía |
| `billing:generate-tenant {tenant} {period} {--dry-run}` | Facturación puntual por tenant |
| `billing:simulate` | Simulador del ciclo |

### Cortes

| Comando | Descripción |
|---|---|
| `billing:auto-cut` | Corte automático por router |
| `billing:process-overdue` | Procesamiento manual de morosos |
| `billing:reconcile-suspensions` | Re-corta a los suspendidos en BD no confirmados en el router |
| `billing:verify-cuts` | Auditoría de no-show de cortes |

### Red

| Comando | Descripción |
|---|---|
| `traffic:collect` | Muestrea contadores WAN (routers con `historial_trafico`) |
| `traffic:prune {--days=30}` | Poda muestras finas |
| `router:diagnose-wan` | Diagnóstico de interfaz WAN |

### Base de datos y mantenimiento

| Comando | Descripción |
|---|---|
| `migrate:both [--fresh] [--seed] [--path=] [--force]` | Migraciones en ambos esquemas |
| `db:sync-dev` | Copia `public` → `ispwatch_dev` |
| `db:fix-sequences [--table=] [--all]` | Repara secuencias |
| `documents:migrate-to-s3 [--dry-run]` | Migra documentos locales a S3 |

### Permisos

| Comando | Descripción |
|---|---|
| `permissions:sync [--dry-run] [--tenant=]` | Añade a los roles canónicos los permisos que les falten según `App\Constants\Permissions`. **Aditivo**: nunca quita. No toca roles personalizados |

---

## 9. Convenciones de desarrollo

### Dónde va cada cosa

| Tipo de cambio | Dónde |
|---|---|
| Nuevo endpoint | `routes/api.php` + controlador + `FormRequest` |
| Regla de negocio | `app/Services` |
| Regla de facturación **pura** | `app/Billing` |
| Comando RouterOS | `app/Services/MikroTik/<Recurso>Manager` |
| Permiso nuevo | `app/Constants/Permissions.php` **+ migración de backfill** |
| Cambio de esquema | `database/migrations` + `migrate:both` |
| Página nueva | `resources/js/pages` + `router/index.js` con `meta.permission` |
| Llamada HTTP nueva | `resources/js/services/api/<dominio>.js` |

### Reglas obligatorias

1. **Nunca derives el tenant del request.** Usa siempre `auth()->user()->tenant_id`
   o el trait `BelongsToTenant`.
2. **Nunca confíes en un `user_id` del cuerpo.** Séllalo desde `$request->user()->id`
   (patrón ya aplicado en `payments.created_by` y `expenses.created_by`).
3. **Un cliente de otro tenant se reporta como inexistente**, no como prohibido: evita
   enumeración entre tenants.
4. **Los importadores no consultan por fila.** Precarga los modelos en bloque; con 200 filas
   se alcanza el `504` del gateway.
5. **Cada permiso nuevo necesita backfill** de los roles ya sembrados.
6. **Los comandos RouterOS usan comillas planas** `"` en los compuestos; el escapado lo
   añade `coreSshExecCommand()`. Envuelve todo statement en `:do {} on-error={}`.
7. **Verifica el `exit-code`** de `ssh-exec`: un código distinto de cero se reportaba como
   éxito y ocultaba fallos reales.
8. **Toda ruta nueva lleva su permiso.** La guarda de `vue-router` es cosmética: quien llame
   la API directamente la evita. Añade además un caso a `ApiAuthorizationTest`.
9. **Los permisos de LECTURA pueden llevar varios valores** (`permission:a,b`, semántica OR)
   cuando son datos de referencia que otra pantalla necesita. Los de ESCRITURA, nunca.
10. **Las búsquedas de texto usan `whereLike`/`orWhereLike`**, jamás `like` ni `ilike` a pelo.

### Git

- **Nunca hagas push directo a `main`**: `main` es producción y despliega automáticamente.
- Trabaja en rama de feature y abre PR.
- Antes de hacer push, comprueba el upstream:

  ```bash
  git branch -vv        # NO debe apuntar a origin/main
  ```

---

## 10. Cómo añadir funcionalidad

### Ejemplo: nuevo endpoint protegido

**1. Permiso** (`app/Constants/Permissions.php`)

```php
const VIEW_REPORTS = 'view_reports';
// …y añádelo al grupo correspondiente en getAllPermissions()
```

**2. Backfill** (`database/migrations/AAAA_MM_DD_..._backfill_view_reports.php`)

```php
DB::table('role')->where('code', 'admin')->get()->each(function ($role) {
    $perms = json_decode($role->permissions ?? '[]', true);
    if (!in_array('view_reports', $perms, true)) {
        $perms[] = 'view_reports';
        DB::table('role')->where('id', $role->id)->update(['permissions' => json_encode($perms)]);
    }
});
```

**3. Ruta** (`routes/api.php`)

```php
Route::middleware(['permission:view_reports'])->group(function () {
    Route::get('/reports/revenue', [ReportController::class, 'revenue']);
});
```

**4. Controlador** — sólo traduce HTTP; la lógica va a un servicio.

**5. Frontend** — módulo en `services/api/reports.js`, página en `pages/` y ruta con
`meta: { permission: 'view_reports' }`.

**6. Test** en `tests/Feature/`.

**7. Documentación** — actualiza `API_REFERENCE.md`, `BITACORA_TECNICA.md`,
`MANUAL_USUARIO.md` y `BASE_DATOS.md` según corresponda.

### Ejemplo: nuevo comando RouterOS

Crea el manager en `app/Services/MikroTik/`, usa los traits
`BuildsCoreSshExec`, `DetectsSshExecFailures` y `VerifiesRouterOsObjectState`, y añade el
caso en `CustomerProvisioningService::provisionByControlMode()` si aplica al alta de cliente.
Cubre el manager con un test unitario que verifique **la cadena de comando generada**
(patrón de `CoreSshExecTest` y `FirewallRulesManagerTest`).

### Ejemplo: nuevo placeholder de documento

**Escalar** (texto plano, ej. `{{cliente.telefono}}`):

1. Añádelo a la whitelist del tipo correspondiente en `config/document_placeholders.php`
   (`{'namespace.campo' => 'Etiqueta legible'}`).
2. Resuélvelo en `App\Services\Templates\PlaceholderResolver::forInvoice()` /
   `forContract()` / `forInstallation()` — el array de retorno debe tener **exactamente**
   las mismas claves que la whitelist (`PlaceholderResolverTest` lo verifica con
   `assertEqualsCanonicalizing`). Si el dato puede no existir, cae a `''`, nunca `null`.
3. No hace falta tocar nada más: `apply()` ya escapa el valor con `htmlspecialchars()`.

**De bloque** (HTML de confianza, ej. una tabla o imagen — sólo úsalo si el contenido
necesita un `<img>`, atributos que el allowlist del tenant prohíbe, o repetir filas; para
todo lo demás, usa un placeholder escalar):

1. Whitelist en `config/document_placeholder_blocks.php`.
2. Partial Blade en `resources/views/documents/blocks/` — nunca pasa por
   `TemplateSanitizer`/`AdvancedTemplateSanitizer`, así que puedes usar cualquier tag/atributo.
3. Resuélvelo en `App\Services\Templates\BlockPlaceholderResolver`, envuelto en
   `safeRender()` (try/catch — un bloque que revienta al renderizar **nunca** debe tumbar
   el documento completo, se degrada a `''` y queda logueado).
4. **No** lo insertes directo con `str_replace`: `TemplateRenderer::compile()` /
   `compileAdvanced()` ya orquestan `BlockMarkerInjector::markify()` (antes de sustituir
   escalares) y `::splice()` (después) — ver `docs/ARQUITECTURA.md` § Plantillas de
   documentos para el porqué del orden.
5. Si el frontend debe ofrecerlo en el selector de placeholders, ya llega solo:
   `DocumentTemplateController::show()` expone `block_placeholders` desde el mismo config.

### Ejemplo: consecutivos (facturas y contratos)

Hay dos secuencias en el sistema y ambas siguen el mismo patrón; si algún día hace falta una
tercera (remisiones, órdenes…), cópialo tal cual en vez de inventar otro:

| Secuencia | Contador | Reserva | Respaldo |
|---|---|---|---|
| Facturas | `tenant.next_invoice_number` | `BillingService::generateInvoiceNumber()` | **UK** `(tenant_id, number)` en `invoices` |
| Contratos | `tenant.next_contract_number` + `tenant.contract_prefix` | `ContractNumberService::allocate()` | **UK** `(tenant_id, contract_number)` en `customer_documents` |

Las tres reglas que hacen que funcione:

1. **`DB::transaction` + `lockForUpdate` sobre la fila del tenant.** Sin el lock, dos
   peticiones concurrentes leen el mismo contador y emiten el mismo número. En SQLite el
   `for update` se ignora (la gramática lo compila a vacío), así que los tests pasan igual
   pero **no** demuestran la exclusión: eso lo garantiza la UK.
2. **Reserva antes de renderizar.** El número va impreso dentro del PDF, así que no puede
   asignarse después. Un render fallido quema el número; un hueco en la secuencia es mucho
   menos grave que un duplicado.
3. **Previsualizar no consume.** Usa el helper estático de formato
   (`ContractNumberService::format($prefix, $n)`) sobre el contador actual — nunca
   `allocate()` — en cualquier ruta de preview.

4. **Si el prefijo es configurable, no lo restrinjas para proteger el sistema de archivos.**
   Es el error que tuvo la primera versión: `regex:/^[A-Za-z0-9\-]+$/` en el `FormRequest`,
   porque el prefijo acababa dentro de la clave de S3. Eso le quitaba al ISP formatos
   perfectamente legítimos (`CNO/`, `Contrato N° `). Lo correcto es separar las dos cosas:

   | Cosa | Quién manda | Dónde |
   |---|---|---|
   | El número que se **imprime y se guarda** | El ISP, texto libre | `format()` |
   | El nombre del **archivo** en S3 | El sistema, saneado a ASCII | `fileName()` |

   `fileName()` translitera (`Nº` → `No`), reemplaza lo que no sea `[A-Za-z0-9._-]` por `-` y
   cae a `contrato` si no queda nada. La parte numérica siempre sobrevive, así que dos
   contratos del mismo cliente no colisionan aunque sus prefijos se saneen igual.

   Dos detalles que se aprendieron por las malas:

   - El separador se decide con `preg_match('/[\p{L}\p{N}]$/u', $prefix)` — sólo se añade `-`
     si el prefijo termina en letra o dígito. Si no, el ISP ya puso el suyo.
   - **El espacio final es significativo** (`Contrato N° `) y `TrimStrings` se lo comía. El
     campo está exceptuado en `bootstrap/app.php`. Si añades otro campo donde el espacio
     final signifique algo, tiene que ir en esa misma lista o nunca llegará a la base.

---

## 11. Trampas conocidas

| # | Trampa | Detalle |
|---|---|---|
| 1 | **`.env` local apunta a producción** | `DB_SCHEMA=public` = datos reales. Verifica antes de cualquier escritura |
| 2 | **`migrate` a secas** | Deja los esquemas desincronizados. Usa `migrate:both` |
| 3 | **`customer_profile.status` es booleano** | Compararlo con `'active'` lanza `22P02` en PostgreSQL y coincide con cero filas en SQLite |
| 4 | **`LIKE` sensible a mayúsculas** | Usa las macros `whereLike`/`orWhereLike`, que eligen operador por driver. Escribir `ilike` a pelo tiene el defecto simétrico: SQLite no lo conoce y revienta en los tests |
| 5 | **Scope de tenant sobre `Role`** | Si `users.tenant_id` ≠ `role.tenant_id`, el rol se anula y sale un falso 403. Carga el rol con `withoutGlobalScope('tenant')` |
| 6 | **Permiso nuevo sin backfill** | El frontend lee `role.permissions` de la base, no `getPermissionsByRole()`. Ejecuta `php artisan permissions:sync` |
| 7 | **Cifrado de credenciales** | Ya resuelto: se cifran en su propia columna y las `*_encrypted` se eliminaron. **No cifres columnas por las que se filtre en SQL** (`pppoe_username` tiene índice único): un valor cifrado no es consultable |
| 8 | **La IP del router deriva** | Usa `RouterEndpointResolver`, no `router->ip` directamente |
| 9 | **SSH del cliente puede no estar en el 22** | Usa `Router::sshPort()` y pasa `port=` |
| 10 | **`/ip hotspot user profile` no acepta `comment`** | Añadirlo rompe el comando entero |
| 11 | **Subida múltiple de fotos** | `413`/`504` sin JSON. Comprimir y enviar de una en una |
| 12 | **Push PPPoE síncrono** | 17–34 s por cliente; el gateway corta. Usa el camino asíncrono |
| 13 | **`cut_type` vacío en producción** | `OverdueSuspensionService` compara por el **nombre literal** `'Corte Automático'`. Sin filas en la tabla, nadie se corta |
| 14 | **`email_verified_at` no está en `$fillable`** | `User::create()` lo descarta en silencio y el usuario nace sin verificar → el login devuelve 403. Márcalo con `forceFill()` |
| 15 | **`SubstituteBindings` corre antes que el middleware de ruta** | En el grupo `api`, un id inexistente en una ruta con vinculación implícita (`destroy(Router $router)`) devuelve **404 antes** de comprobar el permiso. Al testear autorización, crea el registro |
| 16 | **No ocultes `password_rb` en `$hidden`** | `RouterEdit.vue` prellena el formulario con ese valor y lo reenvía al guardar: ocultarlo **borra la credencial** del router en la primera edición |
| 17 | **El modo de corte se compara normalizado** | Usa `CutType::matches()` / `isAutomatic()`, nunca `=== 'Corte Automático'`: una tilde de menos dejaba de cortar sin ningún error |
| 18 | **`*/` dentro de un docblock lo cierra antes de tiempo** | Un comentario tipo `/** ... on*/url() ... */` rompe con `syntax error, unexpected identifier` en la línea *siguiente*, no en la que tiene el error. Pasó dos veces escribiendo docs de `BlockMarkerInjector`/`AdvancedTemplateSanitizer` (`on*/url()`, `cliente.*/empresa.*`). Evita `algo*/algo` en prosa dentro de `/** */` |
| 19 | **No todas las propiedades CSS de HTMLPurifier están activas por default** | `CSSDefinition::doSetup()` sólo registra un subconjunto; `display`/`visibility`/`overflow`/`opacity` requieren `CSS.AllowTricky`, `border-radius` (y variantes) requiere `CSS.Proprietary` (no `Tricky`) — mensajes de error como `Style attribute 'X' is not supported` no dicen cuál flag falta. Verifica contra el código fuente de `CSSDefinition.php` instalado, no contra la memoria/docs generales de HTMLPurifier. **Nunca** actives `CSS.Trusted` (habilita `position`/`z-index`) ni `HTML.Trusted` (habilita `<script>`) para resolver esto |
| 20 | **`id` se descarta siempre sin `Attr.EnableID`** | Declarar `div[id]` en `HTML.Allowed` **no alcanza** — sin `Attr.EnableID => true`, HTMLPurifier lo quita en silencio. Con el flag activo, además valida sintaxis (rechaza valores como `javascript:alert(1)`) y fuerza unicidad en todo el documento (un `id` duplicado se descarta en la 2ª aparición) — verificado empíricamente antes de activarlo en `AdvancedTemplateSanitizer` (auditoría 2026-08-03) |
| 21 | **El serializador de libxml percent-codifica `\` en atributos URI** | Cualquier fragmento HTML que pase por el round-trip de `DOMDocument::saveHTML()` (ej. `BlockMarkerInjector::spliceIntoDom()`) y tenga un `src`/`href` con backslash lo convierte en `%5C` — invisible en producción (Linux, `public_path()` sólo da `/`), pero rompe rutas locales en un dev Windows. `BlockPlaceholderResolver::resolveLogo()` normaliza a `/` explícitamente antes de renderizar el partial por esto |
| 22 | **Nunca crees archivos/directorios a mano bajo `public_path('storage/...')` en un test** | Si el symlink de `php artisan storage:link` todavía no existe en ese entorno, un `mkdir(..., true)` recursivo lo reemplaza por un **directorio real** — y `storage:link` después se salta la creación porque el destino "ya existe", dejando el symlink roto de forma permanente (rompe el logo de **toda** la app, no sólo del test). Pasó de verdad en un dev Windows real escribiendo los tests de `empresa.logo` (auditoría 2026-08-03/04). Usa `Storage::disk('public')->put()/delete()` (escribe directo en `storage/app/public`, nunca toca `public/storage`) y, si el test necesita que el symlink exista, créalo defensivamente con `if (!is_link(public_path('storage'))) Artisan::call('storage:link')` — nunca con `mkdir()` |
| 23 | **Un campo `encrypted` no desencriptable tumba TODO el endpoint que lo toca, aunque sea sólo de paso** | `TenantController::show()` leía `$tenant->google_maps_api_key` (cast `encrypted`, sin relación con el resto del payload) sólo para armar un booleano — un `DecryptException` ahí tumbaba con 500 branding, nombre, dirección, todo. La `APP_KEY` de este `.env` local no coincide con la que cifró los datos sincronizados desde producción (`ispwatch_dev` **y** `public` fallan igual — no es cosa del schema), probablemente porque dev y producción tienen `APP_KEY` distintas por diseño (ver `MEJORAS_RECOMENDADAS.md` P-6). Cualquier acceso a un campo `encrypted` que no sea el propio flujo que lo espera (conexión a router, túnel VPN) debe envolverse — patrón: `TenantController::safeGoogleMapsApiKey()` |
| 24 | **`insertBefore()` con un `DocumentFragment` vacío dispara un warning de PHP** | `BlockMarkerInjector::replaceMarkersInTextNode()` construía el fragmento a insertar sin comprobar si el valor del bloque era `''` (caso normal y frecuente: `{{empresa.logo}}` sin logo subido, `{{contrato.firma_cliente}}` antes de firmar) — `insertBefore($fragmentoVacio, $textNode)` dispara "Document Fragment is empty", detectado sólo al probar end-to-end con un HTML real (2026-08-04), ningún test unitario aislado lo cubría. Saltar el `insertBefore()` cuando el fragmento es `''` es equivalente a insertar "nada" — mismo resultado, sin el warning |
| 25 | **dompdf y las alturas fijas en tablas: páginas en blanco** | `height` en `<table>`/`<td>` (atributo o CSS) es un MÍNIMO en un navegador, que se ignora cuando el contenido crece; dompdf lo trata rígidamente y genera páginas EN BLANCO. `AdvancedTemplateSanitizer::fixDompdfPaginationQuirks()` lo retira de toda la familia `<table>` (no de `<img>`/`<div>`, donde es legítimo). Medido sobre un contrato real: sólo quitando las alturas, 8 páginas con 3 en blanco → 7 con 1 |
| 26 | **dompdf no parte una celda de tabla entre páginas — y RECORTA lo que no cabe** | Un `<td>` cuyo contenido excede el alto de una página se empuja entero a la siguiente (dejando la anterior en blanco) y el excedente **se pierde**, sin ningún error. Medido sobre un contrato real: el mismo bloque como tabla daba 7 páginas / 1 en blanco / 15.847 caracteres; convertido a `<div>` (texto plano idéntico), 6 páginas / 0 en blanco / **17.682** caracteres. **Nunca envuelvas contenido que pueda superar una página en una celda de tabla**; usa `<div>`, que fluye entre páginas. No se corrige en el sanitizer a propósito: saber si el contenido desbordará exige renderizar, y convertir tablas a divs a ciegas alteraría el diseño del tenant |

---

## 12. Solución de problemas

| Problema | Diagnóstico | Solución |
|---|---|---|
| **No se generan facturas** | `php artisan billing:verify-monthly` | Si reporta `no_show`, el planificador no corre. Ver §7 |
| **Un router concreto no factura** | Revisa `billing_router_id` y `create_invoice` | Asigna configuración de facturación al router |
| **Un cliente no recibe factura** | ¿`user_services.status = 'active'`? ¿`exclude_from_billing`? ¿plan de cortesía? ¿lápida `suppressed` en `billing_action_logs`? | Según el caso |
| **"El cliente abonó y no se le cortó"** | Por diseño: un abono parcial cierra la factura (`paid`, `carried_out > 0`) y saca al cliente de la mora | El faltante se cobra en la próxima factura. Ver `invoice_carryovers` (`status = pending`) |
| **Una factura salió más cara de lo esperado** | ¿Tiene un ítem `carryover`? `invoices.carried_in > 0` | Es el arrastre de un abono parcial anterior; `invoice_carryovers.from_invoice_id` dice de cuál |
| **El arrastre no volvió al anular el pago** | Ya estaba `applied`: otra factura lo cobró | Correcto: devolverlo lo cobraría dos veces. Si hay que deshacerlo, borrar la factura que lo cobró lo devuelve a `pending` |
| **`SQLSTATE 22P02`** | Comparación de booleano con cadena | Corrige la consulta a `where('status', true)` |
| **Falso `403 No role assigned`** | Desajuste de `tenant_id` entre usuario y rol | Carga el rol con `withoutGlobalScope('tenant')` |
| **Pestaña ausente para un admin** | Permiso nuevo sin backfill | Migración de backfill, o marcar en `/roles` y re-loguear |
| **`<connection failed> <ip>:22`** | IP obsoleta o puerto SSH distinto | `RouterEndpointResolver` + `router.puerto_ssh`. El CORE necesita `forwarding-enabled=both`. Comprueba que el puerto llegue **hasta el `ssh-exec`**: si un método intermedio no lo recibe en su firma, el `port=` se pierde y todo cae al 22 |
| **Aprovisionamiento con éxito pero sin efecto** | `ssh-exec` con `exit-code ≠ 0` | Revisar centinelas `ISP_BEGIN`/`ISP_FAIL`/`ISP_END` en el log |
| **Cliente cortado sigue navegando** | Faltan reglas en el router o falta flush de conntrack | *Aplicar reglas de bloqueo* + `billing:reconcile-suspensions` |
| **Toda la suite Feature falla** | SQL exclusivo de PostgreSQL en una migración | Protégelo por driver |
| **Error CORS/CSRF con Vite** | Host de Vite ausente | Añádelo a `SANCTUM_STATEFUL_DOMAINS` y `CORS_ALLOWED_ORIGINS` |
| **`504` al importar** | Consultas por fila | Precargar modelos en bloque |
| **Documentos no se ven** | Bucket privado | La URL firmada dura 30 minutos; recarga la vista |
| **PDF sin datos de empresa** | `tenant` incompleto | Completar identidad fiscal en Configuración |

### Logs útiles

```bash
php artisan pail                            # log en vivo
tail -f storage/logs/laravel.log

# Filtrar por módulo
grep "Billing:"   storage/logs/laravel.log
grep "Auto-cut:"  storage/logs/laravel.log
```

Los servicios registran con prefijos consistentes (`Billing:`, `Auto-cut:`) y los comandos
MikroTik incluyen `variant`, `envelope_state` y `exit_code` para diagnóstico exacto.
