# BITÁCORA TÉCNICA — ISPWatch

> Inventario estructural del repositorio, responsabilidad de cada directorio y archivo
> relevante, módulos de negocio y trazabilidad entre componentes.
> Documento pensado para mantenimiento a largo plazo: **si cambias código, actualiza aquí.**

**Última actualización:** 2026-08-05 (vista previa de la hoja de instalación + firma que no se dibujaba) · Rama: `feat/contract-prefix-freeform`

---

## Índice

1. [Resumen ejecutivo](#1-resumen-ejecutivo)
2. [Estructura de carpetas](#2-estructura-de-carpetas)
3. [Backend — archivo por archivo](#3-backend--archivo-por-archivo)
4. [Frontend — archivo por archivo](#4-frontend--archivo-por-archivo)
5. [Relación entre componentes](#5-relación-entre-componentes)
6. [Módulos de negocio](#6-módulos-de-negocio)
7. [Reglas de negocio no evidentes](#7-reglas-de-negocio-no-evidentes)
8. [Flujo completo del sistema](#8-flujo-completo-del-sistema)
9. [Trazabilidad módulo → código → datos](#9-trazabilidad-módulo--código--datos)
10. [Registro de decisiones técnicas](#10-registro-de-decisiones-técnicas)
11. [Auditoría del manual de usuario — 2026-08-03](#11-auditoría-del-manual-de-usuario--2026-08-03)
12. [Consecutivo de contratos — 2026-08-04](#12-consecutivo-de-contratos--2026-08-04)
13. [El prefijo del consecutivo pasa a ser texto libre — 2026-08-05](#13-el-prefijo-del-consecutivo-pasa-a-ser-texto-libre--2026-08-05)
14. [Vista previa de la hoja de instalación y firma que no se dibujaba — 2026-08-05](#14-vista-previa-de-la-hoja-de-instalación-y-firma-que-no-se-dibujaba--2026-08-05)

---

## 1. Resumen ejecutivo

### Propósito

ISPWatch es la plataforma operativa de un **Proveedor de Servicios de Internet**.
Unifica en un solo sistema la gestión comercial (prospectos, clientes, instalaciones),
la gestión financiera (facturación, pagos, gastos) y **el control efectivo de la red**
(aprovisionamiento, suspensión y reconexión sobre routers MikroTik RouterOS).

### Problema que resuelve

Un ISP pequeño o mediano suele operar con tres sistemas desconectados: una hoja de
cálculo para clientes, un software contable para facturar y acceso manual (Winbox) a cada
RouterBoard para dar de alta o cortar servicios. Esa desconexión produce tres fugas
concretas:

1. **Fuga de ingreso:** clientes morosos que siguen navegando porque nadie los cortó a mano.
2. **Fuga de tiempo:** cada alta exige entrar al router y crear cola/secret manualmente.
3. **Fuga de información:** no hay trazabilidad de quién cortó, cuándo, ni por qué.

ISPWatch cierra el ciclo: **la factura vencida dispara el corte real en el equipo, y el
pago registrado dispara la reconexión real**, con bitácora y reintentos automáticos.

### Funcionalidades principales

| Área | Capacidades |
|---|---|
| **Clientes** | Alta individual o masiva por Excel, mapa georreferenciado, documentos en S3, contrato firmado, exclusión de facturación por cliente |
| **Comercial** | Prospectos, agenda de instalaciones, acta de instalación firmada, cobro de instalación |
| **Facturación** | Generación mensual por router, prorrateo y meses de cortesía, numeración segura por tenant, tipos de factura administrables, PDF, recordatorios email/WhatsApp, pagos con asignación automática, saldo a favor y arrastre de abonos parciales |
| **Cobranza** | Corte automático por mora con día y hora configurables, reconexión automática al pagar, reconciliación DB ⇄ router |
| **Red** | Aprovisionamiento por método de control (Queue/PCQ/HotSpot/PPPoE/DHCP), reglas de bloqueo, scripts VPN L2TP/IPSec, lectura de interfaces, historial de tráfico WAN |
| **Planta externa** | Árbol FTTH (OLT → splitter → NAP → mufa), puertos calculados, topología visual |
| **Soporte** | Tickets con categorías, mensajes internos, adjuntos, cargos facturables |
| **Administración** | Multi-tenant, roles con permisos granulares, inventario, gastos, plantillas de documentos, centro de ayuda |

### Tipos de usuario

| Rol | `role_code` | Alcance típico |
|---|---|---|
| **Administrador** | `admin` | Acceso total. `role_id == 1` obtiene bypass en el backend |
| **Staff** | `staff` | Clientes, planes, sectoriales, inventario, soporte, facturación (lectura y registro de pagos) |
| **Técnico** | `technician` | Clientes, instalaciones, activar/desactivar, tráfico |
| **Contabilidad** | `accounting` | Facturas, pagos, gastos, transferencias |
| **Cliente** | `client` | Sin permisos en el panel; es el sujeto de la facturación |

Los roles son **editables por tenant**: `role.permissions` es un array JSON, y se pueden
crear roles personalizados desde `/roles`.

---

## 2. Estructura de carpetas

```
ISPWatch/
├── .do/                     # Especificación de despliegue DigitalOcean App Platform
│   ├── deploy.template.yaml #   web + worker + scheduler. SIN secretos (type: SECRET)
│   └── nginx.conf
├── .github/workflows/       # CI: suite en SQLite y en PostgreSQL+PostGIS
├── app/                     # Código de aplicación (PSR-4: App\)
│   ├── Billing/             #   Políticas de facturación puras (sin IO)
│   ├── Console/Commands/    #   19 comandos Artisan
│   ├── Constants/           #   Catálogo de permisos
│   ├── Exports/             #   Plantillas Excel de descarga
│   ├── Helpers/             #   Traducción de errores de BD
│   ├── Http/
│   │   ├── Controllers/     #   37 controladores de API
│   │   ├── Middleware/      #   Permisos, perfil staff, cabeceras de seguridad
│   │   └── Requests/        #   6 FormRequest con validación
│   ├── Imports/             #   Importadores Excel
│   ├── Jobs/                #   ProvisionCustomerJob
│   ├── Mail/                #   4 mailables
│   ├── Models/              #   43 modelos Eloquent
│   ├── Notifications/       #   Verificación de correo
│   ├── Policies/            #   CustomerInstallationPolicy
│   ├── Providers/           #   AppServiceProvider, SearchMacros, Volt
│   ├── Services/            #   Lógica de negocio
│   │   ├── MikroTik/        #     Managers por recurso RouterOS
│   │   │   └── Concerns/    #     Traits compartidos (SSH, escapado, verificación)
│   │   └── Templates/       #     Render y saneado de plantillas
│   ├── Traits/              #   BelongsToTenant, FixesSequences, InputSanitizer
│   └── View/Components/
├── backup/                  # Respaldos manuales
├── bootstrap/               # app.php: rutas, middleware, manejo de excepciones
├── config/                  # 14 archivos de configuración
├── database/
│   ├── factories/           #   PlanFactory, TenantFactory, UserFactory
│   ├── migrations/          #   134 migraciones
│   └── seeders/             #   13 seeders
├── docs/                    # ESTA DOCUMENTACIÓN
├── public/                  # Punto de entrada + assets compilados
├── resources/
│   ├── css/ · sass/
│   ├── js/                  #   SPA Vue 3
│   └── views/               #   Blade: shell de la SPA, PDFs, correos, portal de pago
├── routes/                  # api.php · web.php · console.php
├── scripts/                 # gen_favicon.py · supabase_lockdown_rls.sql
├── storage/                 # Logs, cache, claves SSH (keys/ está en .gitignore)
└── tests/                   # 27 archivos de prueba (245 tests, 0 fallos)
```

### Responsabilidad de cada directorio

| Directorio | Responsabilidad | Cuándo tocarlo |
|---|---|---|
| `app/Billing` | **Política de facturación pura**, sin acceso a base de datos ni IO. Hoy sólo `FirstInvoicePolicy` | Al cambiar reglas de prorrateo o cortesía |
| `app/Console/Commands` | Tareas ejecutables por cron u operador | Al añadir automatización |
| `app/Http/Controllers` | Traducir HTTP ⇄ dominio. **No debe contener lógica de negocio** | Al añadir endpoints |
| `app/Http/Requests` | Validación declarativa reutilizable | Al cambiar el contrato de entrada |
| `app/Models` | Esquema, relaciones, casts, constantes de dominio | Al cambiar el esquema |
| `app/Services` | Lógica de negocio y orquestación | La mayoría de los cambios funcionales |
| `app/Services/MikroTik` | Todo lo que habla RouterOS. **Un manager por recurso** | Al tocar la integración de red |
| `app/Traits` | Comportamiento transversal (multi-tenancy, secuencias) | Rara vez |
| `database/migrations` | Evolución del esquema. **Siempre `migrate:both`** | Al cambiar el esquema |
| `resources/js/pages` | Una página = una ruta del SPA | UI |
| `resources/js/services/api` | Cliente HTTP por dominio | Al añadir endpoints |
| `resources/views/documents` | Plantillas Blade de PDF y sus *shells* | Al cambiar documentos legales |
| `tests` | PHPUnit sobre **SQLite en memoria**; el CI repite la suite en PostgreSQL | Siempre que cambies lógica |

---

## 3. Backend — archivo por archivo

### 3.1 `app/Billing`

| Archivo | Descripción |
|---|---|
| `FirstInvoicePolicy.php` | **Fuente única** de la política de primera factura. Resuelve la cascada cliente → plan → router para dos ejes independientes (`mode` y `freeMonths`) y calcula el cargo del periodo. Usada por la generación mensual, la auditoría y la vista previa del formulario — **no hay una segunda copia de la fórmula** |

### 3.2 `app/Console/Commands`

| Archivo | Comando | Descripción |
|---|---|---|
| `GenerateMonthlyInvoices.php` | `billing:generate-monthly {period?}` | Dispara `BillingService::generateMonthlyInvoices()` |
| `RetryFailedInvoices.php` | `billing:retry-failed` | Reintenta filas `failed` con `next_retry_at` vencido |
| `VerifyMonthlyBilling.php` | `billing:verify-monthly` | Auditoría de no-show; alerta por log y correo |
| `AutoCutByRouter.php` | `billing:auto-cut` | Corte automático por router |
| `ReconcileSuspensions.php` | `billing:reconcile-suspensions` | Reconcilia DB ⇄ RouterBoard |
| `VerifyAutomaticCuts.php` | `billing:verify-cuts` | Auditoría de no-show de cortes |
| `SendPaymentReminders.php` | `billing:send-reminders` | Recordatorios de pago |
| `ProcessOverdueInvoices.php` | `billing:process-overdue` | Procesamiento manual de morosos |
| `SimulateBillingFlow.php` | `billing:simulate` | Simulador del ciclo completo |
| `VoidCourtesyInvoices.php` | `billing:void-courtesy {period?}` | Anula facturas de planes de cortesía |
| `GenerateTenantInvoicesOneOff.php` | `billing:generate-tenant {tenant} {period} {--dry-run}` | Facturación puntual |
| `CollectTrafficHistory.php` | `traffic:collect` | Muestrea contadores WAN |
| `PruneTrafficHistory.php` | `traffic:prune {--days=30}` | Poda muestras finas |
| `VerifyVpnTunnels.php` | `vpn:verify-tunnels {--stale=15} {--no-mail}` | Salud del túnel por router: `last-handshake` en WireGuard, `/ppp active` en L2TP. Solo lee |
| `MigrateBothSchemas.php` | `migrate:both` | **Aplica migraciones a `ispwatch_dev` y `public`** |
| `SyncDevFromPublic.php` | `db:sync-dev` | Copia producción → desarrollo |
| `FixSequences.php` | `db:fix-sequences` | Repara secuencias PostgreSQL |
| `MigrateDocumentsToS3.php` | `documents:migrate-to-s3 {--dry-run}` | Migra documentos locales a S3 |
| `DiagnoseRouterWan.php` | `router:diagnose-wan` | Diagnóstico de interfaz WAN |
| `SyncRolePermissions.php` | `permissions:sync` | Reconcilia los roles canónicos con el catálogo de permisos (aditivo) |

### 3.3 `app/Http/Controllers`

| Archivo | Líneas | Responsabilidad |
|---|---:|---|
| `CustomerProfileController.php` | 1385 | CRUD de clientes, estadísticas, mapa, IPs usadas, aprovisionamiento (individual, masivo síncrono y asíncrono), suspender/activar, vista previa de primera factura |
| `CustomerInstallationController.php` | 757 | Instalaciones: agenda, acta, fotos, firmas, cobro |
| `RouterController.php` | 650 | CRUD de routers, VPN, interfaces, reglas de bloqueo, pruebas de conexión, tráfico |
| `SupportTicketController.php` | 591 | Tickets, mensajes, estados, cargos, estadísticas |
| `BillingController.php` | 466 | Facturas, pagos, saldo, configs, disparadores del ciclo |
| `PlanController.php` | 327 | CRUD de planes + sincronización de perfiles al router |
| `RegistrationController.php` | 321 | Alta de tenant + administrador con verificación |
| `ImportController.php` | 301 | Plantillas y procesamiento de importaciones masivas |
| `DocumentTemplateController.php` | 281 | Plantillas de factura/contrato/acta con saneado |
| `UserController.php` | 277 | Personal (staff) |
| `AuthController.php` | 248 | Login con rate limiting y detección de inyección; `/auth/me` |
| `TenantController.php` | 242 | Datos de empresa, logo, config de mapas |
| `DashboardController.php` | 235 | Estadísticas del panel |
| `SectorialController.php` | 223 | Elementos de red y topología |
| `PaymentReminderController.php` | 218 | Recordatorios individuales y masivos |
| `CustomerDocumentController.php` | 189 | Documentos en S3, contrato |
| `RoleController.php` | 180 | Roles y catálogo de permisos |
| `ProspectController.php` | 173 | Prospectos y conversión |
| `BillingActionLogController.php` | 170 | Failover de facturación |
| `SuspensionActionLogController.php` | 145 | Failover de cortes + reconciliación |
| `HelpCenterController.php` | 125 | Centro de ayuda |
| `ExpenseController.php` / `ExpenseCategoryController.php` | — | Gastos |
| `InventoryDevice/Stock/Provider/BranchController.php` | — | Inventario |
| `SectorialPhoto/Note/HistoryController.php` | — | Anexos de elementos de red |
| `RouterOutageController.php` | — | Falla masiva |
| `CatalogController.php` | — | Catálogos globales |
| `PaymentMethodController.php` | — | Formas de pago |
| `InvoiceTypeController.php` | — | Catálogo de tipos de factura (sistema + propios del tenant) |
| `SettingsController.php` | — | Limpieza de cache |
| `VerificationController.php` | — | Verificación de correo |

### 3.4 `app/Services`

| Archivo | Líneas | Responsabilidad |
|---|---:|---|
| `BillingService.php` | ~1560 | Núcleo financiero: generación, numeración, primera factura, pagos, asignación, saldo a favor, **arrastre de abonos parciales**, anulación con lápida, reconexión al pagar, notificaciones |
| `VpnService.php` | 945 | Generación y verificación de scripts L2TP/IPSec |
| `RouterApiService.php` | 912 | Protocolo API nativo MikroTik |
| `MikroTikSshService.php` | 603 | SSH directo o vía CORE |
| `OverdueSuspensionService.php` | 412 | Corte automático: elegibilidad, gate de día/hora, ejecución |
| `CustomerProvisioningService.php` | 338 | Aprovisionar cliente según método de control. **Fuente única** para el job, el masivo y el alta |
| `InstallationBillingService.php` | 253 | Factura de instalación |
| `RouterProvisioningService.php` | 218 | Suspender/reactivar en el router |
| `TrafficHistoryService.php` | 164 | Muestreo y agregación de tráfico |
| `WhatsAppService.php` | 162 | WhatsApp Cloud API (Graph v18) |
| `PaymentReminderService.php` | 209 | Recordatorios (uno por cliente, con todas sus facturas pendientes) |
| `RouterPolicyInstallerService.php` | 151 | Instalación de reglas de bloqueo |
| `Templates/TemplateRenderer.php` | — | Render de plantillas |
| `Templates/TemplateSanitizer.php` | — | Saneado con HTMLPurifier |
| `Templates/PlaceholderResolver.php` | — | Resolución de placeholders |

### 3.5 `app/Services/MikroTik`

| Archivo | Líneas | Recurso RouterOS |
|---|---:|---|
| `InterfaceReader.php` | 912 | Lectura robusta de interfaces WAN (multi-variante v6/v7, envelope `:do/:put`, centinelas, puerto SSH del cliente propagado hasta el `ssh-exec`) |
| `FirewallRulesManager.php` | 753 | `/ip firewall filter` + address-list de suspendidos |
| `PppProfileManager.php` | 723 | `/ppp profile` |
| `MikroTikApiProtocol.php` | 602 | Protocolo binario del API |
| `PppSecretManager.php` | 592 | `/ppp secret` |
| `MikroTikConnectionManager.php` | 525 | Gestión de conexiones |
| `SuspensionManager.php` | 510 | Alta/baja en la lista de morosos |
| `SshTunnelManager.php` | 446 | Túnel SSH |
| `HotspotManager.php` | 387 | `/ip hotspot user` y `user profile` |
| `QueueManager.php` | 381 | `/queue simple` |
| `PcqManager.php` | 320 | `/queue type` + address-list |
| `DhcpLeaseManager.php` | 234 | `/ip dhcp-server lease` |
| `IpMacBindingManager.php` | 233 | ARP estático + drop por par IP/MAC |
| `SshTunnel.php` | 207 | Conexión SSH individual |
| `RouterEndpointResolver.php` | 157 | Resuelve la IP real desde `/ppp active` del CORE y la reescribe en BD |
| `Concerns/BuildsCoreSshExec.php` | — | Construye la línea `/system ssh-exec` (puerto + escapado) |
| `Concerns/DetectsSshExecFailures.php` | — | Distingue fallo real de salida vacía |
| `Concerns/NormalizesRouterComment.php` | — | Normaliza comentarios de objetos |
| `Concerns/VerifiesRouterOsObjectState.php` | — | Verifica que el objeto quedó como se pidió |

### 3.6 Otros directorios de `app`

| Archivo | Descripción |
|---|---|
| `Constants/Permissions.php` | Catálogo de **35 permisos** agrupados en 8 categorías + mapa rol → permisos. Reconciliado con la base por `permissions:sync` |
| `Helpers/ErrorMessages.php` | Traduce `QueryException` a mensajes en español |
| `Http/Middleware/CheckPermission.php` | Verifica permiso, con **semántica OR** (`permission:a,b`). Carga el rol con `withoutGlobalScope('tenant')`. Bypass `role_id == 1`. Consulta los permisos **efectivos** (rol ∪ usuario) |
| `Http/Middleware/CheckStaffProfile.php` | Pese al nombre, **no** exige fila en `staff_profile`: comprueba `role.code ∈ {admin, staff}` o `role_id == 1` |
| `Http/Middleware/SecurityHeaders.php` | CSP, HSTS, X-Frame-Options, COOP, Permissions-Policy |
| `Traits/BelongsToTenant.php` | Global scope + auto-relleno de `tenant_id` desde el usuario autenticado |
| `Traits/FixesSequences.php` | Repara secuencias PostgreSQL desincronizadas al crear |
| `Traits/InputSanitizer.php` | Saneado de entrada |
| `Providers/SearchMacrosServiceProvider.php` | Macros `whereLike`/`orWhereLike`: eligen `ilike` o `like` según el driver y escapan comodines |
| `Jobs/ProvisionCustomerJob.php` | Un job por cliente en el aprovisionamiento masivo asíncrono |
| `Imports/UnifiedImport.php` + `Sheets/*` | Importación de clientes, planes, routers y sectoriales en un solo archivo |
| `Imports/CustomersUpdateImport.php` | Actualización masiva de clientes |
| `Imports/InventoryImport.php` | Carga masiva de equipos |
| `Exports/*` | Plantillas descargables y exportación de errores |
| `Mail/InvoiceCreatedMail.php`, `PaymentReminderMail.php`, `SendTicketNotification.php`, `VerificationCodeMail.php` | Correos transaccionales |
| `Policies/CustomerInstallationPolicy.php` | Autorización de instalaciones |

### 3.7 Configuración y rutas

| Archivo | Descripción |
|---|---|
| `bootstrap/app.php` | Registro de rutas, alias de middleware, `trustProxies('*')`, manejo de `QueryException` → JSON 422 |
| `routes/api.php` | Toda la API REST. **Cada endpoint lleva su permiso** desde 2026-07-30; los de lectura usan semántica OR |
| `routes/web.php` | Redirección raíz, portal de pago, ruta CSRF de Sanctum, **catch-all de la SPA** |
| `routes/console.php` | Planificador: 9 tareas programadas |
| ~~`routes/auth.php`~~ | **Eliminado** (2026-07-30): no lo cargaba `bootstrap/app.php` y referenciaba un controlador y unas vistas Volt inexistentes |
| `config/database.php` | Conexiones. **Nota de seguridad:** `sqlite` no define `url` a propósito, para que `DB_URL` no pueda redirigir los tests a la base real |
| `config/cors.php` | Orígenes desde `CORS_ALLOWED_ORIGINS`; `supports_credentials = true`; `exposed_headers` incluye `X-Template-Warnings` (sin eso el aviso de la vista previa de plantillas no llega al JS en desarrollo, donde Vite y la API están en puertos distintos) |
| `config/sanctum.php` | Dominios stateful, guard `web`, sin expiración de token |
| `config/document_placeholders.php` | Placeholders disponibles en las plantillas |
| `config/filesystems.php` | Disco `s3` para documentos |

---

## 4. Frontend — archivo por archivo

### 4.1 Núcleo

| Archivo | Descripción |
|---|---|
| `router/index.js` | ~40 rutas, todas *lazy-loaded*. Guarda `beforeEach`: autenticación, `requiresStaff`, `meta.permission`, título de página |
| `stores/auth.js` | Pinia: `user`, `isAuthenticated`, `permissions`, `roleCode`, `isStaffOrAdmin`, `hasPermission()` (espejo exacto de `CheckPermission`, **con** bypass de superadmin), `refreshUserPermissions()` |
| `services/api.js` | Instancia axios y manejador global de `401`. El interceptor que inyectaba `tenant`/`tenant_id` se eliminó: el backend siempre lo ignoró |
| ~~`services/auth.js`~~ | **Eliminado**: duplicaba `hasPermission` con lógica distinta a la del store, que es la que usa el guard |
| `services/billing.js` | Cliente de facturación |
| `services/api/*.js` | 19 módulos por dominio: `auth`, `customers`, `routers`, `plans`, `staff`, `support`, `inventory`, `inventory-stock/-provider/-branch`, `sectorials`, `tenant`, `roles`, `prospects`, `catalogs`, `expense`, `expense-category`, `document-templates`, `help-center` |

### 4.2 Composables

| Archivo | Descripción |
|---|---|
| `useNotification.js` | Notificaciones toast |
| `usePermissions.js` | Verificación de permisos en componentes |
| `useProvisionPolling.js` | Sondeo del progreso de aprovisionamiento masivo |
| `useTableControls.js` | Búsqueda, orden y paginación de tablas |

### 4.3 Páginas (44 + 8 de facturación)

| Grupo | Páginas |
|---|---|
| Acceso | `Login`, `Register`, `ResendVerification` |
| Panel | `Dashboard` |
| Clientes | `Customers`, `CustomerAdd`, `CustomerEdit`, `CustomerStatistics`, `CustomerMap` |
| Red | `Routers`, `RouterAdd`, `RouterEdit`, `Sectorial`, `SectorialAdd`, `SectorialEdit`, `SectorialDetail`, `FiberTopology` |
| Planes | `PlanList`, `PlanCreate`, `PlanEdit` |
| Facturación | `Billing/BillingDashboard`, `InvoicesList`, `InvoiceDetail`, `InvoiceEdit`, `PaymentsList`, `RegisterPayment`, `PaymentMethods`, `InvoiceTypes`, `AdditionalCharges` |
| Gastos | `Expenses`, `ExpenseCategories` |
| Soporte | `Support`, `SupportCreate`, `SupportDetail`, `SupportEdit`, `SupportStatistics`, `Installations`, `InstallationDetail` |
| Inventario | `Inventory`, `InventoryForm`, `StockList`, `ProviderList`, `BranchList` |
| Administración | `Staff`, `StaffNew`, `EditStaff`, `RolesManagement`, `Settings`, `MassActions` |
| Ayuda | `Manual`, `NotFound` |

### 4.4 Componentes

| Componente | Descripción |
|---|---|
| `Sidebar.vue` | Navegación por grupos: Usuarios, Soporte, Gestión, Inventarios, Finanzas. Filtra por permisos **y por `role_code`** |
| `BillingPanel.vue` | Panel de facturación del cliente |
| `IpRangeAnalyzer.vue` | Análisis de rangos IP |
| `DatePicker.vue` / `DayPicker.vue` | Selección de fecha / día del mes |
| `SearchableSelect.vue` | Selector con búsqueda |
| `StatCard.vue`, `NotificationToast.vue`, `TimezoneClock.vue`, `WhatsAppButton.vue`, `SubmenuItem.vue`, `SettingsSection.vue` | Piezas de UI |
| `customer/CustomerBilling · CustomerDocuments · CustomerInstallations · CustomerTickets` | Pestañas de la ficha de cliente |
| `import/ImportSection · CustomersUpdateSection · InventoryImportSection · ErrorsModal · FieldDocsModal` | Flujos de carga masiva |
| `settings/DocumentTemplatesSection.vue` | Editor de plantillas |
| `ui/ConfirmModal · LoadingSkeleton · PageHeader · Pagination · SearchBar · StatusBadge` | Primitivas reutilizables |

### 4.5 Vistas Blade

| Archivo | Descripción |
|---|---|
| `app.blade.php` | Shell de la SPA |
| `payment-portal.blade.php` | Portal de pago para clientes suspendidos (`/portal-pago`) |
| `billing/invoice_pdf.blade.php` | PDF de factura |
| `documents/contract_pdf.blade.php`, `installation_sheet_pdf.blade.php` | PDF de contrato y acta |
| `documents/shells/*.blade.php` | *Shells* donde se inserta el HTML editable de cada plantilla |
| `emails/*` | Factura creada, recordatorio, notificación de ticket, verificación |

---

## 5. Relación entre componentes

```mermaid
flowchart TB
    subgraph Frontend
        PG["pages/*.vue"] --> SV["services/api/*.js"]
        PG --> ST["stores/auth.js"]
        RT["router/index.js"] --> ST
        SV --> AX["services/api.js<br/>(axios)"]
    end

    AX -->|HTTPS| RA["routes/api.php"]
    RA --> MW["CheckPermission<br/>CheckStaffProfile"]
    MW --> CT["Controllers"]

    CT --> FR["Http/Requests<br/>validación"]
    CT --> SVC["Services"]
    CT --> MD["Models"]
    SVC --> MD
    SVC --> BIL["Billing/FirstInvoicePolicy"]
    SVC --> MK["Services/MikroTik"]
    MD --> TR["Traits/BelongsToTenant"]
    MD --> DB[("PostgreSQL")]

    SCH["routes/console.php"] --> CMD["Console/Commands"]
    CMD --> SVC
    CT --> JOB["Jobs/ProvisionCustomerJob"]
    JOB --> SVC
    MK --> CORE["CORE MikroTik"]
```

### Dependencias entre servicios

| Servicio | Depende de |
|---|---|
| `BillingService` | `WhatsAppService` (constructor), `FirstInvoicePolicy`, modelos de facturación |
| `OverdueSuspensionService` | `BillingService`, `RouterProvisioningService` |
| `CustomerProvisioningService` | `RouterEndpointResolver` + managers MikroTik |
| `RouterProvisioningService` | `SuspensionManager`, `FirewallRulesManager` |
| Todos los managers MikroTik | `BuildsCoreSshExec`, `DetectsSshExecFailures` |

---

## 6. Módulos de negocio

### 6.1 Módulo Clientes

**Objetivo.** Mantener el registro maestro del abonado y su configuración de servicio,
y reflejarla en el equipo de red.

**Funcionalidades.** Alta individual y masiva; edición con detección automática de fibra;
mapa georreferenciado; documentos y contrato; exclusión de facturación; suspensión y
activación manual; aprovisionamiento individual y masivo.

**Dependencias.** `service_plan` (plan), `router` (equipo), `sectorial` (elemento de red /
OLT-NAP), `user_services` (contrato de facturación), `CustomerProvisioningService`.

**Flujo interno.**

```mermaid
flowchart LR
    A["Formulario"] --> B["StoreCustomerRequest"]
    B --> C{"¿Límite de<br/>clientes?"}
    C -- excedido --> X["403 upgrade_required"]
    C -- ok --> D["Normalizar email_tenant a ASCII"]
    D --> E{"¿IP libre<br/>en el router?"}
    E -- no --> Y["422"]
    E -- sí --> F["Transacción:<br/>users + customer_profile"]
    F --> G["UserService::syncForCustomer()"]
    G --> H{"push_to_router?"}
    H -- no --> I["Sólo BD"]
    H -- sí --> J["CustomerProvisioningService"]
    J --> K["SSH CORE → ssh-exec router"]
```

---

### 6.2 Módulo Facturación

**Objetivo.** Emitir la factura correcta a cada cliente en el momento correcto, cobrarla
y dejar trazabilidad de todo.

**Funcionalidades.** Generación mensual dirigida por router; política de primera factura;
numeración segura por tenant; PDF; recordatorios; registro de pagos con asignación
automática y saldo a favor; anulación con lápida; failover y auditoría.

**Dependencias.** `billing` (config del router), `user_services` (qué facturar),
`FirstInvoicePolicy`, `WhatsAppService`, Mail.

**Flujo interno de la generación mensual.**

```mermaid
flowchart TD
    A["billing:generate-monthly (cada hora)"] --> B["Routers con billing_router_id"]
    B --> C{"today.day >=<br/>create_invoice day?"}
    C -- no --> Z["Siguiente router"]
    C -- sí --> D{"hora actual >=<br/>create_invoice_time?"}
    D -- no --> Z
    D -- sí --> E["Periodo según billing_mode<br/>anticipado = mes actual<br/>vencido = mes anterior"]
    E --> F["Clientes facturables del router<br/>service_status ∈ activo/gratis/suspendido<br/>exclude_from_billing=false"]
    F --> G{"¿Servicio activo<br/>y no cortesía?"}
    G -- no --> Z
    G -- sí --> H{"¿Ya existe factura<br/>del periodo?"}
    H -- sí --> Z
    H -- no --> I{"¿Lápida suppressed?"}
    I -- sí --> Z
    I -- no --> T{"¿Pendientes >= tope?<br/>overdue_invoices + stop_invoicing_extra"}
    T -- sí --> Z
    T -- no --> J["FirstInvoicePolicy::chargeFor()"]
    J --> K{"¿Devuelve null?"}
    K -- sí --> Z
    K -- no --> L["Crear factura + ítem"]
    L --> M["billing_action_logs = success"]
    L -.->|excepción| N["billing_action_logs = failed<br/>backoff 2h/6h/24h"]
```

**Regla clave — el día se recorta al mes.** Un `create_invoice` configurado en día 31 se
convierte en 30 en abril y 28 en febrero (`Billing::clampDayToMonth`), para que la
configuración "último día del mes" siga disparando.

**Regla clave — el corte NO congela la deuda; el tope sí.** La audiencia de la generación es
`service_status` (`CustomerProfile::BILLABLE_SERVICE_STATUSES`), no el booleano `status`: al
cliente **cortado por mora** se le siguen emitiendo mensualidades porque puede reconectarse
pagando. Lo que detiene la acumulación es el **tope de facturación**
(`Billing::invoiceStopThreshold()` = `overdue_invoices + stop_invoicing_extra`, `null` = sin
tope): al llegar a ese número de facturas **pendientes** (con saldo, de cualquier tipo, vencidas
o no) no se emite ninguna más. `retirado` y `cancelado` no facturan nunca. El mismo tope se
aplica en `retryFailedInvoice` (después de la idempotencia) y en `auditMonthlyBilling`, que
reporta esos clientes en la columna informativa `capped` para no dar falsos `partial`.

**Notificación al crear.** No se notifica la factura de un mes de cortesía ni la que **nace
saldada** con el saldo a favor del cliente (`balance_due = 0` tras `applyCreditToInvoice`). Si el
cliente arrastra deuda anterior, `InvoiceCreatedMail` incluye `pending_count`, `previous_due` y
`pending_total` para que el correo muestre la deuda total y no sólo el mes.

---

### 6.3 Módulo Cobranza y corte

**Objetivo.** Convertir la mora en una acción real sobre el equipo, y el pago en una
reconexión real.

**Condiciones de corte** (todas deben cumplirse):

1. El router tiene `cut_type` con nombre **`Corte Automático`**.
2. El router tiene configuración de facturación (`billing_router_id`).
3. `hoy.día >= billing.cut_day` (recortado al mes).
4. `hora actual >= billing.cut_time`.
5. El cliente acumula al menos `billing.overdue_invoices` facturas vencidas
   (`balance_due > 0` y `due_date < now`).

Los routers marcados **`Corte Manual`** no suspenden: sólo encolan y reportan los pendientes.

```mermaid
sequenceDiagram
    participant CRON as Scheduler (horario)
    participant OSS as OverdueSuspensionService
    participant RPS as RouterProvisioningService
    participant RB as RouterBoard
    participant LOG as suspension_action_logs

    CRON->>OSS: billing:auto-cut
    OSS->>OSS: filtrar routers y clientes elegibles
    OSS->>RPS: suspend(cliente)
    RPS->>RB: address-list add + flush conntrack
    alt éxito
        RB-->>LOG: status=success
    else fallo
        RB-->>LOG: status=failed, backoff 30m/2h/6h/24h
    end
    Note over CRON,LOG: billing:reconcile-suspensions re-aplica lo no confirmado
    Note over CRON,LOG: billing:verify-cuts alerta si un router quedó sin cortar
```

**Reconexión automática.** Al registrar un pago, `BillingService::reactivateIfCleared()`
comprueba si el cliente quedó al día y, **sólo si el corte fue de facturación**, lo reactiva
en el router. Un corte manual no se revierte solo.

> **Detalle no evidente:** `RouterProvisioningService::suspend`/`unsuspend` **no tocan**
> `customer_profile.status` — el estado real del corte vive en el router y en
> `suspension_action_logs`. Quienes sí lo escriben son los llamadores: el auto-cut
> (`OverdueSuspensionService`) y la suspensión manual dejan `status = false` +
> `service_status = 'suspendido'` antes de hablar con la RB, para que el reconciliador
> (que escanea `status = false`) cubra los cortes fallidos.
>
> Ese `service_status = 'suspendido'` **no** saca al cliente de la facturación: la generación
> mensual filtra por `CustomerProfile::BILLABLE_SERVICE_STATUSES` y sólo excluye
> `retirado`/`cancelado`. Ver §6.2 (tope de facturación).

---

### 6.4 Módulo Red / MikroTik

**Objetivo.** Ejecutar sobre el equipo lo que el sistema decide.

**Funcionalidades.** Aprovisionamiento por método de control; reglas de bloqueo; scripts
VPN; lectura de interfaces; historial de tráfico; falla masiva.

**Flujo interno.** Ver [`ARQUITECTURA.md §8`](ARQUITECTURA.md#8-integración-mikrotik-el-núcleo-diferencial).

**Puntos de fallo documentados en el propio código:**

| Síntoma | Causa real |
|---|---|
| `<connection failed> <ip>:22` | `router.ip` obsoleto por reasignación del pool L2TP **o** SSH en puerto distinto de 22 |
| Aprovisionamiento "exitoso" pero sin efecto | `ssh-exec` con `exit-code ≠ 0` se reportaba como éxito (corregido) |
| Perfil HotSpot no se crea | `/ip hotspot user profile` **no acepta `comment`** y el comando entero falla |
| Cliente en la lista de morosos sigue navegando | Faltaba `place-before`, dependencia de `out-interface=wan`, sin flush de conntrack y sin regla de acceso al portal (los cuatro corregidos) |
| "Actualiza pero no carga a la RB" | Timeout del gateway en el push PPPoE síncrono, no un error de datos |

---

### 6.5 Módulo Comercial (prospectos e instalaciones)

**Objetivo.** Acompañar al futuro cliente desde el interés hasta la instalación cobrada
y firmada.

**Flujo.**

```mermaid
stateDiagram-v2
    [*] --> interesado
    interesado --> agendado: se agenda instalación
    agendado --> instalado: instalación completada
    instalado --> convertido: se crea el cliente
    interesado --> rechazado
    agendado --> rechazado
    convertido --> [*]
```

La instalación registra costo, cargos adicionales (con ítems en JSON), descuento con motivo,
forma de pago y monto recibido; genera factura de tipo `installation`; guarda el acta en
JSON y las firmas del cliente y el técnico como imágenes en S3.

---

### 6.6 Módulo Soporte

**Objetivo.** Registrar y resolver incidencias, y cobrar lo que sea cobrable.

Ticket con categoría, prioridad, estado, cliente, staff asignado y **elemento de red
afectado** (`sectorial_id`). Los mensajes admiten la marca `is_internal`. Un ticket puede
generar facturas de tipo `service_charge`.

> **Detalle de permisos poco intuitivo:** **Instalaciones no tiene permiso propio**: todo el
> grupo Soporte (Tickets, Nuevo Ticket, Instalaciones, Estadísticas) se muestra con
> `view_support`. Quien pueda ver tickets puede ver instalaciones, y viceversa.
> Las operaciones de conversación y cargo exigen además fila en `staff_profile`.

---

### 6.7 Módulos de apoyo

| Módulo | Objetivo | Dependencias |
|---|---|---|
| **Inventario** | Equipos por serial/MAC, con stock, proveedor y sucursal | `BelongsToTenant`; importación masiva |
| **Gastos** | Registro de gastos operativos por categoría y beneficiario | Sin borrado físico: se anulan |
| **Plantillas de documentos** | HTML editable por tenant para factura, contrato y acta | `HTMLPurifier`, `PlaceholderResolver`, permiso propio |
| **Centro de ayuda** | Manual embebido por categorías y artículos | — |
| **Acciones masivas** | Importaciones, aprovisionamiento masivo, paneles de failover | `execute_mass_actions` |

---

## 7. Reglas de negocio no evidentes

Recopilación de decisiones que **no se deducen leyendo sólo el esquema** y que han causado
incidentes reales.

| # | Regla | Dónde vive |
|---|---|---|
| 1 | La facturación **se dirige por router**, no por tenant ni por cliente | `BillingService::generateMonthlyInvoices` |
| 2 | Se factura desde `user_services`, **no** desde `customer_profile.service_id` | `BillingService` |
| 3 | `is_courtesy` en el plan ⇒ el servicio queda en `gratis` ⇒ nunca se factura | `UserService::statusForPlan` |
| 4 | Una factura borrada **nunca se regenera** (lápida `suppressed`) | `BillingService::suppressRegeneration` |
| 5 | La política de primera factura tiene **dos ejes independientes** con cascada propia cliente → plan → router | `FirstInvoicePolicy::resolve` |
| 6 | El prorrateo **no cobra el día de instalación**: instalado el 20 de un mes de 30 ⇒ 10 días | `installationMonthCharge` |
| 7 | Los meses de cortesía son **posteriores** al de instalación y se emiten en **cero**, no se omiten | `chargeFor` |
| 8 | El método de control del router es **excluyente**; `ip_bindings` y `amarre` son aditivos | `resolveControlMode` |
| 9 | `customer_profile.status` es **booleano** | Comentario explícito en `BillingService` |
| 10 | `LIKE` es sensible a mayúsculas en PostgreSQL; hay que usar `ilike` por driver | Búsqueda de facturación |
| 11 | Los tests corren en **SQLite**: las migraciones deben evitar SQL exclusivo de PostgreSQL o protegerlo por driver | `tests/` |
| 12 | La IP es única **por router**, no por tenant | `CustomerProfileController` |
| 13 | El usuario PPPoE es único por router; sin ello RouterOS **sobrescribe en silencio** el secret de otro cliente | Índice parcial + `StoreCustomerRequest` |
| 14 | El correo de login se normaliza a ASCII; los nombres conservan tildes y ñ | `User::sanitizeEmail` |
| 15 | Un permiso nuevo **no llega solo** a los roles existentes: hay que ejecutar `permissions:sync` (o una migración de backfill) | `SyncRolePermissions` |
| 16 | El scope de tenant sobre `Role` puede anular el rol propio y producir un falso `403` | `CheckPermission`, `AuthController@login` |
| 17 | Los permisos efectivos son la **unión** de `role.permissions` y `users.permissions`; la unión sólo concede, nunca revoca | `User::effectivePermissions()` |
| 18 | `email_verified_at` **no está en `$fillable`**: `User::create()` lo descarta en silencio y el usuario nace sin verificar (login 403) | `User::$fillable` |
| 19 | En el grupo `api`, `SubstituteBindings` corre **antes** que el middleware de la ruta: un id inexistente da 404 antes de comprobar el permiso | `RouterController::destroy(Router $router)` |
| 20 | El modo de corte se compara con `CutType::matches()`, que normaliza tildes y mayúsculas: `'Corte Automatico'` sin tilde dejaba de cortar sin error | `CutType`, `OverdueSuspensionService` |
| 21 | Un **abono parcial cierra la factura** (`paid`, saldo 0) y el faltante viaja a la siguiente en `invoice_carryovers`. Efecto buscado: el cliente sale de mora y **se reconecta** aunque no haya pagado todo | `BillingService::carryOverShortfall` |
| 22 | Sólo la generación **mensual** absorbe arrastres; los cargos adicionales, las facturas manuales y los meses de cortesía **no** | `BillingService::applyPendingCarryoversTo` |
| 23 | Un arrastre ya `applied` **no se revierte** al anular el pago original: la deuda se queda en la factura que la cobró para no duplicarla | `revertPendingCarryoversOfPayment`, `markInvoiceUnpaid` |
| 24 | `invoices.invoice_type` guarda el **slug en texto**, no una FK a `invoice_types`: los tipos del sistema son globales (`tenant_id` NULL) y hay facturas anteriores al catálogo | `InvoiceType`, `Invoice::type()` |

---

## 8. Flujo completo del sistema

Recorrido de extremo a extremo: **desde que un prospecto llama hasta que su mora le corta
el servicio y su pago se lo devuelve.**

```mermaid
sequenceDiagram
    autonumber
    actor OP as Operador
    participant SPA as Vue SPA
    participant API as Laravel API
    participant DB as PostgreSQL
    participant SCH as Scheduler
    participant CORE as CORE MikroTik
    participant RB as RouterBoard
    actor CL as Cliente

    rect rgb(240,248,255)
    Note over OP,DB: FASE 1 — Captación
    OP->>SPA: Registra prospecto
    SPA->>API: POST /api/prospects
    API->>DB: prospects (status=interesado)
    OP->>SPA: Agenda instalación
    SPA->>API: POST /api/prospects/{id}/installations
    API->>DB: customer_installations (status=pendiente)
    end

    rect rgb(245,255,245)
    Note over OP,RB: FASE 2 — Instalación y alta
    OP->>SPA: Completa acta + firmas + cobro
    SPA->>API: PUT /sheet · POST /sign · PUT /billing
    API->>DB: sheet, firmas en S3, invoice tipo installation
    OP->>SPA: Crea el cliente
    SPA->>API: POST /api/customers/first-invoice-preview
    API-->>SPA: prorrateo + meses de cortesía
    SPA->>API: POST /api/customers
    API->>DB: users + customer_profile + user_services
    API->>CORE: SSH
    CORE->>RB: ssh-exec (queue / secret / hotspot / lease)
    RB-->>API: ok
    SPA->>API: POST /api/prospects/{id}/mark-converted
    end

    rect rgb(255,250,240)
    Note over SCH,CL: FASE 3 — Ciclo mensual
    SCH->>API: billing:generate-monthly (cada hora)
    API->>DB: gate por create_invoice day + time
    API->>DB: FirstInvoicePolicy → invoice + invoice_items
    API->>CL: correo / WhatsApp "factura creada"
    SCH->>API: billing:send-reminders
    API->>CL: recordatorio en payment_reminder day+time
    SCH->>API: billing:verify-monthly (06:00)
    API->>OP: alerta si algún router no facturó
    end

    rect rgb(255,245,245)
    Note over SCH,RB: FASE 4 — Mora y corte
    SCH->>API: billing:auto-cut (cada hora)
    API->>DB: ¿cut_day + cut_time y N facturas vencidas?
    API->>CORE: suspender
    CORE->>RB: address-list add + flush conntrack
    RB-->>DB: suspension_action_logs
    CL->>CL: navega sólo al portal de pago
    SCH->>API: billing:reconcile-suspensions
    SCH->>API: billing:verify-cuts (07:00)
    end

    rect rgb(245,245,255)
    Note over OP,RB: FASE 5 — Pago y reconexión
    CL->>OP: paga
    OP->>SPA: Registrar pago
    SPA->>API: POST /api/billing/payments
    API->>DB: payment + allocations (más antigua primero)
    API->>DB: excedente → credit_balance
    API->>API: reactivateIfCleared()
    API->>CORE: reconectar
    CORE->>RB: address-list remove
    RB-->>CL: servicio restablecido
    end
```

### Ciclo de vida de una factura

```mermaid
stateDiagram-v2
    [*] --> draft: creación manual
    [*] --> issued: generación mensual
    draft --> issued
    issued --> paid: pago total
    issued --> paid: abono parcial (carried_out > 0)
    issued --> overdue: vence sin pago
    overdue --> paid: pago total o abono parcial
    paid --> issued: mark-unpaid (revierte pagos)
    paid --> partial: mark-unpaid con arrastre ya cobrado en otra factura
    partial --> paid: se completa
    issued --> void: anulación
    issued --> [*]: DELETE → lápida suppressed
    paid --> [*]
```

> **`partial` ya casi no se ve.** Desde el arrastre de saldo, un abono que no cubre la
> factura la deja en `paid` con `carried_out > 0`. El estado `partial` sólo aparece al
> revertir un pago cuyo arrastre **ya** lo cobró otra factura: entonces la original
> vuelve a deber sólo la parte que el abono había cubierto.

---

## 9. Trazabilidad módulo → código → datos

| Módulo | Página SPA | Endpoint | Controlador | Servicio | Tablas |
|---|---|---|---|---|---|
| Clientes | `Customers`, `CustomerAdd/Edit` | `/api/customers*` | `CustomerProfileController` | `CustomerProvisioningService` | `users`, `customer_profile`, `user_services` |
| Mapa | `CustomerMap` | `/api/customers/map`, `/api/tenant/maps-config` | `CustomerProfileController`, `TenantController` | — | `customer_profile`, `tenant` |
| Prospectos | `Installations` | `/api/prospects*` | `ProspectController` | — | `prospects` |
| Instalaciones | `InstallationDetail` | `/api/installations*` | `CustomerInstallationController` | `InstallationBillingService` | `customer_installations`, `customer_documents`, `invoices` |
| Facturación | `Billing/*` | `/api/billing/*` | `BillingController` | `BillingService`, `FirstInvoicePolicy` | `billing`, `invoices`, `invoice_items`, `invoice_carryovers`, `payments`, `payment_allocations` |
| Tipos de factura | `Billing/InvoiceTypes` | `/api/billing/invoice-types*` | `InvoiceTypeController` | — | `invoice_types` |
| Cobranza | `MassActions` | `/api/billing/suspension-logs*` | `SuspensionActionLogController` | `OverdueSuspensionService`, `RouterProvisioningService` | `suspension_action_logs`, `cut_type` |
| Failover facturación | `MassActions` | `/api/billing/action-logs*` | `BillingActionLogController` | `BillingService` | `billing_action_logs` |
| Routers | `Routers`, `RouterAdd/Edit` | `/api/routers*` | `RouterController` | `VpnService`, `RouterApiService`, managers MikroTik | `router` |
| Tráfico | `Routers` | `/api/routers/{id}/traffic` | `RouterController` | `TrafficHistoryService` | `traffic_samples`, `traffic_daily` |
| Falla masiva | `Routers` | `/api/routers/{id}/outage*` | `RouterOutageController` | — | `router_outage_events` |
| Sectoriales / FTTH | `Sectorial`, `FiberTopology` | `/api/sectorials*` | `SectorialController` + anexos | — | `sectorial`, `sectorial_history/note/photo` |
| Planes | `PlanList/Create/Edit` | `/api/plans*` | `PlanController` | `PppProfileManager`, `HotspotManager`, `PcqManager` | `service_plan`, `type_plans` |
| Soporte | `Support*` | `/api/support*` | `SupportTicketController` | — | `support_ticket`, `_message`, `_attachment`, `invoices` |
| Inventario | `Inventory`, `StockList`… | `/api/inventory*` | `InventoryDevice/Stock/Provider/BranchController` | — | `inventory_*` |
| Gastos | `Expenses` | `/api/expenses*` | `ExpenseController` | — | `expenses`, `expense_categories` |
| Personal / Roles | `Staff`, `RolesManagement` | `/api/staff*`, `/api/roles*` | `UserController`, `RoleController` | — | `users`, `staff_profile`, `role` |
| Empresa | `Settings` | `/api/tenants*`, `/api/document-templates*` | `TenantController`, `DocumentTemplateController` | `Templates/*` | `tenant`, `document_templates` |
| Acciones masivas | `MassActions` | `/api/import/*` | `ImportController` | `UnifiedImport` y afines | Múltiples |

---

## 10. Registro de decisiones técnicas

Decisiones deliberadas cuya justificación está documentada en el propio código.

| Decisión | Justificación registrada |
|---|---|
| El `tenant_id` se deriva **sólo** del usuario autenticado | Mitigación OWASP A01/A04 — el query param permitía fuga entre tenants |
| `BulkProvisionRun` **sin** global scope de tenant | Los jobs en cola corren sin sesión; el filtrado se hace explícito en el controlador |
| El rol propio se carga con `withoutGlobalScope('tenant')` | Un desajuste de `tenant_id` anulaba el rol y producía un falso 403 |
| `GET /api/roles` abierto a cualquier autenticado | Los desplegables de personal lo necesitan; la escritura sigue protegida |
| `manage_document_templates` separado de `manage_tenant` | Un rol creado para "cambiar el teléfono" no debe poder reescribir cláusulas de contrato |
| Sin `->where()` en la ruta `{type}` de plantillas | Un tipo inválido debe llegar al controlador y devolver 404 JSON, no caer en el catch-all de la SPA |
| Las columnas `*_encrypted` **no** llevan cast `encrypted` | La migración copió texto plano con SQL crudo; el cast lanzaba `DecryptException` en toda lectura |
| `config/database.php` omite `url` en la conexión `sqlite` | `ConfigurationUrlParser` mezcla `DB_URL` en **cualquier** conexión: los tests podían acabar escribiendo en la base real |
| `withoutOverlapping` en las tareas horarias | Una ejecución larga no debe apilarse con el siguiente tick |
| El corte usa `drop` incondicional en `chain=forward` | Funciona con cualquier topología: mono-WAN, multi-WAN, PCC, failover, PPPoE upstream |
| Backoff de cortes más agresivo (30 min) que el de facturación (2 h) | Un corte sin aplicar es fuga de ingreso directa |
| El aprovisionamiento es asíncrono **también en el alta individual y en el botón "Aprovisionar"**, no sólo en el masivo | Cada cliente tarda 17–34 s por el doble salto SSH; el síncrono provocaba timeout del gateway. El alta persiste el cliente de forma síncrona y encola sólo el tramo que toca RouterOS (`startAsyncProvision`, run dedicado con `total=1`), así que un fallo de cola nunca se reporta como fallo de creación |
| Las fotos de instalación se suben **de una en una** y comprimidas | Varias fotos juntas producían `413`/`504` sin JSON en el gateway |
| Los importadores no consultan por fila | Con 200 filas se alcanzaba el `504` del gateway |
| El transporte VPN es **por router**, no global (`router.vpn_transport`) | WireGuard existe desde RouterOS 7.1 y en v6 no lo hay: los dos transportes conviven de forma permanente |
| Ante un firmware ilegible se elige **L2TP**, no WireGuard | L2TP funciona en las dos ramas; emitir un script WireGuard a un v6 lo deja sin túnel |
| Las claves WireGuard las acuña **ISPWatch** (phpseclib X25519), no el router | Leer la clave pública del equipo exigiría un túnel previo, y un router recién instalado no tiene ninguno |
| El `listen-port` WireGuard del cliente se **busca libre** en tiempo de ejecución | 13231 es el default de RouterOS y lo ocupa el Back To Home VPN; al chocar, la interfaz queda deshabilitada. Como el cliente disca, el puerto local es indiferente |
| Las reglas `ISPWatch-CORE-no-nat` / `-no-mark` son **obligatorias** en el script L2TP | Con multi-WAN el IKE y los datos salen por IPs públicas distintas y el CORE rechaza el L2TP; en v6 es la única defensa posible |
| `wg_public_key` **no** lleva cast `encrypted` (`wg_private_key` sí) | Se compara contra los peers registrados en el CORE, y un valor cifrado no es consultable en SQL |
| `RouterEndpointResolver` no consulta `/ppp active` para routers WireGuard | No aparecen ahí: el "no encontrado" se confundiría con un túnel caído. En WireGuard la IP de overlay es fija por diseño |
| El sanitizer de plantillas corre **antes** de sustituir placeholders, no después | `TemplateSanitizer`/`AdvancedTemplateSanitizer` no saben qué es un placeholder — `{{token}}` es texto inerte para HTMLPurifier. Sanitizar después de insertar el HTML de bloque (tabla, fotos) lo destruiría, porque ese HTML necesita tags (`<img>`, `colspan`) prohibidos en el allowlist del tenant |
| Los placeholders de bloque se sustituyen vía marcador opaco + `DOMDocument`, nunca `str_replace` directo | Un tenant podría poner el token dentro de un atributo HTML (`title="{{factura.tabla_items}}"`); insertar HTML real ahí corrompería el atributo. El marcador sólo se expande si un recorrido de nodos de texto lo encuentra — un atributo no es un nodo de texto navegable, así que es estructuralmente imposible que se expanda ahí |
| Un marcador de bloque repetido reutiliza el **mismo** marcador (no uno por ocurrencia) | Consistencia con cómo ya se comportaban los placeholders escalares (mismo token = mismo valor en todas sus apariciones) |
| `PlaceholderResolver::apply()` escapa con `htmlspecialchars()` en vez de depender de una pasada de sanitización posterior | Una segunda pasada de sanitización después de insertar bloques destruiría el HTML de confianza (`<img>` de fotos/firmas). Escapar en el momento de sustituir da la misma garantía sin ese conflicto |
| Un placeholder desconocido, con typo, o de **otro tipo de documento** se blanquea a `''`, no queda visible | Regla ya establecida desde la Fase 1 (`{{no.existe}} → ''`); mantenerla también cubre el caso de un tenant que pega `{{factura.*}}` en un contrato — deuda reconocida, ver `MEJORAS_RECOMENDADAS.md` |
| `X-Template-Warnings` sólo cubre bloques que **sí correspondían** al tipo de documento pero no se pudieron insertar | No cubre placeholders de otro tipo de documento ni typos — esos ni siquiera llegan a generar un marcador, así que `BlockMarkerInjector` nunca los ve |
| `cerdic/css-tidy` es dependencia de **producción**, no `--dev` | El modo avanzado lo necesita en tiempo de ejecución (`Filter.ExtractStyleBlocks`), no sólo para el spike de prueba que lo introdujo originalmente |
| El modo avanzado nunca activa `CSS.Trusted` ni `HTML.Trusted`, aunque el CSS permitido sea "amplio" | `CSS.Trusted` habilitaría `position`/`top`/`left`/`right`/`bottom`/`z-index` (overlays que ocultan/falsifican contenido en un documento fiscal); `HTML.Trusted` habilitaría `<script>`. Ninguno de los dos es necesario para lo que se pidió ("CSS amplio, excepto url()") |
| Ninguna propiedad CSS que sólo tenga sentido con `url()` está en el allowlist del modo avanzado (`background-image`, `background` shorthand, `list-style-image`) | Así `url()` queda excluido por diseño del allowlist, no sólo por el filtro de esquema de `URI.AllowedSchemes` — defensa en profundidad, no una sola capa |
| `<html>`/`<head>`/`<body>` del tenant en modo avanzado no se validan como tags permitidos | HTMLPurifier no está diseñado para sanear documentos completos, sólo contenido de body. Se descartan y el documento final se reconstruye con un esqueleto propio (`AdvancedTemplateSanitizer::sanitizeParts()`), inyectando ahí el `<style>` ya limpiado por `Filter.ExtractStyleBlocks` |
| `config/dompdf.php` se publicó y `enable_remote` quedó hardcodeado en `false`, no vía `env()` | El contenido de plantillas de tenant se renderiza con esta misma instancia de dompdf; no debe depender de un default del paquete que podría cambiar en una futura versión |
| `AdvancedTemplateSanitizer` habilita `id`/`style` en **todos** los tags del allowlist, y `Attr.EnableID=true` (auditoría 2026-08-03) | Diagnóstico sobre 2 plantillas reales exportadas de WispHub (contrato + hoja de instalación) mostró `<div style="page-break-before:always">` perdiendo `style` (sólo lo tenían div/span/td/th) y `#clausulas{...}` en `<style>` sin efecto porque el `id` correspondiente se descartaba en silencio. Verificado empíricamente vía tinker antes de activar: `Attr.EnableID` valida sintaxis y fuerza unicidad, no es un bypass; el riesgo típico del flag (colisión con la página anfitriona) no aplica porque la salida es siempre un PDF standalone |
| `{{empresa.logo}}` es un placeholder de **bloque**, no escalar, y rompe la invariante previa de "contrato sin bloques" | Una imagen no puede ser un placeholder escalar (texto plano escapado); se agregó a los 3 tipos de documento porque no hay razón de negocio para excluir logo del contrato — antes sólo instalación/factura tenían bloques |
| El logo se resuelve a una ruta **local** en disco (`public_path('storage/'.$tenant->logo)`), nunca una URL | Mismo patrón ya probado en `invoice_shell.blade.php`; más seguro que una URL (cero fetch de red, inmune a `enable_remote`) y sin riesgo de path traversal porque la ruta la construye el servidor, el tenant sólo escribe el token |
| `BlockPlaceholderResolver::resolveLogo()` normaliza `\` a `/` en la ruta antes de renderizar el partial | El serializador de libxml usado por `BlockMarkerInjector::spliceIntoDom()` (`DOMDocument::saveHTML()`) percent-codifica el backslash en atributos URI (`src`) — invisible en producción (Linux), pero rompe la ruta en un dev local Windows. Detectado al escribir el test end-to-end de este bloque, no era un supuesto de diseño previo |
| Una plantilla guardada **antes** de habilitar `Attr.EnableID` no recupera automáticamente sus `id` | La sanitización corre una sola vez, al guardar (`DocumentTemplateController::update()` persiste el HTML ya saneado, no el original) — un cliente con plantillas ya guardadas debe volver a pegar/guardar su HTML original para que sobrevivan los `id` |
| La corrupción de encoding (`Ã³` etc.) observada en las plantillas de prueba del usuario **no** la introduce `AdvancedTemplateSanitizer` | Verificado empíricamente: texto UTF-8 con tildes bien codificado sobrevive intacto a través del sanitizer real. La causa más probable es el origen del archivo `.txt` (exportación/copiado desde WispHub), no el pipeline de ISPwatch |
| **Incidente 2026-08-04**: el logo del tenant se veía "roto" en Configuración → Plantillas | Causa raíz confirmada en disco: `public/storage` era un directorio real, no el symlink de `storage:link` — creado como efecto colateral de los tests de `empresa.logo` del día anterior, que hacían `mkdir()` manual bajo `public_path('storage/...')` en un entorno donde el symlink todavía no existía (ver trampa #22 en `MANUAL_DESARROLLADOR.md`). El logo ya subido seguía intacto en `storage/app/public/tenant_logos/`, sólo era inalcanzable por la ruta pública. Corregido con `rm` del directorio real + `php artisan storage:link`, y los tests reescritos para no volver a causarlo |
| `loadTenantBranding()` en `DocumentTemplatesSection.vue` ahora reporta el error en vez de fallar en silencio | El `catch` vacío original hacía indistinguible "el GET falló" de "nunca se guardó nada" — el usuario reportó exactamente ese síntoma (color/pie de página vueltos a los valores por defecto en cada reingreso) sin ningún rastro para diagnosticarlo. El guardado (`PUT /tenant/config`) se verificó correcto de punta a punta (controlador → modelo → DB → GET), así que el defecto está en la visibilidad del fallo de carga, no en la persistencia — ahora con un toast/`console.error` que la expone |
| **Causa raíz real del "reseteo" de marca (2026-08-04):** `google_maps_api_key` (campo `encrypted`, ajeno a branding) no se puede desencriptar con la `APP_KEY` de este `.env` local — `TenantController::show()` tumbaba con 500 **todo** el payload del tenant por un campo sin relación alguna con lo que el usuario estaba editando | Reproducido fuera del navegador (mismo usuario, mismo middleware, vía el HTTP Kernel directo) para obtener el mensaje real: `DecryptException: The MAC is invalid`. Verificado que no es exclusivo de este campo (`Router.password_rb`, `wg_private_key`, `CustomerProfile.pppoe_password` fallan igual) ni del schema `ispwatch_dev` (falla igual apuntando a `public`, sólo lectura) — ver `MEJORAS_RECOMENDADAS.md` P-6 para la hipótesis de por qué (APP_KEY distinta por entorno, no necesariamente un incidente de producción) |
| `TenantController::safeGoogleMapsApiKey()` aísla el único campo `encrypted` que se lee fuera de un flujo que ya espera desencriptar (routers, VPN) | Un campo sin relación con el resto del payload no debe poder tumbar `show()`/`mapsConfig()` completos; degrada a `null` + `Log::warning`, nunca deja el `DecryptException` sin capturar |
| `{{contrato.firma_cliente}}` agregado como 2° placeholder de bloque de contrato (auditoría 2026-08-04) | Descubierto al preparar y probar end-to-end un HTML real de un cliente (WispHub) contra el modo avanzado: la firma capturada por `CustomerDocumentController::signContract()` nunca llegaba al PDF en modo avanzado porque no hay shell fijo que la imprima fuera de `body_html` — a diferencia de modo seguro. `BlockPlaceholderResolver::forContract()` ahora acepta `?string $signature` |
| `BlockMarkerInjector::replaceMarkersInTextNode()` salta `insertBefore()` cuando el fragmento del bloque es `''` | `insertBefore()` con un `DocumentFragment` sin hijos dispara un warning de PHP ("Document Fragment is empty") — inofensivo (el bloque igual queda vacío, como se espera) pero ruidoso en logs; ningún test end-to-end previo había ejercitado un bloque presente-pero-vacío hasta probar un HTML real completo |
| `AdvancedTemplateSanitizer::fixDompdfPaginationQuirks()` retira `page-break-before` del primer elemento y las alturas fijas de la familia `<table>` (auditoría 2026-08-04) | Diagnóstico por variantes controladas sobre un contrato real (una sola variable a la vez): quitar los saltos de página, la tabla más ancha que la hoja o los `<div>` anidados **no cambiaba nada**; quitar sólo las alturas llevaba el PDF de 8 páginas con 3 en blanco a 7 con 1. Se limita a `<table>`/`<tr>`/`<td>`/`<th>`: en `<img>`/`<div>` la altura es legítima y no causa el problema. Ambas correcciones van en UNA pasada de DOM para no parsear dos veces |
| **NO** se automatiza la conversión de celdas de tabla sobredimensionadas a `<div>`, pese a ser la causa de la última página en blanco | dompdf no parte un `<td>` entre páginas: lo empuja entero y **recorta lo que no cabe en silencio** (medido: 15.847 → 17.682 caracteres al convertir ese bloque a `<div>`, con el texto fuente verificado idéntico — se estaban perdiendo ~1.800 caracteres de texto legal). Saber si desbordará exige renderizar primero, y convertir tablas a divs a ciegas alteraría el diseño de plantillas que hoy funcionan. Se corrige en la plantilla y se documenta; ver `MEJORAS_RECOMENDADAS.md` P-8 |
| Lección de método (2026-08-04): dos mediciones erróneas se colaron antes de llegar a la causa real | (1) Contar páginas vacías partiendo la salida de `pdftotext` por `\f` con `awk` daba un desfase — lo correcto es `pdftotext -f N -l N` página por página. (2) Un experimento de conversión con `str_replace` coincidió 3 veces en vez de 1 y destruyó el 34% del contenido, invalidando su resultado. Desde entonces, toda transformación estructural de prueba **verifica que el texto plano sea idéntico antes y después** antes de creerle al conteo de páginas |
| El consecutivo del contrato se reserva **antes** de renderizar el PDF | El número va impreso en el encabezado del documento. Un render fallido quema el número, y un hueco en la secuencia es preferible a dos contratos con el mismo número (§12) |
| Los contratos subidos a mano (`signed = false`) **no** reciben consecutivo | No se puede sellar por dentro un archivo que el sistema no generó; el número existiría sólo en la base y no en el papel (§12) |

---

## 11. Auditoría del manual de usuario — 2026-08-03

Repaso completo de `MANUAL_USUARIO.md` contra el código, para eliminar información que había
quedado desactualizada tras los cambios de julio. Se verificó afirmación por afirmación; lo que
sigue es lo que **estaba mal** y por qué.

| Afirmación del manual | Realidad verificada en código | Corregido en |
|---|---|---|
| «La búsqueda distingue mayúsculas» | Falso desde el 2026-07-30: `SearchMacrosServiceProvider` elige `ilike` en pgsql y `like` en sqlite; `BillingController::index` hace lo propio en línea | §5.1, §7.2, FAQ |
| «Sube las fotos de instalación de una en una» | Falso desde el fix de compresión en navegador (#195, en producción): el usuario las selecciona todas y el front las comprime y envía de a una | §6.2, FAQ |
| «Ingresos del mes = suma de las facturas pagadas este mes» | La respuesta devuelve `revenue.monthly => $monthlyPayments` — **pagos** recibidos en el mes. `$monthlyRevenue` se calcula y se descarta (ver `MEJORAS_RECOMENDADAS.md` P-11). Faltaban además `pending` y `collection_rate` | §4 |
| «La carga al router tarda 17–34 s; si sale timeout, reaprovisiona» | Ya no aplica: el alta encola el push (`startAsyncProvision`). El cliente se guarda de inmediato y no hay timeout que ver | §5.2 |
| Nada sobre `agregar_cliente_mkt` | Es la compuerta real del push y **por defecto viene en `false`**. Con ella apagada el alta se guarda y nunca llega al equipo, sin señal en pantalla. Causa raíz frecuente de "creé el cliente y no navega" | §5.2, §18.4, FAQ |
| «Eliminar un cliente borra su perfil, facturas y documentos» | Cierto pero incompleto: `destroy()` no desaprovisiona nada en RouterOS. El cliente sigue navegando y deja de ser visible (`MEJORAS_RECOMENDADAS.md` P-10) | §5.5, FAQ |
| «El aviso de falla masiva se difunde por WhatsApp» | `RouterOutageController` sólo registra el evento y marca `falla_general`; el envío depende de que el sistema de mensajería conectado consuma el registro. Si esa integración no está montada, no le llega nada al cliente | §11.6 |
| Nada sobre estados de servicio | `BILLABLE_SERVICE_STATUSES = ['activo','gratis','suspendido']`: al **suspendido se le sigue facturando** a propósito, y sólo `retirado`/`cancelado` son bajas definitivas. Dejar suspendido a quien se fue genera cartera incobrable | §5.4 (nueva) |
| Nada sobre la VPN como dependencia | Todo el control de red pasa por el túnel contra el CORE; con el túnel caído el sistema marca cortes que nunca llegan al equipo. `vpn:verify-tunnels` corre cada 30 min y sólo alerta | §9.4 y §11.7 (nuevas) |
| Catálogo de permisos incompleto | Faltaban 8 permisos de `Permissions::getAllPermissions()` y no se documentaban los roles de fábrica | §16.2 |

**Añadido además:** §9.4 (diagnóstico ordenado de "aparece cortado pero navega": VPN caída →
reglas por debajo en la cadena → conntrack → *Reconciliar*), retención real del historial de
tráfico (detalle 30 días, diario permanente), puertos de splitter derivados del `split_ratio`,
y la nota de que sin `PORTAL_IP` las reglas de bloqueo ni siquiera se pueden aplicar.

**Deuda detectada durante el repaso:** `MEJORAS_RECOMENDADAS.md` P-10 (borrado sin
desaprovisionamiento) y P-11 (`$monthlyRevenue` muerto).

### 11.1 El manual dentro de la app (Centro de Ayuda)

`MANUAL_USUARIO.md` no es lo que lee el usuario final: lo que ve en la app es el **Centro de
Ayuda** (`pages/Manual.vue`), que renderiza `help_categories` / `help_articles` sembrados por
`HelpCenterSeeder`. Los dos venían divergiendo por separado, así que el seeder se reescribió
para que sea el espejo del manual corregido.

| | Antes | Después |
|---|---:|---:|
| Categorías | 9 | **11** |
| Artículos | 30 | **41** |

**Categorías nuevas:** *Corte y Reconexión* (el diagnóstico de "aparece cortado pero navega",
qué ve el cliente cortado, y las bitácoras) y *Prospectos e Instalaciones*, que no existía en
la app pese a ser un módulo completo.

**Artículos nuevos:** estados del cliente, eliminar un cliente (con la advertencia de que no lo
saca del router), abonos parciales, clientes que no se facturan, el método de control del
router, herramientas de diagnóstico, la VPN, falla masiva, historial de tráfico, plantillas de
documentos, y aprovisionamiento masivo.

**Errores que tenía la versión en la app y que el `.md` ya no tenía** (además de todos los de
la tabla anterior, que también estaban replicados aquí):

| Decía el Centro de Ayuda | Realidad |
|---|---|
| «La suspensión automática corre una vez al día» | Corre **cada hora** (`billing:auto-cut`) |
| «Días de gracia» como campo del router | No existe: son día de vencimiento, día de corte y N facturas vencidas |
| «IP: déjala en blanco para asignación automática» | No hay asignación automática. El formulario carga los rangos del router y marca libres/ocupadas para que el operador **elija** |
| Estados del cliente: activo / suspendido / **inactivo** | Son `activo`, `gratis`, `suspendido`, `retirado`, `cancelado` |
| Estados de factura: pendiente / pagada / vencida | Faltaban **borrador**, **parcial** y **anulada** |
| «Generar script VPN: configura el túnel L2TP/IPSec» | Transporte dual desde julio: WireGuard en v7, L2TP sólo en v6 |
| Acciones del router: «Sincronizar colas», «Asignar IP libre» | No son botones reales; los de verdad son los 8 de diagnóstico |
| «Recordatorios: indica los días antes del vencimiento» | Se configuran por **día y hora del mes** en el router, no por días de antelación |
| «El nombre del plan debe coincidir con el perfil en MikroTik» | No es requisito del aprovisionamiento |
| Google Maps en «Configuración → Tenant» | Es **Configuración → Mapas** |

**Verificación:** estructura y HTML validados artículo por artículo, y el seeder se ejecutó
contra `ispwatch_dev` (11 categorías / 41 artículos). **`public` no se tocó** — ver la nota de
despliegue en `MEJORAS_RECOMENDADAS.md` P-12: `migrate:both --seed` omite `public` a propósito,
así que publicar contenido del Centro de Ayuda no tiene hoy un camino sancionado.

---

## 12. Consecutivo de contratos — 2026-08-04

### Qué se pidió

Que **todo contrato firmado lleve un número consecutivo**. Hasta esta fecha no existía nada:
`customer_documents` no tenía columna de número, el archivo se llamaba
`contrato_firmado_{Ymd_His}.pdf` (marca de tiempo, no consecutivo), el PDF no lo imprimía en
ninguna parte, y `config/document_placeholders.php` no exponía ningún token para insertarlo
desde una plantilla editable. La única secuencia del sistema era la de facturas.

### Qué se hizo

| Pieza | Archivo |
|---|---|
| Columnas `tenant.contract_prefix` / `tenant.next_contract_number` y `customer_documents.contract_number` + **UK** `(tenant_id, contract_number)` | `2026_08_04_120000_add_contract_numbering.php` |
| Numeración retroactiva de los contratos ya firmados | `2026_08_04_120100_backfill_contract_numbers.php` |
| Reserva del número | `App\Services\ContractNumberService` |
| Asignación al firmar + nombre del archivo | `CustomerDocumentController::signContract()` |
| Placeholder `{{contrato.numero}}` | `config/document_placeholders.php`, `PlaceholderResolver::forContract()` |
| Impresión en el PDF (ruta legacy y shell de plantilla) | `documents/contract_pdf.blade.php`, `documents/shells/contract_shell.blade.php` |
| Prefijo configurable | `UpdateTenantRequest`, `DocumentTemplatesSection.vue` |
| Número visible en la ficha y antes de firmar | `CustomerDocuments.vue` |

El formato es `PREFIJO-00001`; el prefijo sale de `tenant.contract_prefix` y cae a `CTR` si
está vacío.

### Decisiones y su porqué

| Decisión | Justificación |
|---|---|
| El número se reserva **antes** de renderizar | Va impreso en el encabezado del PDF; no se puede asignar después de generar el archivo. Un render fallido quema el número: un hueco en la secuencia es preferible a dos contratos con el mismo |
| La vista previa (`contract-data` y el preview de plantillas) **no** consume secuencia | Previsualizar no es firmar. Ambas rutas usan el helper estático `format()` sobre el contador actual, nunca `allocate()` |
| Los PDF **subidos a mano** (`signed = false`) no reciben número | No se puede sellar por dentro un archivo que el sistema no generó; un consecutivo que no aparece en el papel prometería una trazabilidad inexistente |
| El backfill numera por **orden cronológico de firma**, por tenant | El consecutivo refleja el orden real en que se celebraron los contratos. El PDF viejo no se regenera —ya está firmado— así que en los históricos el número existe sólo en la ficha |
| La lógica de formato está **duplicada** entre el servicio y la migración de backfill | Una migración tiene que poder re-ejecutarse aunque la clase de servicio cambie o desaparezca |
| ~~El prefijo se valida con `regex:/^[A-Za-z0-9\-]+$/`~~ · **Revertido el 2026-08-05**: el prefijo es texto libre | La restricción existía para proteger la clave de S3, pero le quitaba al ISP formatos legítimos (`CNO/`, `Contrato N° `). Se separaron las responsabilidades: `format()` respeta lo que el ISP escribió, `fileName()` sanea aparte lo que va al bucket. Ver §13 |

### Efecto colateral corregido: la hoja de instalación firmada no se veía

Mientras se revisaba el flujo de documentos apareció un síntoma reportado desde hacía tiempo
—«al firmar, los documentos no quedan cargados»— que ya **no** era el bug de almacenamiento
resuelto en `828865c` (paso del disco `public` efímero a S3), sino un filtro del frontend:
`InstallationDetail.vue` construía la lista de `photos` con
`documents.filter(d => /\.(jpe?g|png|webp)$/.test(d.file_name))`, así que la hoja de
instalación en PDF —generada correctamente y guardada en S3— quedaba fuera de la única lista
que esa pantalla pintaba. El PDF sólo era alcanzable desde la pestaña **Documentos** del
cliente, y en una instalación de prospecto sin convertir (con `customer_id = NULL`) no era
alcanzable desde ninguna parte hasta la conversión.

Se añadió el bloque **Documentos de la orden**, que lista todo lo que no es imagen con enlace
directo al PDF.

### El manual dentro de la app

`MANUAL_USUARIO.md` y el Centro de Ayuda son **dos fuentes distintas**: `Manual.vue` lee
`help_categories`/`help_articles` vía `api.helpCenter`, no el markdown. Se actualizaron los
tres artículos afectados en `HelpCenterSeeder` — *Editar y gestionar un cliente* (firma con
consecutivo), *Agendar y ejecutar una instalación* (el PDF firmado ya visible en la orden) y
*Plantillas de documentos* (campo de prefijo). Siguen siendo 41 artículos.

Publicarlos en producción sigue topando con **P-12**: `migrate:both --seed` omite `public` a
propósito y el seeder es un reemplazo total (`delete()` antes de sembrar), así que hay que
correr `db:seed --class=HelpCenterSeeder` a mano contra `public` asumiendo que borra lo que
alguien hubiera editado desde la UI.

### Deuda que queda

Los documentos subidos **antes** del paso a S3 (29-jul-2026) pueden estar perdidos: vivían en
el disco efímero del contenedor y `documents:migrate-to-s3` sólo sirve ejecutado desde la
misma instancia que los recibió. Las filas sobreviven y la interfaz las pinta con un enlace
roto, sin distinguirlas de las buenas — anotado en `MEJORAS_RECOMENDADAS.md`.

---

## 13. El prefijo del consecutivo pasa a ser texto libre — 2026-08-05

### Qué estaba mal

La primera versión validaba el prefijo con `regex:/^[A-Za-z0-9\-]+$/` y volvía a saltear
cualquier otro carácter en `format()`. La justificación era real —el prefijo acababa dentro
del nombre del archivo y de la clave de S3, donde una `/` crea una carpeta fantasma— pero la
solución era la equivocada: **se le quitó al ISP el control sobre su propio documento para
resolver un problema del sistema de archivos.** Formatos perfectamente legítimos y de uso
corriente en Colombia (`CNO/`, `Contrato N° `, `FIBRA_2026.`) quedaban prohibidos.

### Qué se hizo

Separar las dos responsabilidades que estaban mezcladas en un solo valor:

| | Quién manda | Función |
|---|---|---|
| Número impreso y guardado en `contract_number` | El ISP, tal cual lo escribió | `format()` |
| Nombre del archivo en S3 | El sistema, saneado | `fileName()` |

La validación ahora sólo rechaza caracteres de control (`regex:/^[^\p{C}]*$/u`), que nunca son
intencionales y romperían el PDF.

### Decisiones y su porqué

| Decisión | Justificación |
|---|---|
| El separador `-` se añade sólo si el prefijo termina en letra o dígito (`preg_match('/[\p{L}\p{N}]$/u')`) | Quien escribe `CNO/` ya eligió su separador; `CNO/-00001` sería un error del sistema, no del usuario |
| `contract_prefix` exceptuado de `TrimStrings` en `bootstrap/app.php` | El espacio final de `Contrato N° ` **es** el separador. El middleware global se lo comía y el número salía `Contrato N°00012`. Se detectó porque el test de extremo a extremo del prefijo libre falló con exactamente esa diferencia |
| `fileName()` usa `Str::ascii()`, no `transliterator_transliterate()` ni `iconv` | Con `iconv` (única vía disponible en este entorno, sin `intl`) `CÓRDOBA` salía `C-ORDOBA`: transliteraba a `'O` y la comilla se volvía guion. Peor aún, el resultado **dependía del entorno**, así que el mismo contrato tendría distinto nombre en el Windows del desarrollador que en el Linux del contenedor. `Str::ascii()` lleva su propio mapa de caracteres y es determinista |
| El nombre del archivo conserva siempre la parte numérica | Dos prefijos distintos pueden sanearse al mismo texto; el consecutivo es lo que garantiza que no colisionen dentro de la carpeta del cliente |
| Ningún cambio de esquema ni migración | `contract_prefix` ya era `varchar(20)`; sólo cambia qué se acepta dentro. Los contratos ya numerados no se tocan |

### Lo que no cambia

Los contratos ya firmados conservan su número. Cambiar el prefijo no renumera nada: la
secuencia sigue desde donde iba y sólo los contratos nuevos salen con el formato nuevo.

---

## 14. Vista previa de la hoja de instalación y firma que no se dibujaba — 2026-08-05

Dos problemas reportados juntos desde el detalle de instalación: no había forma de mostrarle
al cliente qué estaba firmando, y la firma **no se veía al trazarla ni quedaba guardada**.

### 14.1 Vista previa antes de firmar

`POST /api/installations/{installation}/sheet-preview` devuelve **el mismo PDF que genera
`/sign`**, con las firmas vacías, sin crear `customer_documents` y sin cerrar la orden.

`CustomerInstallationController::renderSheetPdf()` se partió en dos: `buildSheetPdf()` arma el
PDF (sin persistir) y `renderSheetPdf()` lo guarda como documento. La vista previa usa la
primera; la firma sigue usando la segunda. Así el documento que lee el cliente y el que se
archiva salen literalmente del mismo código, incluida la plantilla del tenant si la tiene.

| Decisión | Justificación |
|---|---|
| El body acepta `sheet` (los mismos campos que `PUT .../sheet`) y se mezcla **en memoria** | El técnico previsualiza con lo que acaba de escribir aunque no haya pulsado *Guardar hoja*. Una vista previa que muestre datos viejos no sirve para el propósito: que el cliente lea lo que firma |
| Las reglas de validación se extrajeron a `sheetValidationRules($presence)` | Guardar y previsualizar deben aceptar exactamente lo mismo; duplicarlas garantizaba que se separaran con el tiempo |
| Sin marca de agua "VISTA PREVIA" | Habría que atravesar todo el pipeline de plantillas con un flag. El PDF sale sin firmas —se distingue solo— y nunca se guarda |
| Permiso `view_support`, igual que `/sign` | Quien puede firmar la orden puede verla antes |

En el frontend el PDF se abre en un modal con `<iframe>` sobre un blob URL, con enlace
*Abrir en pestaña* como alternativa (los iframes con PDF no siempre renderizan en móviles).
El blob se revoca al cerrar y en `onBeforeUnmount`.

### 14.2 La firma no se veía ni se guardaba — causa raíz

El contexto 2D de cada canvas se cacheaba en dos variables sueltas del `<script setup>`
(`ctxCust` / `ctxTech`). El bloque completo de la orden vive dentro de un `v-if="loading"` /
`v-else`, así que **cada recarga desmonta y vuelve a montar los canvas**. Tras cualquier
recarga —subir fotos era el caso típico— la variable seguía apuntando al canvas viejo, ya
desconectado del DOM:

1. El trazo se dibujaba en un canvas fuera de pantalla → *no se ve la firma*.
2. `canvasCustomer.value.toDataURL()` leía el canvas nuevo, en blanco → *se guardaba un PNG
   transparente*, y el backend lo aceptaba porque es un PNG base64 válido.

Los tres arreglos, en orden de importancia:

| Arreglo | Qué resuelve |
|---|---|
| El contexto se cachea en un `WeakMap` **por elemento canvas** | Un canvas nuevo obtiene un contexto nuevo. La caché no puede volverse obsoleta |
| `loadInstallation({ silent: true })` en los refrescos (subida de fotos) | El spinner de pantalla completa ya no desmonta el bloque, así que una firma en curso sobrevive al refresco. En modo silencioso un error tampoco vacía `installation` |
| `canvasHasInk(canvas)` (barrido del canal alfa) decide si hay firma, en vez de la bandera reactiva | La bandera mentía cuando el canvas se re-montaba. Ahora es imposible cerrar una orden con una firma en blanco |

### 14.3 El visor salía en gris: `frame-src` faltaba en la CSP

Ya en producción, el modal abría pero el PDF no se pintaba (recuadro gris con el icono de
documento roto). No era el PDF: la CSP de `SecurityHeaders` **no declaraba `frame-src`**, así
que heredaba `default-src 'self' {origen}` y el navegador **rechazaba** el
`<iframe src="blob:…">`. Añadido `frame-src 'self' blob:` en las dos ramas (local y
producción).

`blob:` sólo habilita documentos generados por la propia página —quien pueda crear uno ya
ejecuta script— y el blob hereda la CSP del contexto que lo creó, así que no abre una vía de
escape. **No** se añadió `data:`, que sí es un vector clásico de XSS.

Lo importante del fallo no es la directiva sino cómo se manifestó: **una regresión de CSP no
rompe nada en el servidor**, falla en el navegador del usuario y no deja rastro en los logs de
la aplicación. Por eso ahora hay `tests/Feature/SecurityHeadersTest.php`, que fija
`frame-src 'self' blob:`, prohíbe `data:` en esa directiva y vuelve a comprobar las tres
restricciones de la auditoría de 2026-07-30 (`object-src 'none'`, sin `unsafe-eval`, sin
`unsafe-inline` en `script-src`).

Como segunda red, `previewSheet()` comprueba que la respuesta empiece por `%PDF-` antes de
abrir el modal: si el gateway devuelve 200 con HTML propio, sale un aviso claro en vez de un
visor gris. El modal ofrece además **Abrir en pestaña** y **Descargar** para los navegadores
móviles que no pintan PDFs dentro de un iframe.

> **Despliegue:** este arreglo es de **backend** (cabecera HTTP). Desplegar sólo el frontend
> deja el visor exactamente igual de gris.

### 14.4 Fuera las fotos del PDF, y un solo documento firmado por tipo

Dos cosas que salieron al usarlo de verdad en producción.

**Las fotos no van en la hoja.** El PDF traía una sección *Fotos de la Instalación* con
recuadros de imagen rota y el nombre del archivo debajo. Nunca funcionó: se referenciaban
como `public_path('storage/'.$file_path)` mientras que se almacenan en **S3**, así que dompdf
no encontraba nada. Además son redundantes — las fotos se consultan en los documentos del
cliente, que es su sitio. Retirado de la vista legacy, del shell y como bloque:

| Se eliminó | Consecuencia |
|---|---|
| Sección de fotos en `installation_sheet_pdf.blade.php` y `shells/installation_shell.blade.php` | La hoja ya no intenta pintar imágenes que no puede resolver |
| Bloque `{{instalacion.fotos}}` (config, resolver y su partial) | Una plantilla que todavía lo use se blanquea, como cualquier token desconocido — nunca deja el marcador ni el texto crudo a la vista (fijado en `TemplateRendererBlockPlaceholdersTest`) |
| Parámetro `$photos` en `renderInstallationSheet`/`previewInstallationSheet`/`forInstallation` | Ya no lo consume nadie; se quitó de la firma en vez de dejarlo muerto |

Si algún día se quieren fotos dentro del PDF, hay que **incrustarlas desde S3 como data URI**
— no basta con volver a poner el `<img>`, que es exactamente el error que se retira aquí.

**Un solo documento firmado por tipo.** Firmar dos veces dejaba dos PDF casi idénticos entre
los documentos del cliente, sin forma de saber cuál vale. Ahora:

- `sign()` (hoja de instalación) devuelve **409** si la orden ya tiene su hoja firmada.
- `signContract()` devuelve **409** si el cliente ya tiene contrato firmado — comprobado
  **antes** de `allocate()`, porque rechazar después gastaría un número de la secuencia en un
  contrato que nunca se genera (fijado con un test que compara `next_contract_number`).
- El bloqueo mira los **documentos**, no una marca en la orden: borrar el anterior habilita
  volver a firmar, que es justamente el flujo pedido. Las fotos (`signed = false`) no cuentan.
- En la interfaz, mientras exista el documento firmado la zona de firma se sustituye por el
  aviso de eliminar el anterior, y **Documentos de la orden** estrenó botón *Eliminar*: sin
  él no había forma de rehacer una hoja mal firmada en una orden de prospecto.

`CustomerDocuments.vue` tenía el **mismo bug de contexto de canvas** que 14.2, latente hasta
que la sección de firma pasó a montarse y desmontarse; se le aplicó el mismo arreglo
(`WeakMap` por elemento + `canvasHasInk()`).

### 14.5 Efecto colateral en las pruebas

La migración `2026_05_27_223001_link_installations_to_prospects` dejaba `customer_id` como
`NOT NULL` en sqlite (sólo pgsql/mysql aplicaban el `DROP NOT NULL`), con un comentario que
lo daba por aceptable. No lo era: hacía **imposible representar una orden de prospecto en los
tests**, justo el caso que motivó la vista previa. Con Laravel 11+ `change()` ya no necesita
doctrine/dbal, así que la rama sqlite ahora hace lo mismo que las demás. La suite completa
(398 pruebas) sigue en verde.
