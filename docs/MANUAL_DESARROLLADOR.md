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
| `MIKROTIK_CORE_SSH_CONNECT_TIMEOUT` | Segundos para el *handshake* servidor→CORE (por defecto 8). Falla rápido si el CORE no responde |
| `MIKROTIK_CORE_SSH_TIMEOUT` | Segundos de espera por la **salida** de un comando en el CORE (por defecto 15) |
| `MIKROTIK_CORE_SSH_EXEC_TIMEOUT` | Igual, pero para los comandos que hacen al CORE abrir un SSH **anidado** al cliente (lectura de interfaces WAN). Por defecto 25, acotado a 10-50 para no rebasar el límite del *gateway* |
| `MIKROTIK_TUNNEL_READY_TIMEOUT` | Segundos que se espera a que el `ssh -L` quede listo (por defecto 6) |
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

### Cobertura actual (51 archivos, 413 tests)

| Suite | Archivos |
|---|---|
| `Feature/Billing` | `AutoCutoffTest`, `AutoReconnectOnPaymentTest`, `BillingEventTimeTest`, `BillingModuleTest`, `DeleteInvoiceTest`, `FirstInvoiceFreeMonthsTest`, `FirstInvoiceProrationTest`, `MarkInvoiceUnpaidTest`, `PaymentReminderTest`, `PaymentsListFilterTest`, `ReconcileSuspensionsTest`, `RouterMonthlyBillingTest`, `VerifyAutomaticCutsTest`, `VerifyMonthlyBillingTest` |
| `Feature/Documents` | `BillingPdfDownloadTest`, `CustomerContractSignTest`, `DocumentTemplateControllerTest`, `InstallationSheetSignTest` |
| `Feature/Auth` | `ApiAuthorizationTest` (42: permiso por endpoint, OR, bypass admin, unión de permisos), `ApiLoginTest` (7: login real, verificación, rate limit), `RolePermissionsSyncTest` (7) |
| `Feature/Router` | `RouterOutageTest` |
| `Feature/Inventory` | `InventoryImportTest` |
| `Feature` (raíz) | `BillingTest`, `StaffDeletionTest`, `SecurityHeadersTest`, `TemplateRendererFallbackTest`, `TemplateRendererBlockPlaceholdersTest`, `TemplateRendererAdvancedModeTest`, `TemplateRendererPageSetupTest` (papel por plantilla, verificado sobre el `/MediaBox` del PDF real), `TenantBrandingConfigTest`, `TenantLogoUploadTest` |
| `Unit` | `CoreSshExecTest`, `FirewallRulesManagerTest`, `InterfaceReaderTest`, `NormalizesRouterCommentTest`, `PppProfileManagerTest`, `WireguardTransportTest` |
| `Unit/Services` | `PlaceholderResolverTest`, `BlockPlaceholderResolverTest`, `BlockMarkerInjectorTest`, `TemplateSanitizerTest`, `AdvancedTemplateSanitizerTest`, `TemplateDiagnosticsTest` |
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

### Ejemplo: exponer datos nuevos en la API pública

La API de integraciones (`/api/v1/partner`) es un **contrato con un tercero** y su
superficie es deliberadamente estrecha. Antes de agregar nada, dos reglas que no son
negociables:

1. **Nunca reutilices un controlador del panel.** Los del panel devuelven el modelo
   entero, y ahí viajan `pppoe_password`, `hotspot_password` y credenciales de router.
2. **Nunca confíes sólo en el global scope para el tenant.** `customer_profile` no tiene
   `tenant_id` propio: su frontera es el join con `users`. Un endpoint que lo olvide
   devuelve la base de clientes completa de la plataforma sin dar ningún error.

**1. Ability** (`config/api_keys.php`) — sólo si el área es nueva:

```php
'abilities' => [
    // …
    'read:inventory' => 'Inventario (equipos y existencias)',
],
```

Agregar el ability aquí **no abre nada por sí solo**: alimenta las casillas del panel y
la validación. El acceso lo concede el `ability:` de la ruta.

**2. Controlador** en `app/Http/Controllers/Api/Partner/`, extendiendo `PartnerController`:

```php
class PartnerInventoryController extends PartnerController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate($this->commonRules() + ['status' => 'sometimes|string|max:30']);

        $tenantId = $this->tenantId($request);   // ← de la llave, nunca del request

        $query = InventoryDevice::query()
            ->where('inventory_devices.tenant_id', $tenantId)   // ← explícito, siempre
            ->select([                                          // ← lista blanca
                'inventory_devices.id',
                'inventory_devices.serial',
                'inventory_devices.status',
            ]);

        return $this->paginated($query, $request, fn ($row) => [
            'id'     => (int) $row->id,
            'serial' => $row->serial,
            'status' => $row->status,
        ]);
    }
}
```

El `select()` con columnas explícitas es lo que hace que agregar mañana una columna
sensible a esa tabla no la publique sola. `paginated()` aplica el tope de 100 por página
y la envoltura `{data, meta}`.

**3. Ruta** (`routes/api.php`, grupo `v1/partner`):

```php
Route::middleware('ability:read:inventory')->group(function () {
    Route::get('/inventory', [PartnerInventoryController::class, 'index']);
});
```

**4. Test de aislamiento** — no es opcional. Puebla **dos** tenants y afirma que sólo
sale el propio; afirmar que «sale algo» no prueba nada:

```php
$this->assertCount(1, $response->json('data'));
$response->assertJsonMissing(['serial' => 'DEL-TENANT-B']);
```

Ver `tests/Feature/ApiKeys/PartnerApiIsolationTest.php`.

**5. Documenta el endpoint** en `API_REFERENCE.md` § 22. Es un contrato publicado: si no
está escrito, el integrador no puede usarlo.

> ⚠️ **No agregues verbos de escritura ni endpoints que hablen con el router.** La API es
> de lectura por diseño, y una llamada al CORE tarda 17-34 s: un integrador con
> reintentos agotaría el pool de conexiones y tumbaría el aprovisionamiento y el corte
> para todos los tenants.

### Ejemplo: mover existencias de inventario

**Nunca escribas `inventory_device.status`, `inventory_balances` ni `inventory_movements` a
mano.** Todo pasa por `App\Services\Inventory\InventoryLedger`, que mueve la existencia y escribe
el kardex dentro de la misma transacción. Un controlador que actualice el estado por su cuenta
deja el historial sin explicar el saldo, y no hay forma de reconstruirlo después.

```php
public function __construct(private InventoryLedger $ledger) {}

// Entregar un equipo a un técnico
$this->ledger->transferDevice($device, InventoryMovement::HOLDER_USER, $tech->id, $actor, 'Entrega');

// Descargar un equipo en una instalación (valida custodia y lanza ValidationException)
$this->ledger->assignDeviceToInstallation($installation, $device, $actor);

// Material por cantidad (descuenta saldo; falla si no alcanza)
$this->ledger->assignMaterialToInstallation($installation, $stock, 4.0, 'user', $tech->id, $actor);

// Deshacer: devuelve la existencia a quien la aportó (source_type/source_id de la línea)
$this->ledger->releaseFromInstallation($item, $actor);
```

Para añadir un tipo de movimiento nuevo: constante en `InventoryMovement`, método público en el
ledger que llame a `record()` dentro de su `DB::transaction`, y una entrada en el `match` de
`InventoryMovementController::holderLabel()` si estrena un extremo (`from_type`/`to_type`).

### Ejemplo: nuevo comando RouterOS

Crea el manager en `app/Services/MikroTik/`, usa los traits
`BuildsCoreSshExec`, `DetectsSshExecFailures` y `VerifiesRouterOsObjectState`, y añade el
caso en `CustomerProvisioningService::provisionByControlMode()` si aplica al alta de cliente.
Cubre el manager con un test unitario que verifique **la cadena de comando generada**
(patrón de `CoreSshExecTest` y `FirewallRulesManagerTest`).

> **Si el recurso se crea, alguien tiene que poder borrarlo.** Durante dos años todos los
> managers tuvieron sólo métodos `ensure*` y ninguno de borrado; el resultado fue que eliminar
> un cliente le dejaba el servicio funcionando (ver `BITACORA_TECNICA.md` § 19). Cuando añadas
> un recurso nuevo, añádelo también al barrido de
> `App\Services\MikroTik\CustomerDeprovisionManager` o quedará como residuo permanente en el
> equipo.

Tres reglas del barrido de borrado que no son evidentes y ya costaron un bug:

1. **En RouterOS un `remove [find ...]` que no encuentra nada es un ERROR**, no un no-op:
   aborta el script. Cada sentencia va envuelta en su propio `:do { } on-error={}` o el primer
   recurso ausente impide borrar los demás.
2. **Nunca emitas un `find` sin criterio.** Sin una clave por la que buscar borrarías recursos
   de otros clientes; si no hay identidad, no se manda nada.
3. **La IP y la MAC se interpolan sin comillas**, así que se validan con `FILTER_VALIDATE_IP` y
   una expresión regular canónica antes de entrar al script. Los nombres van entre comillas y
   se escapan con `addcslashes($v, "\\\"\$")`, igual que en el resto de managers.

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

> **Un placeholder desconocido se blanquea, pero ya no en silencio.**
> `PlaceholderResolver::apply()` reemplaza por `''` cualquier `{{…}}` que no esté en el array
> resuelto — deliberado, para que un typo nunca rompa el render. Eso no cambió; lo que cambió
> el 2026-08-06 es que `TemplateDiagnostics` avisa por qué (ver abajo). El silencio era la
> causa raíz del reporte del 2026-08-05 con una plantilla migrada de WispHub
> (`docs/BITACORA_TECNICA.md` § 15 y § 16).

### Ejemplo: enseñarle al sistema un marcador de otro sistema

Cuando un tenant migra desde otra plataforma, sus marcadores no coinciden con los de ISPwatch
y el documento sale con los datos en blanco. Para que la app lo explique en vez de dejarlo
adivinar, agrega la equivalencia a `config/document_placeholder_aliases.php`:

```php
'scalar' => [
    'common'   => ['cliente_nombre' => 'cliente.nombre'],   // aplica a los 3 tipos
    'contract' => ['fecha_instalacion' => 'contrato.fecha'], // el tipo gana sobre 'common'
],
'literal' => [   // marcadores SIN llaves: aquí son texto y se imprimen tal cual
    'contract' => ['NUMERO_CONTRATO_TAG' => 'contrato.numero'],
],
```

Cuatro cosas que no son evidentes:

1. **Es un catálogo de diagnóstico, no de resolución.** `PlaceholderResolver` sigue sin
   conocer estos nombres: el alias sólo alimenta el mensaje de aviso. Traducir automáticamente
   dejaría la plantilla guardada diciendo una cosa y el PDF imprimiendo otra, y el tenant
   nunca aprendería el vocabulario real.
2. **La misma etiqueta puede significar cosas distintas según el tipo.** `fecha_instalacion`
   es la fecha de firma en un contrato y la fecha de la orden en una hoja de instalación; por
   eso la tabla está partida por tipo y no es una lista plana.
3. **`literal` es para marcadores sin llaves.** No los ve el escaneo de `{{…}}` porque para
   ISPwatch son texto normal — se imprimen tal cual en el PDF, que es un síntoma distinto
   ("me sale un texto raro") al de un marcador que se blanquea.
4. **No toques el frontend.** El mensaje se arma en PHP y viaja ya escrito en
   `X-Template-Warnings`; el editor sólo lo lista. Así se verifica en
   `TemplateDiagnosticsTest` junto con la detección que lo origina.

`TemplateDiagnosticsTest::test_no_token_from_the_official_catalogue_is_ever_flagged` recorre
el catálogo completo de los 3 tipos: si agregas un placeholder nuevo y el diagnóstico lo
marcara como desconocido, ese test falla antes de que un tenant vea un aviso que miente.

### Ejemplo: agregar una plantilla base al editor

1. Escribe el documento en `resources/document-starters/{tipo}/{slug}.html` como **HTML plano**.
   No es una vista Blade y no debe serlo: Blade interpretaría cada `{{marcador}}` como una
   expresión PHP y reventaría al compilar. Se lee con `file_get_contents`, nunca se compila.
2. Regístrala en `config/document_template_starters.php` con `advanced`, `page_size` y
   `page_orientation` — son los que la plantilla **necesita** para verse bien, no sugerencias:
   el frontend los aplica al cargarla.
3. Ya aparece en el editor: `DocumentTemplateController::show()` expone el catálogo del tipo.

Tres reglas para que el PDF no salga roto, todas aprendidas midiendo (ver `BITACORA_TECNICA.md`
§ 15 y § 17):

- **Nunca metas texto largo en una celda de tabla.** dompdf parte una tabla entre filas, pero
  **no** parte una celda: si una celda no cabe en la hoja, recorta el excedente sin avisar. Para
  un diseño a dos columnas, usa una tabla con muchas filas cortas (una sección por celda), no
  dos columnas gigantes. La primera versión del contrato CRC ocupaba 6 páginas por esto; con la
  misma cantidad de texto repartida en filas cortas ocupa 2.
- **Respeta el ancho imprimible.** No es el de la hoja: dompdf mete **1,2 cm** de margen por
  lado (`@page` de `dompdf/lib/res/html.css`), o sea 45 px a 96 dpi. A4 vertical deja
  **703 px**, A4 horizontal **1032 px**, Carta vertical **726 px**. Una tabla con ancho fijo
  mayor que eso no se encoge — se desborda sobre lo que tenga al lado y el PDF sale con los
  textos montados. El editor visual ya edita dentro de ese ancho exacto y avisa cuando no cabe
  (`HtmlDocumentEditor`, evento `@fit`).
  > Estas cifras eran 698/1027/720 hasta el 2026-08-06: el editor tenía sus propias constantes
  > y usaba 1,27 cm de margen, que dompdf nunca aplicó. Ahora salen de
  > `App\Services\Templates\PdfPageGeometry` y **el frontend no calcula ninguna** — llegan por
  > `GET /document-templates/{type}` (`page_metrics`). Si cambias el dpi o el margen, se cambia
  > ahí y en un solo sitio.
- **Nada de alturas fijas.** `height=` en tablas sólo produce páginas en blanco.
- **Imágenes: `{{empresa.logo}}` o `data:`, nunca `https://`.** dompdf corre con
  `enable_remote = false` y una `<img>` a internet sale rota siempre. Desde el 2026-08-06 el
  sanitizer del modo avanzado acepta `data:` para `image/jpeg|gif|png` (valida los bytes
  reales, no el mime declarado; SVG queda fuera porque puede llevar script), así que una imagen
  embebida sí llega al PDF.
- **Declara una fuente que dompdf tenga, o al final de la pila.** dompdf no lee las fuentes del
  sistema: sólo conoce `serif`/`sans-serif`/`monospace`, `times`, `helvetica`, `courier`,
  `symbol`, `zapfdingbats` y las tres DejaVu. `font-family: Calibri` cae a Times y el texto
  ocupa distinto que en el editor; `font-family: Calibri, Arial, sans-serif` funciona, porque
  dompdf recorre la pila. `TemplateDiagnostics` lo avisa (`unsupported_font`).
- **Sólo marcadores del catálogo del tipo.** `DocumentStarterLibraryTest` corre
  `TemplateDiagnostics` sobre cada plantilla base y falla si aparece uno que el sistema no
  resuelve; `DocumentTemplateControllerTest` las renderiza todas y exige un PDF real sin avisos.
  Entre las dos, una plantilla base rota no llega a producción.

### Ejemplo: cambiar el tamaño o la orientación del PDF

Vive en `document_templates.page_size` / `page_orientation` y lo aplica
`TemplateRenderer::applyPaper()` con `setPaper()`. Tres cosas que no son evidentes:

1. **Hay 6 caminos, no 3.** `renderInvoice`/`renderContract`/`renderInstallationSheet` leen
   el papel de la fila; los tres `preview*` lo reciben **por parámetro**, porque la vista
   previa debe reflejar lo que el tenant tiene seleccionado en el editor sin haber guardado.
   Si agregas un camino nuevo de render, tiene que pasar por `applyPaper()` o saldrá con el
   default sin que nada avise.
2. **La ruta legacy (sin fila en `document_templates`) no lleva `setPaper()`** a propósito:
   se queda con el default de `config/dompdf.php`, que es lo que hacían todas antes de que
   existieran estas columnas.
3. **`applyPaper()` revalida contra `DocumentTemplate::PAGE_SIZES`/`PAGE_ORIENTATIONS`**
   aunque el FormRequest ya validó. No es redundancia decorativa: ver la trampa 30 abajo.

Para agregar un tamaño nuevo hay que tocar **tres** sitios: `DocumentTemplate::PAGE_SIZES` (la
columna es texto, no enum, justamente para no necesitar una migración), la tabla `PAPER_PT` de
`PdfPageGeometry` (si no, el editor no sabe cuánto mide la hoja y cae al default) y los
`<option>` de `DocumentTemplatesSection.vue`.

### Ejemplo: que el editor y el PDF se vean igual

Son dos motores distintos: el editor es el navegador, el PDF lo genera dompdf. La paridad se
sostiene en tres piezas, y romper cualquiera devuelve el problema de "en pantalla se ve bien y
el PDF sale horrible":

1. **`PdfPageGeometry` es la única definición de los números.** Papel, margen, dpi, `font-size`
   y `line-height` por defecto. El frontend los pide, no los calcula. `PdfPageGeometryTest` los
   contrasta contra el dompdf instalado (`lib/res/html.css`, `CPDF::$PAPER_SIZES`,
   `Css\Style::$default_line_height`) y contra el Blade de cada shell: si actualizas dompdf y
   cambia un default, el test falla en vez de que se entere un tenant.
2. **Dos CSS base, según lo que se edite.** Un documento completo (modo avanzado) hereda los
   defaults de dompdf → `editorBaseCss()`. Un fragmento (modo seguro) va dentro de
   `.custom-block` del shell y hereda la letra del shell → `editorFragmentCss($type)`. Ambos se
   inyectan en el iframe **antes** del `<style>` del tenant, para que él siga ganando.
3. **El panel "PDF real" es el árbitro.** Llama al mismo `POST .../preview` con *debounce*, así
   que lo que muestra no es una imitación: es el PDF. Cuando algo no se puede imitar en el
   editor (`float`, `position`, flexbox), es ahí donde se ve la verdad.

**Trampa:** al leer el valor del editor (`readValue()`) hay que quitar todo lo que sólo existe
mientras se edita — las dos hojas de estilo del editor, el `contenteditable` del body y las
imágenes que sustituyen a un marcador. Se hace sobre una **copia** del documento. Si se te
olvida algo ahí, se guarda dentro de la plantilla del tenant y sale impreso en el PDF (el fondo
gris del editor, las guías de página, o la URL del logo congelada, que dejaría de actualizarse
cuando el tenant cambie de logo).

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

### Ejemplo: añadir un filtro por columna a un listado

Recaudos, Facturación y Gastos siguen la misma estructura de tres piezas. Cópiala; no
inventes una variante:

```php
// 1. Reglas — compartidas por el listado Y la exportación.
private function validatedInvoiceFilters(Request $request): array
{
    return $request->validate([
        'total_min' => 'nullable|numeric',
        'sort_by'   => 'nullable|in:issue_date,due_date,number,total,balance_due',
        'per_page'  => 'nullable|integer|min:1|max:200',
    ]);
}

// 2. Consulta — SIN orden ni paginación, para que el CSV no pueda divergir
//    de lo que el usuario tiene en pantalla.
private function filteredInvoicesQuery(Request $request, array $f)
{
    // Texto: SIEMPRE con las macros, nunca `like` ni `ilike` a pelo (trampa 4).
    if (!empty($f['number'])) $query->whereLike('number', $f['number']);

    // Importes: `isset`, no `!empty` — 0 es un valor válido y `!empty(0)` es false.
    if (isset($f['total_min'])) $query->where('total', '>=', $f['total_min']);

    // El tenant sale del usuario autenticado, jamás de un query param.
    ...
}

// 3. Listado — orden con desempate estable por `id`.
$query->orderBy($f['sort_by'] ?? 'issue_date', $f['sort_dir'] ?? 'desc')
      ->orderBy('id', 'desc')
      ->paginate($f['per_page'] ?? 20);
```

Cuatro reglas que no son opcionales:

- **`sort_by` va en lista blanca (`in:`)**: entra directo en el `ORDER BY`. Sin la lista, el
  parámetro es una inyección.
- **El desempate por `id`** hace falta en cualquier columna que repita valores (`issue_date`
  es una fecha sin hora y toda la facturación mensual comparte día): sin él, dos páginas
  repiten u omiten filas.
- **El `summary` se calcula sobre la MISMA consulta filtrada** (`clone $query`), no sobre la
  página ni en un endpoint aparte: si no, la cifra y la lista pueden responder a filtros
  distintos sin que nada lo delate.
- **El frontend no manda los vacíos** y devuelve a la página 1 en cada cambio de filtro; los
  campos de texto van con `debounce` de 400 ms y descartan respuestas viejas con un
  `requestId`, o al teclear rápido la primera en llegar pinta resultados obsoletos.

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
| 27 | **Una ruta literal debe declararse ANTES que su hermana con `{id}`** | `Route::get('/billing/invoices/{id}')` captura también `/billing/invoices/export` si se registra primero: la petición entra a `show('export')` y devuelve un 404 de "factura no encontrada" en vez de exportar — sin ningún indicio de que el problema es el orden de las rutas. Las tres rutas `/export` de Finanzas van deliberadamente encima de sus `{id}` |
| 28 | **Un CSV para Excel en español necesita `;`, BOM y coma decimal** | Con configuración regional es-CO: separado por comas, Excel apelmaza todas las columnas en una; sin BOM UTF-8, las tildes salen como `Ã³`/`Ã±`; y un importe escrito `50000.00` se lee como **texto** (no suma) porque el separador decimal esperado es `,`. El trait `ExportsCsv` resuelve los tres (`;`, `\xEF\xBB\xBF`, `number_format(..., ',', '')`) — no "corrijas" el separador a coma sin probarlo en un Excel es-CO real |
| 29 | **Un `default` en la migración NO le da valor al objeto recién creado** | `CustomerAdditionalService::create([...])` sin `is_active` ni `quantity` devuelve una instancia con **ambos en `null`**: el default de PostgreSQL protege a la *fila*, no al *objeto* que quedó en memoria. La fila en base sale bien; el modelo que tienes en la mano, no — hasta que lo relees. Lo detectó un test de la fase 1 de servicios adicionales (`coversPeriod()` daba la asignación por inactiva), pero el daño real habría llegado al cobrar: `unit_price * null` deja el cargo en **cero**, sin error y sin que nadie lo note. Repite los defaults en `protected $attributes` de todo modelo cuyos valores se lean antes de releer la fila |
| 30 | **Una relación cuyo snake_case coincide con una columna FK la PISA en el JSON** | La relación `assignedBy()` se serializa como `assigned_by` — exactamente el nombre de la columna FK. Con la relación cargada, `assigned_by` deja de ser un id y pasa a ser un objeto; sin cargar, vuelve a ser un id. La misma clave significa dos cosas según el `->with()` del controlador, y quien consume la API no tiene forma de saber cuál le va a llegar. Detectado por un test de la fase 3 de servicios adicionales (`assertJsonPath('assigned_by', $id)` recibía un array). Se renombró a `assigner()`, conservando `'assigned_by'` como FK. **Nunca nombres una relación igual que su propia columna FK** — pasa con todo par `xxxBy()`/`xxx_by` y `xxxTo()`/`xxx_to` |
| 27 | **Cachear el contexto 2D de un `<canvas>` en una variable de `<script setup>`** | El canvas de firma de `InstallationDetail.vue` vive dentro de un `v-if="loading"`/`v-else`: **cada recarga de la orden lo desmonta y monta otro**. Un `let ctx = canvas.getContext('2d')` guardado aparte sigue apuntando al canvas viejo, ya fuera del DOM → el trazo se dibuja donde nadie lo ve y `toDataURL()` del canvas nuevo devuelve un PNG transparente que el backend acepta como firma válida (era exactamente el bug de "la firma no se ve ni se guarda", 2026-08-05). Cachea el contexto en un `WeakMap` **por elemento**, refresca sin desmontar (`loadInstallation({ silent: true })`) y comprueba que haya trazo real barriendo el canal alfa (`canvasHasInk()`), nunca con una bandera reactiva |
| 28 | **`frame-src` no se hereda gratis de `default-src`** | Mostrar un PDF generado en el navegador (`<iframe src="blob:…">`) exige `frame-src 'self' blob:` explícito en `SecurityHeaders`; sin él la CSP cae en `default-src 'self'` y el navegador rechaza el frame — recuadro gris con icono de documento roto, **cero rastro en los logs del servidor**. Cualquier cambio de CSP se despliega con el **backend**: subir sólo el frontend no arregla nada. Fijado en `SecurityHeadersTest` |
| 29 | **Un `<img>` a `public_path('storage/…')` en un PDF NO funciona: los archivos están en S3** | La hoja de instalación pintaba recuadros de imagen rota durante meses sin que nadie lo notara (dompdf no lanza error: dibuja el hueco). Para meter un archivo de S3 en un PDF hay que **leerlo e incrustarlo como data URI**; `enable_remote = false` además impide cualquier fetch por URL. El logo del tenant sí funciona porque vive en el disco `public` local, no en S3 |
| 30 | **`setPaper()` con un tamaño desconocido no falla: se queda callado** | dompdf no lanza excepción ni loguea si le pasas `'papiro'`; produce un canvas raro y sigue. Por eso `TemplateRenderer::applyPaper()` revalida contra `DocumentTemplate::PAGE_SIZES`/`PAGE_ORIENTATIONS` y cae al default aunque `UpdateDocumentTemplateRequest` ya haya validado en la entrada — la fila puede venir de un `UPDATE` a mano o de una migración desde otro sistema |
| 31 | **`Barryvdh\DomPDF\PDF` resuelve casi toda su API por `__call()`** | `setPaper()`, `setWarnings()` y compañía **no están declarados** en la clase: se reenvían al `Dompdf` interno. Mockery valida contra los métodos reales, así que un `Mockery::mock(PDF::class)` revienta con `BadMethodCallException: Method … does not exist on this mock object` **señalando el código de producción, no el test**. Los mocks de PDF llevan `->shouldIgnoreMissing(\Mockery::self())` por esto; cualquier método nuevo del wrapper que llames los va a romper igual (`docs/MEJORAS_RECOMENDADAS.md` P-14) |
| 32 | **Un diseño a dos columnas no cabe en A4 vertical** | A 96 dpi, A4 vertical deja ~698 px útiles y horizontal ~1027 px. Un contrato CRC a dos columnas necesita ~950 px: en vertical dompdf lo aprieta y descuadra la maquetación entera, **sin error alguno**. Se resuelve con `page_orientation = 'landscape'` en la plantilla, no rediseñando un formato regulado |

| 33 | **Un `delete payload.campo` en el frontend es indistinguible de "no se guardó"** | `InstallationDetail.vue::buildSheetPayload()` borraba `client_ip` del payload cuando el core tenía PPPoE: el técnico escribía la IP del cliente, el POST salía sin ella y el backend guardaba bien lo que le llegó. Ni error, ni log, ni validación que se queje — sólo un campo vacío la próxima vez que se abre la orden. Si un campo se muestra, se guarda; si no se debe guardar, no se debe mostrar. Ver `BITACORA_TECNICA.md` § 20 |
| 34 | **Un formulario largo con un solo botón de guardar al final** | La tarjeta *Conexión / Red* de `InstallationDetail.vue` no tenía botón propio: el único `Guardar hoja` vivía en la tarjeta siguiente y guardaba las dos, pero nada lo decía en pantalla. Llenar la primera y salir perdía el trabajo en silencio. Cada bloque visualmente independiente necesita su acción de guardado (aunque llame al mismo handler) |
| 31 | **El `$periodStart` de `createMonthlyInvoiceFor()` NO siempre es el día 1** | Llega como `$charge['period_start']`, y en una primera factura prorrateada eso es el **día de instalación** (`2026-07-11`), no el inicio del mes. Cualquier cálculo que necesite el mes natural —la ventana de vigencia de un servicio adicional, su prorrateo— debe derivarlo de `$periodEnd->copy()->startOfMonth()`, que sí es siempre fin de mes en ese método. Usar el `$periodStart` recibido prorratea por error asignaciones antiguas, y el error sólo aparece en clientes instalados a mitad de mes |
| 35 | **Una relación NO puede llamarse igual que una columna del mismo modelo** | `customer_installations` tiene una columna `equipment` (texto libre "equipo previsto"). Al añadir la relación `equipment()` hacia `installation_equipment`, `$installation->equipment` seguía devolviendo **el string**: Eloquent resuelve primero `$attributes` y sólo cae a las relaciones si no hay atributo con ese nombre — ni siquiera un `loadMissing('equipment')` previo cambia eso, porque la relación queda cargada pero inalcanzable por la propiedad. La vista del PDF habría hecho `->count()` sobre un texto. Se llama `equipmentItems()`. Es la trampa gemela de la #30 (relación que pisa una FK), pero al revés: aquí la **columna** gana |
| 37 | **El tenant NUNCA sale de un parámetro de la petición** | Dos casos vivos encontrados el 2026-08-06: `billing/stats` lo leía de `?tenant=` (cualquiera con `view_billing` podía pedir las finanzas de otra empresa cambiando la URL) y `routers/{id}/free-ips` de `?tenant_id=` con un `if ($tenantId)` que, al no llegar nunca desde el frontend, dejaba la consulta **sin filtro** y escondía IPs libres. Deriva siempre de `$request->user()->tenant_id`, o usa `BelongsToTenant` — y desconfía de todo `if ($tenantId)`: un filtro condicional es un filtro que algún día no se aplica |
| 36 | **Un `whereIn` polimórfico con NULL no filtra: usa un índice único sin nulos** | En `inventory_balances` el custodio es `holder_type` + `holder_id` **NOT NULL** en vez de `branch_id`/`user_id` nulables, porque el índice único `(tenant_id, stock_id, holder_type, holder_id)` es lo que impide saldos duplicados — y en PostgreSQL **dos NULL son distintos entre sí**, así que un único sobre columnas nulables deja pasar duplicados en silencio. Si necesitas unicidad sobre "una de dos referencias", conviértelo en par tipo+id antes que en dos columnas nulables |
| 38 | **Un `try/catch` NO protege una transacción de PostgreSQL: hace falta un SAVEPOINT** | Una sentencia que falla deja la transacción **abortada**, y desde ahí toda consulta revienta con `SQLSTATE[25P02] current transaction is aborted` aunque la excepción se haya atrapado — sólo un `ROLLBACK` la desbloquea, y sólo un `ROLLBACK TO SAVEPOINT` sin perder lo anterior. `MoneyAuditObserver::write()` tenía el `try` y aun así tumbaba el `Payment::create()` que auditaba. Toda escritura accesoria que no deba tumbar la operación principal (bitácora, métricas, notificaciones) va envuelta en `Connection::transaction()`, que emite el SAVEPOINT solo cuando ya hay transacción abierta. **En sqlite la diferencia es invisible**, así que esto sólo lo caza el job de PostgreSQL del CI. Ver `BITACORA_TECNICA.md` § 28 |
| 39 | **SQLite pierde el CHECK de un `enum` si la tabla pasó por un `->change()`** | Laravel implementa `change()` en SQLite **reconstruyendo la tabla**, y el CHECK inline del enum no sobrevive a la reconstrucción (en PostgreSQL sí, porque ahí sólo se emite un `ALTER COLUMN`). Efecto: **un valor de enum inventado pasa en local y sólo revienta en el CI real**. Pasó con `customer_installations.status`, cuyo vocabulario es español (`pendiente`/`completada`/`cancelada`) y un test insertaba `'pending'` — 5 fallos en el job de PostgreSQL, verde en sqlite. Al escribir una prueba, toma el valor del enum de la migración, no de memoria |
---

## 12. Solución de problemas

| Problema | Diagnóstico | Solución |
|---|---|---|
| **No se generan facturas** | `php artisan billing:verify-monthly` | Si reporta `no_show`, el planificador no corre. Ver §7 |
| **Un router concreto no factura** | Revisa `billing_router_id` y `create_invoice` | Asigna configuración de facturación al router |
| **Un cliente no recibe factura** | ¿`user_services.status = 'active'`? ¿`exclude_from_billing`? ¿plan de cortesía? ¿lápida `suppressed` en `billing_action_logs`? | Según el caso |
| **Un cliente recibe la factura pero no el aviso por correo/WhatsApp** | ¿`customer_profile.notify_invoice = false`? | Es intencional: la factura y la mora/corte siguen igual, sólo el aviso está silenciado. Ver `notifyInvoiceCreated()` en `BillingService` y `sendDueReminders()` en `PaymentReminderService` |
| **"El cliente abonó y no se le cortó"** | Por diseño: un abono parcial cierra la factura (`paid`, `carried_out > 0`) y saca al cliente de la mora | El faltante se cobra en la próxima factura. Ver `invoice_carryovers` (`status = pending`) |
| **Una factura salió más cara de lo esperado** | ¿Tiene un ítem `carryover`? `invoices.carried_in > 0` | Es el arrastre de un abono parcial anterior; `invoice_carryovers.from_invoice_id` dice de cuál |
| **El arrastre no volvió al anular el pago** | Ya estaba `applied`: otra factura lo cobró | Correcto: devolverlo lo cobraría dos veces. Si hay que deshacerlo, borrar la factura que lo cobró lo devuelve a `pending` |
| **`SQLSTATE 22P02`** | Comparación de booleano con cadena | Corrige la consulta a `where('status', true)` |
| **Falso `403 No role assigned`** | Desajuste de `tenant_id` entre usuario y rol | Carga el rol con `withoutGlobalScope('tenant')` |
| **Pestaña ausente para un admin** | Permiso nuevo sin backfill | Migración de backfill, o marcar en `/roles` y re-loguear |
| **`<connection failed> <ip>:22`** | IP obsoleta o puerto SSH distinto | `RouterEndpointResolver` + `router.puerto_ssh`. El CORE necesita `forwarding-enabled=both`. Comprueba que el puerto llegue **hasta el `ssh-exec`**: si un método intermedio no lo recibe en su firma, el `port=` se pierde y todo cae al 22 |
| **Aprovisionamiento con éxito pero sin efecto** | `ssh-exec` con `exit-code ≠ 0` | Revisar centinelas `ISP_BEGIN`/`ISP_FAIL`/`ISP_END` en el log |
| **La VPN figura activa y aun así todo lo que el CORE inicia al router se cuelga** | **Túnel duplicado**: dos secrets discando desde la misma IP pública (`caller-id`). Se reciclan entre sí y el router queda con dos direcciones de overlay | `php artisan vpn:verify-tunnels` → estado `DUPLICADO`. Deja UN solo túnel por pública: quita el `l2tp-client` al equipo que sobre y borra su secret del CORE |
| **"Respuesta del router: `ISP_BEGIN`"** al leer la WAN | No es una respuesta del router: es media respuesta del CORE. El `ssh-exec` seguía esperando al cliente cuando venció el tiempo de espera, y `phpseclib` devuelve lo poco que llegó sin lanzar excepción | Ya se reporta como *timeout* explícito. Diagnostica con `php artisan router:diagnose-wan <id>`; si el enlace es lento pero sano, sube `MIKROTIK_CORE_SSH_EXEC_TIMEOUT` (segundos, por defecto 25) |
| **"Credenciales incorrectas en el router cliente: `no_done`"** | Tampoco es la contraseña: el router no respondió **nada** al login API. El socket abrió pero al otro lado no había una API MikroTik escuchando | Verifica en el cliente `/ip service print` (servicio `api` habilitado y su *available from*). Hoy este caso se reporta como tiempo de espera agotado, no como credenciales |
| **La modal de WAN falla y no deja escribir la interfaz** | Resuelto: el bloque de error ya no oculta la entrada manual | Escribe el nombre a mano, o pulsa *Reintentar lectura* |
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

---

## Auditar un cambio nuevo

Todo lo que mueve plata debe quedar registrado. Hay dos mecanismos y **no son intercambiables**.

### Un campo nuevo que mueve plata

Agrégalo a la lista blanca de `MoneyAuditObserver::WATCHED`, con su etiqueta en español:

```php
Plan::class => [
    'cost_product' => 'precio',
    'mi_campo'     => 'etiqueta legible',
],
```

Eso basta: el observer atrapa el cambio venga de donde venga —panel, API, carga masiva o
consola—. **No instrumentes el controlador.** Es la lección del episodio del precio mal cargado:
un cambio equivalente entró por `CustomersUpdateImport`, que no pasa por ningún controlador de
planes, y por eso quedó sin registro.

Si el valor no se lee bien en crudo (un `service_id` no le dice nada a nadie), agrégale
traducción en `MoneyAuditObserver::readable()`.

**Un modelo nuevo** se registra además en `AppServiceProvider::registerMoneyAudit()`.

**Cuidado con el volumen.** La lista blanca es corta a propósito. `Invoice::balance_due` queda
fuera porque cambia en cada pago y ya deja asiento en `payment_allocations`; `credit_balance`
queda fuera porque lo cubre el libro con más detalle. Auditarlo todo hace la bitácora ilegible.

### Tocar el saldo a favor

**Nunca escribas `customer_profile.credit_balance` directamente.** Es una caché; la verdad son los
movimientos de `customer_credits`, y escribir el escalar a pelo rompe el invariante
`SUM(amount) == credit_balance`.

Usa `CustomerCredit::earn()`, `applyToInvoice()`, `adjust()` o `reverseForPayment()` según el caso.
Todos sincronizan la caché por su cuenta. Si después necesitas el saldo actualizado en un modelo
que ya tenías cargado, llama `$profile->refresh()`: el libro trabaja sobre su propia instancia y la
tuya queda vieja.

Cuando escribas un test que toque saldo, afirma el invariante. Es una línea y es lo que detecta el
tipo de error que hace desaparecer dinero:

```php
$this->assertEqualsWithDelta(
    (float) CustomerProfile::where('user_id', $id)->value('credit_balance'),
    CustomerCredit::ledgerBalanceFor($id),
    0.01
);
```

### Marcar el origen de un proceso

Un proceso que escribe en nombre de otro —una importación, un comando— debe marcar sus escrituras
para que la bitácora las distinga:

```php
AuditContext::as(AuditContext::SOURCE_IMPORT, fn () => Excel::import($import, $file));
```

Sin eso, una carga masiva lanzada desde el panel queda registrada como `web` y se vuelve
indistinguible de un cambio hecho a mano — que es exactamente la ambigüedad que costó la auditoría
manual del episodio 56.000 → 60.000.

### Dos reglas que la bitácora aprendió a golpes

**La bitácora nunca tumba la operación que audita.** `MoneyAuditObserver::write()` traga cualquier
excepción y la manda a `Log::error`. Perder la trazabilidad de un pago es malo; perder el pago es
peor. En PostgreSQL además no es solo el registro: una excepción dentro de la transacción la deja
abortada y **todo lo que venga detrás revienta en cadena** con «current transaction is aborted».
SQLite no tiene ese estado, así que un observer frágil pasa los tests en local y tumba producción.

**No todo lo que se autentica es un `User`.** La API pública autentica un `ApiClient`, cuyo id vive
en otra tabla. `audit_logs.user_id` y `customer_credits.created_by` tienen clave foránea contra
`users`, así que `AuditContext::actorId()` comprueba el tipo antes de estampar nada. **SQLite no
aplica claves foráneas por defecto**: este fallo es invisible en la suite rápida y solo aparece en
el job de PostgreSQL. Si tocas algo que guarde un id de actor, pásalo por ahí.
