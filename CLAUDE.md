# Instrucciones del proyecto

> **Precedencia.** Este archivo y `/docs` mandan sobre las guías genéricas de Laravel Boost que
> aparecen al final (bloque `<laravel-boost-guidelines>`). En particular: aquí **actualizar
> `/docs` es obligatorio** al cerrar un cambio (Boost dice lo contrario), y las convenciones del
> código existente —comentarios en español, estructura actual— prevalecen sobre las de Boost.
> Además: las herramientas `database-query` y `tinker` de Boost están **desactivadas a propósito**
> (`config/boost.php`: el `.env` local ve los datos de producción); usa `database-schema` para la
> estructura. Y la "PHP 8.4" que cita Boost es la de esta máquina: el CI corre en **PHP 8.2**, así
> que nada de sintaxis exclusiva de 8.3+.

## Contexto rápido

ISPWatch es un SaaS **multi-tenant** para proveedores de internet (ISP) en Colombia: clientes,
facturación mensual y cartera, corte y reconexión automáticos en routers **MikroTik**,
instalaciones, inventario, tickets de soporte y una API para integradores (`/api/v1/partner`).

- **Backend:** Laravel 12 (monolito), PHP ≥ 8.2, Sanctum (SPA por cookie + tokens).
- **Frontend:** SPA Vue 3 + Pinia + Vite + Tailwind 3 en `resources/js` (no Livewire, no Inertia).
- **Datos:** PostgreSQL en Supabase con PostGIS. Esquema **`public` = PRODUCCIÓN**,
  **`ispwatch_dev` = desarrollo**; los dos viven en la **misma** base.
- **Infra:** DigitalOcean App Platform (cada push a `main` despliega y migra), S3, Brevo, WhatsApp.

### Dónde va cada cosa

| Qué | Dónde |
|---|---|
| Endpoint | `routes/api.php` + controlador + `FormRequest` |
| Regla de negocio | `app/Services` (facturación: `BillingService`, ~3.800 líneas) |
| Regla de facturación pura | `app/Billing` |
| Vocabularios cerrados / helpers de dominio | `app/Support` (`TicketWorkflow`, `ReconnectionOutcome`, `RouterOsVersion`…) |
| Comando RouterOS | `app/Services/MikroTik/<Recurso>Manager` (los controladores **no** lo importan: `MikroTikBoundaryTest`) |
| Permiso | `app/Constants/Permissions.php` **+ migración de backfill + caso en `ApiAuthorizationTest`** |
| Esquema | `database/migrations` (aplicar con `migrate:both`) |
| Tareas programadas | `routes/console.php` |
| Página / ruta del SPA | `resources/js/pages` + `resources/js/router/index.js` con `meta.permission` |
| Llamada HTTP del SPA | `resources/js/services/api/<dominio>.js` |

## Reglas que no se ven leyendo el código

1. **Tenant:** nunca se deriva del request. Sale de `auth()->user()->tenant_id` o del trait
   `BelongsToTenant`. Las FK de entrada se validan con `Rule::exists(...)->where('tenant_id', ...)`.
   Un recurso de otro tenant se responde como **inexistente** (404), no prohibido.
2. **Base de datos:** el `.env` local apunta a Supabase. **Nunca `php artisan migrate` a secas**:
   siempre `php artisan migrate:both`. Nunca seeders sobre `public`. No escribas datos con
   `tinker` sin que el usuario lo pida. `ProductionDatabaseGuard` frena comandos de consola contra
   `public`; no lo saltes con `ISPWATCH_ALLOW_PRODUCTION_DB`.
3. **Migraciones:** portables a SQLite (lo exclusivo de PostgreSQL va tras
   `DB::getDriverName() === 'pgsql'`) e idempotentes (`Schema::hasColumn/hasTable`).
4. **Tests:** `php artisan test` corre en SQLite `:memory:`; el CI repite en PostgreSQL 16 +
   PostGIS. SQLite **no** detecta: CHECK de enums, `LIKE` sensible a mayúsculas, comparar booleano
   con texto, umbrales `float` como binding en SQL crudo. Búsquedas de texto: `whereLike`.
5. **Dinero:** no recalcules `balance_due` como `total − asignado`. Invariantes:
   `balance_due == total − asignado − saldo_aplicado − arrastrado` y
   `recibido == asignado + ganado`. Verifica con `php artisan billing:audit-books --tenant=N`.
6. **Pago ≠ reconexión:** el pago no se revierte por un fallo de router; el desenlace se nombra con
   `ReconnectionOutcome`, nunca se supone éxito.
7. **Permisos:** `permission:a,b` es OR (solo para lectura). `role_id == 1` es superadmin. Todo
   permiso nuevo necesita backfill y caso de autorización.
8. **Tickets:** el estado no se escribe, se transiciona (`TicketWorkflow` / `transicionar()`).
9. **Formato:** el código **no** sigue Pint. No reformatees archivos enteros; toca solo lo tuyo.

Detalle y el porqué de cada una: `docs/MANUAL_DESARROLLADOR.md` § 9 (convenciones) y § 11
(trampas conocidas, numeradas).

## Verificar antes de afirmar

`/docs` es grande (~1,8 MB) y **no** siempre está al día; Jira tampoco. Antes de decir que algo
existe, está hecho o está pendiente, **compruébalo en el código y en `git log`**
(`git log --oneline --grep KAN-N`). Si la documentación y el código discrepan, manda el código,
y señala la discrepancia.

| Pregunta | Dónde |
|---|---|
| Cómo está armado un módulo | `docs/ARQUITECTURA.md` (índice al inicio; § 8 MikroTik, § 9 multi-tenancy, § 10 planificador) |
| Tablas, columnas, CHECK, FK | `docs/BASE_DATOS.md` (o la herramienta `database-schema` de Boost) |
| Contrato de un endpoint | `docs/API_REFERENCE.md` (§ 21 mapa de permisos) y `docs/openapi/` |
| Cómo extender / trampas | `docs/MANUAL_DESARROLLADOR.md` |
| Por qué se decidió algo, cuándo | `docs/BITACORA_TECNICA.md` (cronológica; buscar con grep, no leer entera) |
| Deuda conocida | `docs/MEJORAS_RECOMENDADAS.md` |
| Cliente CNO | `docs/cliente/CNO/`: `V1_1/` es el paquete original del cliente (**no se modifica**); `SEGUIMIENTO_MODULO_TICKETS.md` es nuestro control interno |

## Jira

- Sitio `ispwatch.atlassian.net`, proyecto **KAN** (conector MCP de Atlassian).
  Estados: To Do → In Progress → In Review → Done.
- **El tablero va atrasado:** muchos issues en *In Review* ya están mergeados. Antes de trabajar
  un issue, léelo completo (descripción y comentarios) y búscalo en `git log`.
- Flujo al trabajar un issue: rama `tipo/kan-N-descripcion` desde `origin/develop` → commits con
  `KAN-N` → PR a `develop` → comentario en el issue con el enlace al PR → pasar a **In Review**.
  **Done** lo marca una persona tras validar.
- La integración GitHub for Jira **no** está conectada: los smart commits no mueven nada solos.
- **Pide confirmación antes de:** tareas de consola externa (DigitalOcean, Supabase, secretos,
  rotar credenciales), decisiones de producto o del cliente que el issue no resuelve, y cualquier
  escritura en datos de producción.

## Flujo de ramas (regla permanente, desde el 2026-10-16)

- **`develop` es la rama de integración.** Todo PR de feature o fix sale de `origin/develop` y
  apunta a `develop` (`gh pr create --base develop`). Nunca se abren PR de trabajo contra `main`.
- **`main` es producción**: cada push despliega en App Platform y aplica las migraciones
  (`PRE_DEPLOY migrate --force`). A `main` solo llega un **PR de release** `develop → main`,
  mergeado con *merge commit* (no squash ni rebase, para que `develop` y `main` no diverjan).
- **Hotfix urgente de producción:** rama desde `origin/main` y PR a `main`. Después del merge,
  se abre un PR `main → develop` para que `develop` no lo pierda.
- Antes de hacer push, `git branch -vv`: la rama de trabajo no debe seguir a `origin/main`.
- Detalle y comandos: `docs/MANUAL_DESARROLLADOR.md` § Git.

## Mantenimiento de documentación (regla permanente)

Al finalizar cualquier feature, fix o cambio estructural (nuevo módulo, cambio de arquitectura, nueva tabla, endpoint nuevo, decisión de diseño relevante), **antes de considerar el trabajo terminado**, revisa y actualiza los archivos correspondientes en `/docs`:

- **ARQUITECTURA.md** → si cambia la estructura del sistema, servicios nuevos, o el diseño de algún módulo (ej. el sistema de plantillas de documentos, el pipeline de sanitización).
- **BASE_DATOS.md** → si hay migraciones nuevas, columnas agregadas, relaciones nuevas o cambios de esquema.
- **API_REFERENCE.md** → si hay endpoints nuevos, cambios en request/response, o nuevos headers de contrato (ej. `X-Template-Warnings`).
- **MANUAL_DESARROLLADOR.md** → si cambia algo que afecta cómo un desarrollador nuevo entendería o extendería el código (ej. cómo funciona el sanitizer, cómo se agregan placeholders nuevos).
- **MANUAL_USUARIO.md** → si cambia algo visible para el usuario final (ej. nueva opción en Configuración, nuevo checkbox de "Modo avanzado").
- **BITACORA_TECNICA.md** → registro cronológico de qué se hizo, cuándo, y por qué (decisiones de diseño, bugs encontrados y su causa raíz, deuda técnica aceptada conscientemente).
- **MEJORAS_RECOMENDADAS.md** → cualquier deuda técnica identificada pero no resuelta (ej. la nota sobre placeholders cross-type que se blanquean sin warning).
- **BLOQUEO_MOROSOS_MANUAL.md** → solo si el cambio afecta directamente esa lógica de negocio específica.

No actualices un archivo solo por actualizarlo — si un cambio no afecta esa categoría, no la toques. Pero si terminas cualquier feature sin haber revisado esta lista, considera el trabajo incompleto, no solo el código.

Al final de cada resumen de implementación, incluye una sección corta **"Documentación actualizada"** listando qué archivos de `/docs` se tocaron y por qué, o **"Ninguno aplica"** si el cambio no lo requería.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- This project uses the streamlined Laravel 11+ structure: register middleware, exceptions, and routing in `bootstrap/app.php` and service providers in `bootstrap/providers.php`. There is no `app/Http/Kernel.php` or `app/Console/Kernel.php`, and commands in `app/Console/Commands/` auto-register.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.

- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
