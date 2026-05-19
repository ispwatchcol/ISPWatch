# 🚀 ISPWatch

**La Solución Inteligente para la Gestión de tu ISP**

ISPWatch es una plataforma **multi-tenant** completa para optimizar, automatizar y escalar la administración de Proveedores de Servicios de Internet (ISP): gestiona clientes, monitorea redes MikroTik, factura automáticamente y suspende/reactiva servicios por mora, todo en un solo lugar.

![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4)
![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20)
![Vue](https://img.shields.io/badge/Vue-3-42b883)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-Supabase-336791)
![License](https://img.shields.io/badge/License-MIT-green)

---

## 📑 Tabla de Contenidos

- [Características](#-características)
- [Tecnologías](#-tecnologías)
- [Arquitectura](#-arquitectura)
- [Requisitos Previos](#-requisitos-previos)
- [Instalación](#-instalación)
- [Configuración (.env)](#-configuración-env)
- [Ejecutar en Desarrollo](#️-ejecutar-en-desarrollo)
- [Build para Producción](#️-build-para-producción)
- [Comandos Artisan](#-comandos-artisan)
- [Tareas Programadas](#-tareas-programadas)
- [Módulo de Facturación](#-módulo-de-facturación)
- [Estructura del Proyecto](#-estructura-del-proyecto)
- [Testing](#-testing)
- [Seguridad](#-seguridad)
- [Solución de Problemas](#-solución-de-problemas)
- [Licencia](#-licencia)

---

## ✨ Características

### 👥 Gestión de Clientes
- Alta por formulario o **importación masiva desde Excel** (clientes, planes, routers y sectoriales en un solo archivo)
- Asignación automática de IP y aprovisionamiento en MikroTik (Simple Queue / secret PPPoE)
- Perfiles con cédula, ubicación geográfica (mapa Leaflet) y datos de contacto
- Límite de clientes por plan del tenant con aviso de upgrade

### 💰 Facturación Automática
- Generación mensual de facturas para clientes con servicio activo (idempotente)
- Numeración de facturas secuencial y segura por tenant (concurrency-safe)
- Registro de pagos con asignación automática a facturas (más antigua primero)
- **Planes de cortesía**: el estado `gratis` excluye al cliente de la facturación automática
- Facturas en PDF, recordatorios de pago por correo / WhatsApp

### ✂️ Corte y Reactivación Automática
- Corte automático por router según día/hora y cantidad de facturas vencidas
- Modos *Corte Automático* y *Corte Manual* (cola de pendientes)
- Suspensión/reactivación vía MikroTik con registro de auditoría

### 📡 Monitoreo e Integración MikroTik
- Conexión a RouterBoards por **API** y **SSH** (directa o vía túnel CORE)
- Generación de scripts VPN (L2TP/IPSec), verificación de conexión y de interfaz WAN
- Instalación de reglas de bloqueo y políticas en el router

### 🎫 Soporte y Administración
- Sistema de tickets con categorías, mensajes y adjuntos
- Roles personalizados con permisos granulares y auditoría de acciones
- Autenticación con Laravel Sanctum y verificación de correo

---

## 🛠 Tecnologías

| Backend | Frontend | Datos / Infra |
|---------|----------|---------------|
| PHP 8.2+ | Vue 3 + Vue Router | PostgreSQL (Supabase) |
| Laravel 12 | Vite 6 | Cola/caché en base de datos |
| Laravel Sanctum | Pinia (estado) | DomPDF (facturas PDF) |
| Livewire 3 + Volt | TailwindCSS 3 | Maatwebsite Excel (import/export) |
| phpseclib (SSH) | Leaflet (mapas) | doctrine/dbal |

---

## 🏗 Arquitectura

```
Vue 3 SPA  ──HTTP/Sanctum──▶  API Laravel  ──▶  PostgreSQL (Supabase)
                                   │
                                   ├─▶ Services/   Lógica de negocio
                                   │     • BillingService          (facturación)
                                   │     • OverdueSuspensionService (corte por mora)
                                   │     • RouterProvisioningService(alta en router)
                                   │     • MikroTikSshService / RouterApiService
                                   │     • VpnService / WhatsAppService
                                   │
                                   └─▶ Scheduler   Tareas programadas (cron)
```

- **Multi-tenant**: el trait `BelongsToTenant` filtra automáticamente por `tenant_id` del usuario autenticado (mitigación OWASP A01). En contextos de consola/jobs sin auth, no aplica filtro (procesa todos los tenants).
- **Facturación dirigida por `user_services`**: el job mensual solo factura servicios con `status = 'active'`. Los planes de cortesía usan `status = 'gratis'`.

---

## 📋 Requisitos Previos

- **PHP** >= 8.2 (extensiones: `pdo_pgsql`, `mbstring`, `openssl`, `zip`, `gd`)
- **Composer** >= 2.0
- **Node.js** >= 18.x y **NPM** >= 9.x
- **PostgreSQL** 14+ (o una cuenta en Supabase)
- *(Opcional)* `pg_dump`/`pg_restore` para respaldos y squash de esquema

---

## 🚀 Instalación

```bash
# 1. Clonar
git clone https://github.com/tuusuario/ISPWatch.git
cd ISPWatch

# 2. Dependencias PHP
composer install

# 3. Dependencias Node
npm install

# 4. Variables de entorno
cp .env.example .env

# 5. Clave de aplicación
php artisan key:generate

# 6. Migraciones
php artisan migrate

# 7. Datos iniciales
php artisan db:seed
```

El seeder crea, en orden: roles → tenant demo → tipos de plan → tipos de corte → planes de servicio → routers → usuarios base → clientes de ejemplo.

---

## 🔧 Configuración (.env)

```env
# Aplicación
APP_NAME=ISPWatch
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# Base de Datos (PostgreSQL / Supabase)
DB_CONNECTION=pgsql
DB_HOST=tu-host.pooler.supabase.com
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=tu_usuario
DB_PASSWORD=tu_contraseña

# Supabase (frontend)
VITE_SUPABASE_URL=https://tu-proyecto.supabase.co
VITE_SUPABASE_ANON_KEY=tu_anon_key

# Sanctum (agrega el host del dev server de Vite)
SANCTUM_STATEFUL_DOMAINS=localhost,localhost:5173,127.0.0.1,127.0.0.1:5173

# MikroTik CORE (servidor central / túnel VPN)
MIKROTIK_CORE_API_HOST=
MIKROTIK_CORE_API_PORT=8728
MIKROTIK_CORE_API_USER=
MIKROTIK_CORE_API_PASS=
MIKROTIK_USE_CORE_TUNNEL=false   # producción debe ir en true

# Email (Brevo / SMTP)
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
```

> ⚠️ **Nunca** subas el `.env` ni claves SSH al repositorio. El `.gitignore` ya excluye `.env`, `*.log` y claves (`*.pem`, `id_ed25519*`, etc.).

---

## ▶️ Ejecutar en Desarrollo

```bash
# Opción 1: backend + Vite en un comando
npm run dev

# Opción 2: full-stack con cola y logs (recomendado para depurar)
composer run dev      # serve + queue:listen + pail + vite

# Opción 3: terminales separadas
php artisan serve
npm run vite
```

Aplicación en **http://localhost:8000**.

---

## 🏗️ Build para Producción

```bash
npm run build         # assets optimizados en public/build/
php artisan config:cache
php artisan route:cache
```

Ver [`DEPLOYMENT_INSTRUCTIONS.md`](DEPLOYMENT_INSTRUCTIONS.md) para el despliegue completo.

---

## 🧰 Comandos Artisan

| Comando | Descripción |
|---------|-------------|
| `php artisan billing:generate-monthly` | Genera las facturas del **mes actual** para clientes activos (idempotente). |
| `php artisan billing:void-courtesy {period?}` | Anula las facturas de clientes con plan de cortesía para un período `YYYY-MM` (por defecto, mes actual). |
| `php artisan billing:auto-cut` | Procesa cortes automáticos por mora según la config de cada router. |
| `php artisan db:seed` | Carga los datos iniciales. |
| `php artisan migrate --path=...` | Aplica **una** migración específica (recomendado: la BD aplica migraciones de forma selectiva). |

> ℹ️ `billing:generate-monthly` está definido como *closure* en `routes/console.php` y **no acepta argumentos**: siempre factura el mes actual. Para un período específico usa `tinker`:
> `app(\App\Services\BillingService::class)->generateMonthlyInvoices('2026-04')`.

---

## ⏰ Tareas Programadas

Definidas en [`routes/console.php`](routes/console.php) (requieren `php artisan schedule:work` o un cron a `schedule:run`):

| Frecuencia | Tarea |
|------------|-------|
| Día 1 de cada mes, 00:00 | `billing:generate-monthly` |
| Cada hora | `billing:auto-cut` (procesa routers cuyo horario de corte ya llegó) |

---

## 💸 Módulo de Facturación

- **Origen de datos**: la facturación se basa en la tabla `user_services`, no en `customer_profile.service_id`. Cada cliente con servicio debe tener una fila en `user_services`.
- **Estados** (`user_services.status`): `active` (se factura), `gratis` (plan de cortesía, **no** se factura), `suspended`, `cancelled`, `expired`.
- **Cortesía**: marca el plan con `is_courtesy = true`. Al importar/crear/editar clientes, el servicio queda en `gratis` automáticamente (`UserService::syncForCustomer`).
- **Idempotencia**: regenerar el mismo período no duplica facturas (se valida por `tenant_id + customer_id + período`).
- **Mora y corte**: facturas vencidas con saldo > 0 cuentan para el corte automático; las anuladas (`void`) no.

---

## 📁 Estructura del Proyecto

```
ISPWatch/
├── app/
│   ├── Console/Commands/   # Comandos Artisan (billing, auto-cut, etc.)
│   ├── Http/Controllers/   # API: Customer, Billing, Router, Plan, Auth…
│   ├── Imports/Sheets/     # Importación Excel (Clientes, Planes, Routers)
│   ├── Models/             # Eloquent (User, Plan, Invoice, UserService…)
│   ├── Services/           # Lógica de negocio (Billing, MikroTik, VPN…)
│   └── Traits/             # BelongsToTenant, FixesSequences
├── config/                 # Configuración Laravel
├── database/
│   ├── migrations/         # Migraciones (idempotentes, con guardas hasColumn)
│   ├── seeders/            # Datos iniciales
│   └── factories/          # Factories para tests
├── resources/
│   ├── js/{components,pages}/  # SPA Vue 3
│   └── views/              # Blade (PDF de facturas, emails)
├── routes/
│   ├── api.php             # API REST (auth Sanctum)
│   ├── web.php             # Rutas web / portal de pago
│   └── console.php         # Comandos closure + scheduler
└── tests/                  # PHPUnit (Feature/Unit)
```

---

## 🧪 Testing

```bash
php artisan test                       # toda la suite (SQLite en memoria)
composer run test                      # limpia config y corre la suite
php artisan test tests/Feature/Billing # solo facturación
```

Los tests usan `DB_CONNECTION=sqlite` (`:memory:`), aislado de la BD real.

---

## 🔐 Seguridad

- Autenticación con **Laravel Sanctum** (SPA) y verificación de email.
- Aislamiento **multi-tenant** en cada modelo vía `BelongsToTenant` (OWASP A01).
- Credenciales de router cifradas en BD; claves SSH excluidas del repo.
- Auditoría de acciones sensibles (`audit_logs`, `suspension_action_logs`).
- Revisión OWASP documentada en [`OWASP_SECURITY_AUDIT.md`](OWASP_SECURITY_AUDIT.md) y [`SECURITY_IMPLEMENTATION_SUMMARY.md`](SECURITY_IMPLEMENTATION_SUMMARY.md).

---

## 🩹 Solución de Problemas

| Problema | Solución |
|----------|----------|
| `billing:generate-monthly` dice *"No arguments expected"* | El comando no acepta período; usa `tinker` con `generateMonthlyInvoices('YYYY-MM')`. |
| Migración pendiente que no quieres aplicar a producción | Aplica solo la tuya: `php artisan migrate --path=database/migrations/<archivo>.php`. |
| Cliente no recibe factura | Verifica que tenga fila en `user_services` con `status = 'active'` (los creados antes de la corrección manual podrían no tenerla). |
| Error CORS/CSRF con Vite | Agrega el host de Vite a `SANCTUM_STATEFUL_DOMAINS`. |
| Conexión MikroTik falla en local | Revisa `MIKROTIK_USE_CORE_TUNNEL` y credenciales API/SSH del router. |

---

## 📄 Licencia

Proyecto bajo Licencia **MIT**.
