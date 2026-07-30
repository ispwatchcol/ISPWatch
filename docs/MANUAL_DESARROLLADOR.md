# MANUAL DE DESARROLLADOR — ISPWatch

> Todo lo necesario para instalar, ejecutar, probar, desplegar y extender el sistema.
> Complementa a [`ARQUITECTURA.md`](ARQUITECTURA.md) (diseño) y
> [`BITACORA_TECNICA.md`](BITACORA_TECNICA.md) (inventario de código).

**Última actualización:** 2026-07-30

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
cola `sync`, correo `array`. Definido en `phpunit.xml` y reforzado en `.env.testing`.

### Cobertura actual (40 archivos)

| Suite | Archivos |
|---|---|
| `Feature/Billing` | `AutoCutoffTest`, `AutoReconnectOnPaymentTest`, `BillingEventTimeTest`, `BillingModuleTest`, `DeleteInvoiceTest`, `FirstInvoiceFreeMonthsTest`, `FirstInvoiceProrationTest`, `MarkInvoiceUnpaidTest`, `PaymentReminderTest`, `ReconcileSuspensionsTest`, `RouterMonthlyBillingTest`, `VerifyAutomaticCutsTest`, `VerifyMonthlyBillingTest` |
| `Feature/Documents` | `BillingPdfDownloadTest`, `CustomerContractSignTest`, `DocumentTemplateControllerTest`, `InstallationSheetSignTest` |
| `Feature/Auth` | Autenticación, verificación de correo, contraseñas, registro |
| `Feature/Router` | `RouterOutageTest` |
| `Feature/Inventory` | `InventoryImportTest` |
| `Feature` (raíz) | `BillingTest`, `StaffDeletionTest`, `TemplateRendererFallbackTest`, `TenantBrandingConfigTest`, `TenantLogoUploadTest` |
| `Unit` | `CoreSshExecTest`, `FirewallRulesManagerTest`, `InterfaceReaderTest`, `NormalizesRouterCommentTest` |

**Zona sin cobertura relevante:** clientes, prospectos, sectoriales, soporte, gastos,
inventario (más allá de la importación) y roles/permisos.

### Cuidado con SQLite

Diferencias que los tests **no** detectan y sí rompen en producción:

| Aspecto | PostgreSQL | SQLite |
|---|---|---|
| Comparar `boolean` con `'active'` | `SQLSTATE 22P02` | Coincide con cero filas, silenciosamente |
| `LIKE` | **Sensible** a mayúsculas | Insensible |
| Índices parciales | Soportados | No |

Cuando el filtrado sea insensible a mayúsculas, usa `ilike` seleccionado por driver.

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

### ⚠️ El planificador no está en la definición de la app

La especificación **no incluye ningún componente que ejecute `php artisan schedule:run`**.
Sin él, **nada del ciclo automático ocurre**: no se factura, no se recuerda, no se corta, no
se recolecta tráfico. Este es un fallo que ya se materializó en producción.

Opciones para resolverlo:

```bash
# A) Worker adicional en App Platform
php artisan schedule:work

# B) Cron en un droplet o servidor con acceso al proyecto
* * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

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

---

## 11. Trampas conocidas

| # | Trampa | Detalle |
|---|---|---|
| 1 | **`.env` local apunta a producción** | `DB_SCHEMA=public` = datos reales. Verifica antes de cualquier escritura |
| 2 | **`migrate` a secas** | Deja los esquemas desincronizados. Usa `migrate:both` |
| 3 | **`customer_profile.status` es booleano** | Compararlo con `'active'` lanza `22P02` en PostgreSQL y coincide con cero filas en SQLite |
| 4 | **`LIKE` sensible a mayúsculas** | Los tests SQLite no lo detectan. Usa `ilike` por driver |
| 5 | **Scope de tenant sobre `Role`** | Si `users.tenant_id` ≠ `role.tenant_id`, el rol se anula y sale un falso 403. Carga el rol con `withoutGlobalScope('tenant')` |
| 6 | **Permiso nuevo sin backfill** | El frontend lee `role.permissions` de la base, no `getPermissionsByRole()` |
| 7 | **Columnas `*_encrypted` de `router`** | Contienen **texto plano**. No añadas el cast `encrypted` sin una migración de re-guardado |
| 8 | **La IP del router deriva** | Usa `RouterEndpointResolver`, no `router->ip` directamente |
| 9 | **SSH del cliente puede no estar en el 22** | Usa `Router::sshPort()` y pasa `port=` |
| 10 | **`/ip hotspot user profile` no acepta `comment`** | Añadirlo rompe el comando entero |
| 11 | **Subida múltiple de fotos** | `413`/`504` sin JSON. Comprimir y enviar de una en una |
| 12 | **Push PPPoE síncrono** | 17–34 s por cliente; el gateway corta. Usa el camino asíncrono |
| 13 | **`cut_type` vacío en producción** | `OverdueSuspensionService` compara por el **nombre literal** `'Corte Automático'`. Sin filas en la tabla, nadie se corta |
| 14 | **Migración pendiente** | `2026_07_30_000000_add_first_invoice_free_months_and_plan_policy` no está aplicada en `public` |

---

## 12. Solución de problemas

| Problema | Diagnóstico | Solución |
|---|---|---|
| **No se generan facturas** | `php artisan billing:verify-monthly` | Si reporta `no_show`, el planificador no corre. Ver §7 |
| **Un router concreto no factura** | Revisa `billing_router_id` y `create_invoice` | Asigna configuración de facturación al router |
| **Un cliente no recibe factura** | ¿`user_services.status = 'active'`? ¿`exclude_from_billing`? ¿plan de cortesía? ¿lápida `suppressed` en `billing_action_logs`? | Según el caso |
| **`SQLSTATE 22P02`** | Comparación de booleano con cadena | Corrige la consulta a `where('status', true)` |
| **Falso `403 No role assigned`** | Desajuste de `tenant_id` entre usuario y rol | Carga el rol con `withoutGlobalScope('tenant')` |
| **Pestaña ausente para un admin** | Permiso nuevo sin backfill | Migración de backfill, o marcar en `/roles` y re-loguear |
| **`<connection failed> <ip>:22`** | IP obsoleta o puerto SSH distinto | `RouterEndpointResolver` + `router.puerto_ssh`. El CORE necesita `forwarding-enabled=both` |
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
