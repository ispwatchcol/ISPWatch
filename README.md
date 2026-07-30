# 🚀 ISPWatch

**La solución inteligente para la gestión de tu ISP**

Plataforma **multi-tenant** para administrar Proveedores de Servicios de Internet: gestiona
clientes, monitorea redes MikroTik, factura automáticamente y suspende o reactiva servicios
por mora — **actuando de verdad sobre los equipos de red**, no sólo sobre la base de datos.

![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4)
![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20)
![Vue](https://img.shields.io/badge/Vue-3-42b883)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-Supabase-336791)
![License](https://img.shields.io/badge/License-MIT-green)

---

## 📚 Documentación

La documentación completa vive en [`docs/`](docs/). Empieza por la que corresponda a tu rol:

| Documento | Para quién | Contenido |
|---|---|---|
| [**MANUAL_USUARIO.md**](docs/MANUAL_USUARIO.md) | Operadores, administrativos, técnicos | Uso diario paso a paso, en lenguaje no técnico |
| [**MANUAL_DESARROLLADOR.md**](docs/MANUAL_DESARROLLADOR.md) | Desarrolladores | Instalación, entorno, pruebas, despliegue, trampas conocidas |
| [**ARQUITECTURA.md**](docs/ARQUITECTURA.md) | Arquitectos, nuevos integrantes | Diseño del sistema, stack, integración MikroTik, flujo de datos |
| [**BITACORA_TECNICA.md**](docs/BITACORA_TECNICA.md) | Mantenimiento | Inventario de código, módulos de negocio, trazabilidad, flujo completo |
| [**API_REFERENCE.md**](docs/API_REFERENCE.md) | Integradores, frontend | Todos los endpoints con parámetros, respuestas y errores |
| [**BASE_DATOS.md**](docs/BASE_DATOS.md) | DBA, backend | Diccionario de datos, ER, índices, restricciones |
| [**MEJORAS_RECOMENDADAS.md**](docs/MEJORAS_RECOMENDADAS.md) | Responsables técnicos | Auditoría con problema, impacto, prioridad y recomendación |
| [**BLOQUEO_MOROSOS_MANUAL.md**](docs/BLOQUEO_MOROSOS_MANUAL.md) | Redes | Configuración del bloqueo en MikroTik |

> ⚠️ **Regla del proyecto:** todo cambio de código debe reflejarse en los manuales
> correspondientes en el mismo PR. Ver [Mantener la documentación](#-mantener-la-documentación).

---

## ✨ Qué hace

### 👥 Clientes
Alta por formulario o **importación masiva desde Excel** (clientes, planes, routers y
sectoriales en un solo archivo). Asignación de IP y aprovisionamiento automático en MikroTik.
Mapa georreferenciado, documentos en S3 con URL firmada, contrato firmado digitalmente.
Límite de clientes por plan del tenant.

### 💰 Facturación
Generación mensual **dirigida por router** (cada equipo tiene su día y hora de facturación),
idempotente y con recuperación ante caídas. Numeración secuencial segura por tenant.
**Política de primera factura** en cascada cliente → plan → router: sin cobro, prorrateado o
mes completo, más meses de cortesía posteriores a la instalación. Facturas en PDF,
recordatorios por correo y WhatsApp, pagos con asignación automática y saldo a favor.

### ✂️ Corte y reactivación
Corte automático por router según día, hora y número de facturas vencidas.
**Reconexión automática al registrar el pago.** Failover con reintentos escalonados,
reconciliación base de datos ⇄ RouterBoard y auditoría de *no-show*.

### 📡 MikroTik
Conexión por API y SSH a través de un router **CORE** central. Cinco métodos de control
excluyentes (Simple Queue, PCQ, HotSpot, PPPoE, DHCP) más aditivos de IP Bindings y amarre
IP-MAC. Generación de scripts VPN L2TP/IPSec, lectura de interfaces, reglas de bloqueo,
historial de tráfico WAN y difusión de falla masiva.

### 🌐 Planta externa
Árbol FTTH completo (OLT → splitter → NAP → mufa) con puertos calculados y vista de topología.

### 🎫 Soporte y administración
Tickets con categorías, mensajes internos, adjuntos y cargos facturables.
Roles personalizados con permisos granulares. Inventario, gastos, plantillas de documentos
editables y centro de ayuda embebido.

---

## 🛠 Stack

| Backend | Frontend | Datos / Infra |
|---|---|---|
| PHP 8.2 · Laravel 12 | Vue 3 · Vue Router 4 | PostgreSQL (Supabase) + PostGIS |
| Laravel Sanctum (SPA) | Pinia 3 · Vite 6 | Cache, cola y sesión en base de datos |
| DomPDF · Maatwebsite Excel | TailwindCSS 3 | Amazon S3 (documentos privados) |
| phpseclib (SSH) · Guzzle | Leaflet · axios | DigitalOcean App Platform |

Detalle completo en [`ARQUITECTURA.md`](docs/ARQUITECTURA.md#2-stack-tecnológico).

---

## 🏗 Arquitectura en una imagen

```
Vue 3 SPA  ──HTTPS/Sanctum──▶  API Laravel  ──▶  PostgreSQL (Supabase)
                                     │
                                     ├─▶ Services/     Lógica de negocio
                                     ├─▶ Billing/      Políticas puras de facturación
                                     ├─▶ MikroTik/     SSH → CORE → ssh-exec → RouterBoard
                                     └─▶ Scheduler     9 tareas programadas
```

- **Multi-tenant:** el trait `BelongsToTenant` filtra por el `tenant_id` **del usuario
  autenticado**, nunca por parámetros del request.
- **Facturación dirigida por `user_services`:** sólo se factura lo que tenga
  `status = 'active'`. Los planes de cortesía usan `status = 'gratis'`.
- **Doble salto SSH:** los RouterBoards no son accesibles desde internet; se alcanzan a
  través del CORE con `/system ssh-exec`.

---

## 📋 Requisitos

- **PHP** ≥ 8.2 — extensiones `pdo_pgsql`, `mbstring`, `openssl`, `zip`, `gd`, `bcmath`
- **Composer** ≥ 2.0
- **Node.js** ≥ 18 y **NPM** ≥ 9
- **PostgreSQL** 14+ con PostGIS (o una cuenta Supabase)
- *(Para la red)* credenciales SSH del router CORE MikroTik

---

## 🚀 Instalación rápida

```bash
git clone https://github.com/ispwatchcol/ISPWatch.git
cd ISPWatch

composer install
npm install

cp .env.example .env
php artisan key:generate

php artisan migrate
php artisan db:seed
```

El seeder crea, en orden: roles → tenant demo → tipos de plan → tipos de corte →
planes de servicio → routers → usuarios base → clientes de ejemplo.

Guía completa en [`MANUAL_DESARROLLADOR.md`](docs/MANUAL_DESARROLLADOR.md#2-instalación).

---

## 🔧 Configuración mínima (.env)

```env
APP_NAME=ISPWatch
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

# Base de datos — ⚠️ DB_SCHEMA=public es PRODUCCIÓN
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=ispwatch
DB_USERNAME=postgres
DB_PASSWORD=
DB_SCHEMA=ispwatch_dev

# Sanctum + CORS (añade el host de Vite en local)
SANCTUM_STATEFUL_DOMAINS=localhost,localhost:5173,127.0.0.1,127.0.0.1:5173
CORS_ALLOWED_ORIGINS=http://localhost:8000,http://localhost:5173

# MikroTik CORE
MIKROTIK_CORE_SSH_HOST=
MIKROTIK_CORE_SSH_PORT=2222
MIKROTIK_CORE_SSH_USER=
MIKROTIK_CORE_SSH_PASS=
MIKROTIK_USE_CORE_TUNNEL=false     # true en producción
PORTAL_IP=192.168.88.252           # portal accesible al cliente cortado

# Correo
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
```

Referencia completa de variables en
[`MANUAL_DESARROLLADOR.md`](docs/MANUAL_DESARROLLADOR.md#3-variables-de-entorno).

> ⚠️ **Nunca** subas `.env`, claves SSH ni secretos al repositorio.
> **No definas `DB_URL`**: puede redirigir silenciosamente la conexión de los tests a la
> base de datos real.

---

## ▶️ Ejecutar en desarrollo

```bash
npm run dev          # backend + Vite
composer run dev     # serve + queue:listen + pail + vite  (recomendado para depurar)
php artisan serve    # sólo backend
```

Aplicación en **http://localhost:8000**.

---

## 🗄️ Migraciones

> **Nunca uses `php artisan migrate` a secas contra Supabase.**

La misma base de datos aloja dos esquemas: `ispwatch_dev` (desarrollo) y `public`
(producción). El comando correcto los cubre ambos:

```bash
php artisan migrate:both
php artisan migrate:both --path=database/migrations/<archivo>.php
php artisan migrate:both --seed     # el seed sólo corre en ispwatch_dev
php artisan db:sync-dev             # copia public → ispwatch_dev
```

---

## 🧰 Comandos Artisan

| Comando | Descripción |
|---|---|
| `billing:generate-monthly {period?}` | Genera facturas del periodo (idempotente) |
| `billing:retry-failed` | Reintenta facturas fallidas (backoff 2h/6h/24h) |
| `billing:verify-monthly` | Audita que la facturación mensual ocurrió |
| `billing:auto-cut` | Corte automático por mora |
| `billing:reconcile-suspensions` | Reconcilia cortes entre base de datos y router |
| `billing:verify-cuts` | Audita que los cortes ocurrieron |
| `billing:send-reminders` | Recordatorios de pago |
| `billing:void-courtesy {period?}` | Anula facturas de planes de cortesía |
| `billing:simulate` | Simulador del ciclo completo |
| `traffic:collect` / `traffic:prune` | Historial de tráfico WAN |
| `migrate:both` | Migraciones en ambos esquemas |
| `db:fix-sequences --all` | Repara secuencias de PostgreSQL |

Lista completa en [`MANUAL_DESARROLLADOR.md`](docs/MANUAL_DESARROLLADOR.md#8-comandos-artisan).

---

## ⏰ Tareas programadas

Definidas en [`routes/console.php`](routes/console.php). Requieren `schedule:run` cada minuto
o un worker con `schedule:work`.

| Frecuencia | Tarea |
|---|---|
| Cada hora | `billing:generate-monthly`, `billing:retry-failed`, `billing:auto-cut`, `billing:reconcile-suspensions`, `billing:send-reminders` |
| Diario 06:00 | `billing:verify-monthly` |
| Diario 07:00 | `billing:verify-cuts` |
| Cada 5 min | `traffic:collect` |
| Diario | `traffic:prune --days=30` |

Los comandos horarios aplican su propio filtro de día y hora por router, así que ejecutarlos
de más es una operación sin efecto. Si el sistema estuvo caído el día de facturación,
**recupera al arrancar**.

> ⚠️ **Verifica que el planificador esté corriendo en producción.** Sin él no se factura ni
> se corta. Comprueba con `php artisan billing:verify-monthly`.

---

## 💸 Notas sobre facturación

- **Dirigida por router:** cada router lleva su configuración (día y hora de emisión,
  vencimiento, recordatorio, corte, número de facturas vencidas toleradas y modo
  anticipado/vencido). Si un cliente no recibe factura, revisa **primero su router**.
- **Origen de datos:** se factura desde `user_services`, no desde `customer_profile.service_id`.
- **Cortesía:** un plan con `is_courtesy = true` deja el servicio en `gratis` y nunca se factura.
- **Exclusión por cliente:** la casilla `exclude_from_billing` saca al cliente de todo el
  ciclo automático.
- **Primera factura:** dos ejes independientes (modo y meses de cortesía) que se resuelven en
  cascada cliente → plan → router. Una única fuente de la fórmula:
  [`app/Billing/FirstInvoicePolicy.php`](app/Billing/FirstInvoicePolicy.php).
- **Factura eliminada = lápida:** no se regenera nunca para ese cliente y ese periodo.

---

## 📁 Estructura del proyecto

```
ISPWatch/
├── .do/                    # Especificación de despliegue DigitalOcean
├── app/
│   ├── Billing/            # Políticas puras de facturación
│   ├── Console/Commands/   # 18 comandos Artisan
│   ├── Http/               # Controladores, middleware, FormRequests
│   ├── Models/             # 43 modelos Eloquent
│   ├── Services/           # Lógica de negocio
│   │   ├── MikroTik/       #   Un manager por recurso RouterOS
│   │   └── Templates/      #   Render y saneado de documentos
│   └── Traits/             # BelongsToTenant, FixesSequences
├── database/               # 129 migraciones, 13 seeders, 3 factories
├── docs/                   # 📚 DOCUMENTACIÓN
├── resources/
│   ├── js/                 # SPA Vue 3 (44 páginas)
│   └── views/              # Blade: shell SPA, PDFs, correos, portal de pago
├── routes/                 # api.php · web.php · console.php · auth.php
└── tests/                  # 40 archivos PHPUnit (SQLite en memoria)
```

Detalle en [`BITACORA_TECNICA.md`](docs/BITACORA_TECNICA.md#2-estructura-de-carpetas).

---

## 🧪 Testing

```bash
php artisan test                       # toda la suite (SQLite en memoria)
composer run test                      # limpia config y ejecuta
php artisan test tests/Feature/Billing # sólo facturación
```

> Los tests corren sobre SQLite. **Las migraciones deben ser portables**: SQL exclusivo de
> PostgreSQL rompe toda la suite si no se protege por driver.

---

## 🔐 Seguridad

- Autenticación **Laravel Sanctum** (SPA por cookie) con verificación de correo obligatoria,
  límite de 5 intentos por minuto y detección de patrones de inyección en el login.
- Aislamiento **multi-tenant** por `BelongsToTenant`, con el `tenant_id` derivado siempre del
  usuario autenticado.
- Permisos granulares por rol, editables por tenant.
- Cabeceras de seguridad globales (CSP, HSTS, X-Frame-Options, COOP).
- Documentos en bucket S3 **privado** con URL firmada de 30 minutos.
- Clave de Google Maps cifrada en reposo y nunca serializada.
- Bitácoras de facturación y de cortes con reintentos y auditoría de *no-show*.

Auditoría histórica en [`OWASP_SECURITY_AUDIT.md`](OWASP_SECURITY_AUDIT.md) y
[`SECURITY_IMPLEMENTATION_SUMMARY.md`](SECURITY_IMPLEMENTATION_SUMMARY.md).

> 🔴 **Hay hallazgos abiertos de prioridad crítica y alta.** Consulta
> [`MEJORAS_RECOMENDADAS.md`](docs/MEJORAS_RECOMENDADAS.md) antes de considerar el sistema
> listo para producción.

---

## 🩹 Solución de problemas

| Problema | Solución |
|---|---|
| No se generan facturas | Ejecuta `billing:verify-monthly`. Si reporta `no_show`, el planificador no está corriendo |
| Un cliente no recibe factura | Revisa: config de facturación del router → `user_services` activo → plan no cortesía → `exclude_from_billing` → lápida `suppressed` |
| Cliente cortado sigue navegando | Aplica las reglas de bloqueo al router y ejecuta `billing:reconcile-suspensions` |
| `<connection failed> <ip>:22` | IP del router obsoleta (la resuelve `RouterEndpointResolver`) o SSH en puerto distinto de 22 (`router.puerto_ssh`) |
| Error CORS/CSRF con Vite | Añade el host de Vite a `SANCTUM_STATEFUL_DOMAINS` y `CORS_ALLOWED_ORIGINS` |
| Falso `403 No role assigned` | Desajuste de `tenant_id` entre usuario y rol; el rol debe cargarse con `withoutGlobalScope('tenant')` |
| Pestaña ausente para un administrador | Permiso nuevo sin backfill en los roles ya sembrados |
| `504` al importar | Los importadores no deben consultar por fila; precarga en bloque |

Más casos en
[`MANUAL_DESARROLLADOR.md`](docs/MANUAL_DESARROLLADOR.md#12-solución-de-problemas).

---

## 📝 Mantener la documentación

**Todo cambio de código debe actualizar la documentación afectada en el mismo PR.**
Guía rápida de qué tocar:

| Si cambias… | Actualiza |
|---|---|
| Un endpoint o su validación | `API_REFERENCE.md` |
| El esquema (migración) | `BASE_DATOS.md` |
| Un flujo de negocio o un servicio | `BITACORA_TECNICA.md` |
| El diseño, el stack o una integración | `ARQUITECTURA.md` |
| Algo visible para el usuario final | `MANUAL_USUARIO.md` |
| Instalación, entorno o despliegue | `MANUAL_DESARROLLADOR.md` |
| Resuelves un hallazgo de auditoría | `MEJORAS_RECOMENDADAS.md` (márcalo como resuelto) |

Actualiza también la fecha de *Última actualización* en la cabecera del documento.

### Contribuir

- **Nunca hagas push directo a `main`**: es producción y despliega automáticamente.
- Trabaja en rama de feature y abre PR. Verifica el upstream con `git branch -vv`.
- Ejecuta `php artisan test` antes de abrir el PR.
- Usa `php artisan migrate:both` para cualquier cambio de esquema.

---

## 📄 Licencia

Proyecto bajo licencia **MIT**.
