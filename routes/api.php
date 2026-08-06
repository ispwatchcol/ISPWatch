<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerProfileController;
use App\Http\Controllers\CustomerDocumentController;
use App\Http\Controllers\CustomerInstallationController;
use App\Http\Controllers\ProspectController;
use App\Http\Controllers\RouterController;
use App\Http\Controllers\RouterOutageController;
use App\Http\Controllers\InventoryDeviceController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SectorialController;
use App\Http\Controllers\SectorialPhotoController;
use App\Http\Controllers\SectorialNoteController;
use App\Http\Controllers\SectorialHistoryController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BillingActionLogController;
use App\Http\Controllers\SuspensionActionLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\VerificationController;
use App\Http\Controllers\PaymentReminderController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\InvoiceTypeController;
use App\Http\Controllers\AdditionalServiceController;
use App\Http\Controllers\CustomerAdditionalServiceController;
use App\Http\Controllers\HelpCenterController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\InventoryStockController;
use App\Http\Controllers\InventoryProviderController;
use App\Http\Controllers\InventoryBranchController;
use App\Http\Controllers\InventoryMovementController;
use App\Http\Controllers\InstallationEquipmentController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\DocumentTemplateController;

/*
|--------------------------------------------------------------------------
| PUBLIC ROUTES (No authentication required)
|--------------------------------------------------------------------------
*/
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [RegistrationController::class, 'register']);
Route::post('/register/send-code', [RegistrationController::class, 'sendVerificationCode']);

// Email Verification
Route::get('/verify-email/{id}/{hash}', [VerificationController::class, 'verify'])
    ->middleware(['signed'])
    ->name('verification.verify');
Route::post('/verify-email/resend', [VerificationController::class, 'resend'])
    ->name('verification.resend');

/*
|--------------------------------------------------------------------------
| PROTECTED ROUTES (require auth:sanctum)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->group(function () {

    // ─── AUTH ───
    Route::get('/auth/me', [AuthController::class, 'me']);

    // ─── DASHBOARD ───
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

    // ─── ROLE CATALOG (read-only, any authenticated user) ───
    // SECURITY FIX (OWASP A01): role names + their permission sets must not
    // be exposed unauthenticated. Read stays open to any logged-in user
    // (needed for staff/role dropdowns); create/update/delete remain gated
    // by permission:manage_roles via the apiResource below.
    Route::get('/roles', [RoleController::class, 'index']);

    // ─── CUSTOMERS (custom routes before apiResource) ───
    Route::get('/customers/statistics', [CustomerProfileController::class, 'statistics'])
        ->middleware('permission:view_clients');
    Route::get('/customers/map', [CustomerProfileController::class, 'mapData'])
        ->middleware('permission:view_clients');
    Route::get('/customers/used-ips', [CustomerProfileController::class, 'usedIps'])
        ->middleware('permission:view_clients,add_clients');
    // Sólo calcula (no escribe): qué se le cobraría al cliente en sus primeros
    // meses. Lo usa el formulario de alta/edición para mostrar el prorrateo y
    // los meses de cortesía antes de guardar.
    Route::post('/customers/first-invoice-preview', [CustomerProfileController::class, 'firstInvoicePreview'])
        ->middleware('permission:view_clients,add_clients');
    Route::post('/customers/{id}/provision', [CustomerProfileController::class, 'provision'])
        ->middleware(['permission:activate_deactivate_clients', 'throttle:router-ops']);
    Route::post('/customers/bulk-provision', [CustomerProfileController::class, 'bulkProvision'])
        ->middleware(['permission:activate_deactivate_clients', 'throttle:bulk-ops']);
    // Aprovisionamiento masivo asíncrono (job en cola + polling de progreso).
    Route::post('/customers/bulk-provision-async', [CustomerProfileController::class, 'bulkProvisionAsync'])
        ->middleware(['permission:activate_deactivate_clients', 'throttle:bulk-ops']);
    Route::get('/customers/bulk-provision-status/{jobId}', [CustomerProfileController::class, 'bulkProvisionStatus'])
        ->middleware('permission:activate_deactivate_clients');
    Route::post('/customers/{id}/suspend', [CustomerProfileController::class, 'suspend'])
        ->middleware(['permission:activate_deactivate_clients', 'throttle:router-ops']);
    Route::post('/customers/{id}/activate', [CustomerProfileController::class, 'activate'])
        ->middleware(['permission:activate_deactivate_clients', 'throttle:router-ops']);

    // ─── CUSTOMER INSTALLATIONS ───
    // Instalaciones no tiene permiso propio: viaja con view_support (así lo
    // refleja también el Sidebar). Las rutas colgadas de /customers/{customer}
    // se abren además a view_clients porque la ficha del cliente las consulta
    // desde su pestaña "Instalaciones".
    Route::get('/installations', [CustomerInstallationController::class, 'all'])
        ->middleware('permission:view_support');
    Route::post('/installations', [CustomerInstallationController::class, 'createWithProspect'])
        ->middleware('permission:view_support');
    Route::get('/installations/technicians', [CustomerInstallationController::class, 'technicians'])
        ->middleware('permission:view_support');
    Route::get('/installations/customers', [CustomerInstallationController::class, 'customersForInstallation'])
        ->middleware('permission:view_support');
    Route::get('/installations/{installation}', [CustomerInstallationController::class, 'show'])
        ->middleware('permission:view_support,view_clients');
    Route::put('/installations/{installation}/prospect', [CustomerInstallationController::class, 'updateProspect'])
        ->middleware('permission:view_support');
    Route::get('/customers/{customer}/installations', [CustomerInstallationController::class, 'index'])
        ->middleware('permission:view_support,view_clients');
    Route::post('/customers/{customer}/installations', [CustomerInstallationController::class, 'store'])
        ->middleware('permission:view_support,add_clients');
    Route::put('/customers/installations/{installation}', [CustomerInstallationController::class, 'update'])
        ->middleware('permission:view_support');
    Route::delete('/customers/installations/{installation}', [CustomerInstallationController::class, 'destroy'])
        ->middleware('permission:delete_installations,view_support');
    Route::put('/installations/{installation}/billing', [CustomerInstallationController::class, 'updateBilling'])
        ->middleware('permission:edit_discount');
    Route::put('/installations/{installation}/sheet', [CustomerInstallationController::class, 'saveSheet'])
        ->middleware('permission:view_support');
    Route::post('/installations/{installation}/photos', [CustomerInstallationController::class, 'uploadPhotos'])
        ->middleware('permission:view_support');
    Route::post('/installations/{installation}/sheet-preview', [CustomerInstallationController::class, 'previewSheet'])
        ->middleware('permission:view_support');
    Route::post('/installations/{installation}/sign', [CustomerInstallationController::class, 'sign'])
        ->middleware('permission:view_support');

    // Equipos y materiales de la orden. Viajan con view_support como el resto
    // de la hoja: quien puede llenar la hoja es quien descarga el inventario.
    Route::get('/installations/{installation}/equipment', [InstallationEquipmentController::class, 'index'])
        ->middleware('permission:view_support,view_clients');
    Route::get('/installations/{installation}/equipment/available', [InstallationEquipmentController::class, 'available'])
        ->middleware('permission:view_support');
    Route::post('/installations/{installation}/equipment', [InstallationEquipmentController::class, 'store'])
        ->middleware('permission:view_support');
    Route::delete('/installations/{installation}/equipment/{item}', [InstallationEquipmentController::class, 'destroy'])
        ->middleware('permission:view_support');

    // ─── PROSPECTS ───
    // El alta de cliente lee el prospecto y lo marca como convertido, así que
    // add_clients también entra además de view_support.
    Route::middleware('permission:view_support,view_clients,add_clients')->group(function () {
        Route::get('/prospects', [ProspectController::class, 'index']);
        Route::get('/prospects/{prospect}', [ProspectController::class, 'show']);
    });
    Route::middleware('permission:view_support,add_clients')->group(function () {
        Route::post('/prospects', [ProspectController::class, 'store']);
        Route::put('/prospects/{prospect}', [ProspectController::class, 'update']);
        Route::patch('/prospects/{prospect}', [ProspectController::class, 'update']);
        Route::post('/prospects/{prospect}/mark-converted', [ProspectController::class, 'markConverted']);
        Route::post('/prospects/{prospect}/installations', [CustomerInstallationController::class, 'storeForProspect']);
    });
    Route::delete('/prospects/{prospect}', [ProspectController::class, 'destroy'])
        ->middleware('permission:view_support');

    // ─── CUSTOMER DOCUMENTS & CONTRACT ───
    Route::get('/customers/{customer}/documents', [CustomerDocumentController::class, 'index'])
        ->middleware('permission:view_clients');
    Route::post('/customers/{customer}/documents', [CustomerDocumentController::class, 'store'])
        ->middleware('permission:edit_internet_service,add_clients,view_support');
    Route::delete('/customers/documents/{document}', [CustomerDocumentController::class, 'destroy'])
        ->middleware('permission:edit_internet_service');
    Route::get('/customers/{customer}/contract-data', [CustomerDocumentController::class, 'contractData'])
        ->middleware('permission:view_clients');
    Route::post('/customers/{customer}/contract-sign', [CustomerDocumentController::class, 'signContract'])
        ->middleware('permission:edit_internet_service,add_clients');

    // ─── ROUTER MANAGEMENT ───
    // free-ips lo consulta el formulario de alta de cliente, así que add_clients
    // también entra. El resto son operaciones de infraestructura: manage_routers.
    Route::get('/routers/{router}/free-ips', [RouterController::class, 'getFreeIps'])
        ->middleware('permission:manage_routers,add_clients,view_clients');
    Route::get('/routers/{router}/vpn-script', [RouterController::class, 'generateVpnScript'])
        ->middleware('permission:manage_routers');
    Route::post('/routers/{router}/verify-vpn', [RouterController::class, 'verifyVpnConnection'])
        ->middleware(['permission:manage_routers', 'throttle:router-ops']);
    Route::get('/routers/{router}/interfaces', [RouterController::class, 'getInterfaces'])
        ->middleware('permission:manage_routers');
    Route::get('/routers/{router}/traffic', [RouterController::class, 'trafficHistory'])
        ->middleware('permission:manage_routers,view_client_traffic');
    Route::post('/routers/{router}/set-wan-interface', [RouterController::class, 'setWanInterface'])
        ->middleware('permission:manage_routers');
    Route::post('/routers/{router}/apply-block-rules', [RouterController::class, 'applyBlockRules'])
        ->middleware(['permission:activate_deactivate_clients', 'throttle:router-ops']);
    Route::get('/routers/{router}/verify-block-rules', [RouterController::class, 'verifyBlockRules'])
        ->middleware('permission:manage_routers,activate_deactivate_clients');
    Route::get('/routers/{router}/test-ssh-connection', [RouterController::class, 'testClientSshConnection'])
        ->middleware(['permission:manage_routers', 'throttle:router-ops']);
    Route::get('/routers/test-core-connection', [RouterController::class, 'testCoreConnection'])
        ->middleware(['permission:manage_routers', 'throttle:router-ops']);
    Route::post('/routers/{router}/test-secret-sync', [RouterController::class, 'testSecretSync'])
        ->middleware('permission:manage_routers');
    // SECURITY FIX (OWASP A01): the GET variant must require the same
    // permission as POST — otherwise it is a method-based authz bypass.
    Route::get('/routers/{router}/test-secret-sync', [RouterController::class, 'testSecretSync'])
        ->middleware('permission:manage_routers');
    Route::get('/routers/{router}/test-queue-sync', [RouterController::class, 'testQueueSync'])
        ->middleware('permission:manage_routers');

    // ─── FALLA MASIVA (mass outage broadcast) ───
    // Records the outage/recovery signal; Converza polls it read-only and sends.
    Route::get('/routers/{router}/outage', [RouterOutageController::class, 'show']);
    Route::post('/routers/{router}/outage/notify', [RouterOutageController::class, 'notify'])
        ->middleware('permission:manage_routers');
    Route::post('/routers/{router}/outage/resolve', [RouterOutageController::class, 'resolve'])
        ->middleware('permission:manage_routers');

    // Plan → RB engine sync (per control method)
    Route::middleware('permission:view_plans,manage_routers')->group(function () {
        Route::post('/plans/{plan}/sync-pppoe-profile', [PlanController::class, 'syncPppoeProfile']);
        Route::post('/plans/{plan}/sync-hotspot-profile', [PlanController::class, 'syncHotspotProfile']);
        Route::post('/plans/{plan}/sync-pcq-engine', [PlanController::class, 'syncPcqEngine']);
    });

    // ─── BILLING ───
    Route::middleware(['permission:view_billing'])->group(function () {
        Route::get('/billing/stats', [BillingController::class, 'getStats']);
        Route::get('/billing/invoices', [BillingController::class, 'index']);
        // OJO: antes de `/billing/invoices/{id}`, o "export" se interpreta como un id.
        Route::get('/billing/invoices/export', [BillingController::class, 'exportInvoices']);
        Route::get('/billing/invoices/{id}', [BillingController::class, 'show']);
        Route::post('/billing/invoices', [BillingController::class, 'store']);
        Route::put('/billing/invoices/{id}', [BillingController::class, 'update']);
        Route::post('/billing/invoices/{id}/mark-unpaid', [BillingController::class, 'markUnpaid']);
        Route::delete('/billing/invoices/{id}', [BillingController::class, 'destroy'])
            ->middleware('permission:delete_invoice');
        Route::post('/billing/invoices/{id}/items', [BillingController::class, 'addItems']);
        Route::get('/billing/invoices/{id}/pdf', [BillingController::class, 'downloadPdf']);
        Route::get('/billing/payments', [BillingController::class, 'getPayments']);
        Route::get('/billing/payments/export', [BillingController::class, 'exportPayments']);
        Route::post('/billing/payments', [BillingController::class, 'registerPayment']);
        Route::put('/billing/payments/{id}', [BillingController::class, 'updatePayment']);
        Route::delete('/billing/payments/{id}', [BillingController::class, 'deletePayment']);
        Route::get('/billing/customers/{customerId}/balance', [BillingController::class, 'getCustomerBalance']);
        Route::patch('/billing/customers/{customerId}/credit', [BillingController::class, 'updateCreditBalance']);
        Route::post('/billing/run-monthly', [BillingController::class, 'runMonthlyGeneration']);
        Route::post('/billing/run-overdue', [BillingController::class, 'processOverdue']);
        Route::get('/billing/configs', [BillingController::class, 'getBillingConfigs']);
        Route::put('/billing/configs/{id}', [BillingController::class, 'updateBillingConfig']);
        Route::post('/billing/run-auto-cut', [BillingController::class, 'runAutoCut']);

        // Additional charges (cargo adicional sin ticket)
        Route::post('/billing/additional-charges', [BillingController::class, 'storeAdditionalCharge']);

        // Payment Reminders
        Route::post('/billing/invoices/{id}/send-reminder', [PaymentReminderController::class, 'sendReminder']);
        Route::post('/billing/invoices/bulk-reminders', [PaymentReminderController::class, 'sendBulkReminders']);
        Route::get('/billing/whatsapp-status', [PaymentReminderController::class, 'checkWhatsAppStatus']);

        // Payment Methods (formas de pago)
        Route::get('/billing/payment-methods', [PaymentMethodController::class, 'index']);
        Route::post('/billing/payment-methods', [PaymentMethodController::class, 'store']);
        Route::put('/billing/payment-methods/{id}', [PaymentMethodController::class, 'update']);
        Route::delete('/billing/payment-methods/{id}', [PaymentMethodController::class, 'destroy']);

        // Tipos de factura (catálogo: equipos, TV, reconexión...). Va con
        // view_billing igual que las formas de pago: un permiso nuevo dejaría a
        // los roles admin existentes sin la pestaña hasta re-sembrarlos.
        Route::get('/billing/invoice-types', [InvoiceTypeController::class, 'index']);
        Route::post('/billing/invoice-types', [InvoiceTypeController::class, 'store']);
        Route::put('/billing/invoice-types/{id}', [InvoiceTypeController::class, 'update']);
        Route::delete('/billing/invoice-types/{id}', [InvoiceTypeController::class, 'destroy']);

        // Catálogo de servicios adicionales recurrentes. Mismo permiso que las
        // dos listas de arriba y por la misma razón.
        // OJO: antes de `/billing/additional-services/{id}`, o 'unbilled' se
        // interpretaría como un id si algún día se añade un GET show.
        Route::get('/billing/additional-services/unbilled', [AdditionalServiceController::class, 'unbilled']);
        Route::get('/billing/additional-services', [AdditionalServiceController::class, 'index']);
        Route::post('/billing/additional-services', [AdditionalServiceController::class, 'store']);
        Route::put('/billing/additional-services/{id}', [AdditionalServiceController::class, 'update']);
        Route::delete('/billing/additional-services/{id}', [AdditionalServiceController::class, 'destroy']);

        // Asignaciones por cliente. Anidadas bajo el cliente en las cuatro:
        // así el ámbito viaja siempre en la URL y no hay forma de tocar la
        // asignación de otro cliente pasando sólo su id.
        Route::get('/billing/customers/{customer}/additional-services', [CustomerAdditionalServiceController::class, 'index']);
        Route::post('/billing/customers/{customer}/additional-services', [CustomerAdditionalServiceController::class, 'store']);
        Route::put('/billing/customers/{customer}/additional-services/{id}', [CustomerAdditionalServiceController::class, 'update']);
        Route::delete('/billing/customers/{customer}/additional-services/{id}', [CustomerAdditionalServiceController::class, 'destroy']);
    });

    // ─── BILLING ACTION LOGS (failover de facturación) ───
    Route::middleware(['permission:execute_mass_actions'])->group(function () {
        Route::get('/billing/action-logs',             [BillingActionLogController::class, 'index']);
        Route::get('/billing/action-logs/stats',       [BillingActionLogController::class, 'stats']);
        Route::post('/billing/action-logs/{id}/retry', [BillingActionLogController::class, 'retry']);
        Route::post('/billing/action-logs/retry-all',  [BillingActionLogController::class, 'retryAll']);

        // ─── SUSPENSION ACTION LOGS (failover de cortes / sincronización RB) ───
        Route::get('/billing/suspension-logs',             [SuspensionActionLogController::class, 'index']);
        Route::get('/billing/suspension-logs/stats',       [SuspensionActionLogController::class, 'stats']);
        Route::post('/billing/suspension-logs/{id}/retry', [SuspensionActionLogController::class, 'retry']);
        Route::post('/billing/suspension-logs/reconcile',  [SuspensionActionLogController::class, 'reconcile']);
    });

    // ─── SUPPORT (requires staff profile) ───
    Route::middleware(['staff_profile'])->group(function () {
        Route::get('/support/statistics', [SupportTicketController::class, 'statistics']);
        Route::post('/support/{id}/message', [SupportTicketController::class, 'addMessage']);
        Route::put('/support/messages/{id}', [SupportTicketController::class, 'updateMessage']);
        Route::delete('/support/messages/{id}', [SupportTicketController::class, 'deleteMessage']);
        Route::patch('/support/{id}/status', [SupportTicketController::class, 'updateStatus']);
        // Ticket charges
        Route::post('/support/{id}/charge', [SupportTicketController::class, 'generateCharge']);
        Route::get('/support/{id}/charges', [SupportTicketController::class, 'getCharges']);
    });

    // ─── SECTORIAL: photos / notes / history / linked tickets ───
    Route::middleware('permission:view_sectorials')->group(function () {
        Route::post('/sectorials/{sectorial}/photos', [SectorialPhotoController::class, 'store']);
        Route::delete('/sectorials/{sectorial}/photos/{photo}', [SectorialPhotoController::class, 'destroy']);

        Route::post('/sectorials/{sectorial}/notes', [SectorialNoteController::class, 'store']);
        Route::put('/sectorials/{sectorial}/notes/{note}', [SectorialNoteController::class, 'update']);
        Route::delete('/sectorials/{sectorial}/notes/{note}', [SectorialNoteController::class, 'destroy']);
    });

    Route::middleware('permission:view_sectorials,view_support')->group(function () {
        Route::get('/sectorials/{sectorial}/photos',  [SectorialPhotoController::class, 'index']);
        Route::get('/sectorials/{sectorial}/notes',   [SectorialNoteController::class, 'index']);
        Route::get('/sectorials/{sectorial}/history', [SectorialHistoryController::class, 'index']);
        Route::get('/sectorials/{sectorial}/tickets', [SectorialHistoryController::class, 'tickets']);
    });

    /*
    |--------------------------------------------------------------------------
    | CRUD RESOURCES
    |--------------------------------------------------------------------------
    |
    | SECURITY FIX (OWASP A01, 2026-07-30): estos apiResource sólo exigían
    | `auth:sanctum`. El control de acceso real lo hacía la guarda de vue-router,
    | que es puramente cosmética: cualquier usuario autenticado —incluido un rol
    | "Cliente" con permisos vacíos— podía crear, modificar o borrar clientes,
    | routers y planes llamando la API directamente.
    |
    | Los permisos de LECTURA llevan varios valores (semántica OR en
    | CheckPermission) porque son datos de referencia que otras pantallas
    | necesitan: el formulario de alta de cliente carga planes, sectoriales y
    | routers, y el rol Técnico tiene `add_clients` pero no `view_plans`,
    | `view_sectorials` ni `manage_routers`. La ESCRITURA sí exige el permiso
    | dueño del módulo, a secas.
    |
    | Nota: no existe un permiso `delete_clients` en App\Constants\Permissions.
    | El borrado de cliente se apoya en `edit_internet_service` para no inventar
    | un permiso nuevo que ningún rol sembrado tendría (ver MEJORAS_RECOMENDADAS).
    |
    */

    // Clientes
    Route::middleware('permission:view_clients')->group(function () {
        Route::get('/customers', [CustomerProfileController::class, 'index']);
        Route::get('/customers/{customer}', [CustomerProfileController::class, 'show']);
    });
    Route::post('/customers', [CustomerProfileController::class, 'store'])
        ->middleware('permission:add_clients');
    Route::match(['put', 'patch'], '/customers/{customer}', [CustomerProfileController::class, 'update'])
        ->middleware('permission:edit_internet_service');
    Route::delete('/customers/{customer}', [CustomerProfileController::class, 'destroy'])
        ->middleware('permission:edit_internet_service');

    // Routers
    Route::middleware('permission:manage_routers,view_clients,add_clients,view_billing,execute_mass_actions')
        ->group(function () {
            Route::get('/routers', [RouterController::class, 'index']);
            Route::get('/routers/{router}', [RouterController::class, 'show']);
        });
    Route::middleware('permission:manage_routers')->group(function () {
        Route::post('/routers', [RouterController::class, 'store']);
        Route::match(['put', 'patch'], '/routers/{router}', [RouterController::class, 'update']);
        Route::delete('/routers/{router}', [RouterController::class, 'destroy']);
    });

    // Planes
    Route::middleware('permission:view_plans,view_clients,add_clients,view_billing')->group(function () {
        Route::get('/plans', [PlanController::class, 'index']);
        Route::get('/plans/{plan}', [PlanController::class, 'show']);
    });
    Route::middleware('permission:view_plans')->group(function () {
        Route::post('/plans', [PlanController::class, 'store']);
        Route::match(['put', 'patch'], '/plans/{plan}', [PlanController::class, 'update']);
        Route::delete('/plans/{plan}', [PlanController::class, 'destroy']);
    });

    // Sectoriales / planta FTTH
    Route::middleware('permission:view_sectorials,view_clients,add_clients,view_support')->group(function () {
        Route::get('/sectorials', [SectorialController::class, 'index']);
        Route::get('/sectorials/{sectorial}', [SectorialController::class, 'show']);
    });
    Route::middleware('permission:view_sectorials')->group(function () {
        Route::post('/sectorials', [SectorialController::class, 'store']);
        Route::match(['put', 'patch'], '/sectorials/{sectorial}', [SectorialController::class, 'update']);
        Route::delete('/sectorials/{sectorial}', [SectorialController::class, 'destroy']);
    });

    // Soporte
    Route::middleware('permission:view_support')->group(function () {
        Route::apiResource('support', SupportTicketController::class);
    });

    // Inventario. La lectura se abre a view_support porque la pantalla de
    // Instalaciones lista equipos para asignarlos en la visita.
    Route::middleware('permission:view_inventory,view_support')->group(function () {
        Route::get('/inventory', [InventoryDeviceController::class, 'index']);
        Route::get('/inventory-stock', [InventoryStockController::class, 'index']);
        Route::get('/inventory-providers', [InventoryProviderController::class, 'index']);
        Route::get('/inventory-branches', [InventoryBranchController::class, 'index']);
    });
    // El kardex y las entregas son gestión de inventario, no consulta de campo.
    Route::middleware('permission:view_inventory')->group(function () {
        Route::get('/inventory/movements', [InventoryMovementController::class, 'index']);
        Route::get('/inventory/holdings', [InventoryMovementController::class, 'holdings']);
        Route::post('/inventory/transfers', [InventoryMovementController::class, 'store']);
        Route::post('/inventory/{inventory}/retire', [InventoryMovementController::class, 'retire']);
    });
    // Va DESPUÉS de las rutas literales de arriba: registrada antes, /inventory/{inventory}
    // se tragaría /inventory/movements y /inventory/holdings como si fueran ids.
    Route::get('/inventory/{inventory}', [InventoryDeviceController::class, 'show'])
        ->middleware('permission:view_inventory,view_support');
    Route::middleware('permission:view_inventory')->group(function () {
        Route::post('/inventory', [InventoryDeviceController::class, 'store']);
        Route::match(['put', 'patch'], '/inventory/{inventory}', [InventoryDeviceController::class, 'update']);
        Route::delete('/inventory/{inventory}', [InventoryDeviceController::class, 'destroy']);

        Route::apiResource('inventory-stock', InventoryStockController::class)
            ->only(['store', 'update', 'destroy']);
        Route::apiResource('inventory-providers', InventoryProviderController::class)
            ->only(['store', 'update', 'destroy']);
        Route::apiResource('inventory-branches', InventoryBranchController::class)
            ->only(['store', 'update', 'destroy']);
    });

    // ─── STAFF ───
    Route::middleware(['permission:view_staff'])->group(function () {
        Route::apiResource('staff', UserController::class);
    });

    // ─── EXPENSES ─── (Gastos) Tenant-scoped via BelongsToTenant. No hard
    // delete on expenses: they are voided (status=anulado) via update.
    Route::middleware(['permission:view_expenses'])->group(function () {
        Route::get('/expenses', [ExpenseController::class, 'index']);
        Route::get('/expenses/export', [ExpenseController::class, 'exportExpenses']);
        Route::get('/expense-categories', [ExpenseCategoryController::class, 'index']);
    });
    Route::middleware(['permission:add_expense'])->group(function () {
        Route::post('/expenses', [ExpenseController::class, 'store']);
        Route::post('/expense-categories', [ExpenseCategoryController::class, 'store']);
    });
    Route::middleware(['permission:edit_expense'])->group(function () {
        Route::put('/expenses/{expense}', [ExpenseController::class, 'update']);
        Route::put('/expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'update']);
        Route::delete('/expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'destroy']);
    });

    // ─── CATALOGS ───
    // Global reference data (no tenant_id), read-only. Replaces the frontend's
    // former direct Supabase reads of cut_type / script_version / type_billing.
    Route::get('/catalogs/cut-types',       [CatalogController::class, 'cutTypes']);
    Route::get('/catalogs/script-versions', [CatalogController::class, 'scriptVersions']);
    Route::get('/catalogs/type-billings',   [CatalogController::class, 'typeBillings']);
    Route::get('/catalogs/users',           [CatalogController::class, 'users']);

    Route::get('/roles/permissions', [RoleController::class, 'permissions'])
        ->middleware('permission:manage_roles');
    Route::middleware('permission:manage_roles')->group(function () {
        Route::apiResource('roles', RoleController::class);
    });

    // Maps config: readable by any authenticated user so non-admins can view
    // the customer map. Writing the key still goes through the manage_tenant
    // protected /tenants/{id} update below.
    Route::get('/tenant/maps-config', [TenantController::class, 'mapsConfig']);

    Route::get('/tenants/{id}', [TenantController::class, 'show'])
        ->middleware('permission:manage_tenant');
    Route::put('/tenants/{id}', [TenantController::class, 'update'])
        ->middleware('permission:manage_tenant');
    Route::match(['put', 'patch'], '/tenant/config', [TenantController::class, 'updateConfig'])
        ->middleware('permission:manage_tenant');
    Route::post('/tenant/logo', [TenantController::class, 'uploadLogo'])
        ->middleware('permission:manage_tenant');

    // ─── DOCUMENT TEMPLATES (factura, contrato, instalación) ───
    // Deliberately its OWN permission, not manage_tenant: manage_tenant only
    // guards structured company-identity fields (NIT, razón social,
    // dirección de facturación...) with tight validation. Document template
    // bodies are free-form legal/fiscal-facing text — a tenant could grant
    // manage_tenant to a custom role meant only for "update our phone
    // number" (this app supports arbitrary custom role/permission
    // combinations, see RoleController), and that role would otherwise also
    // be able to rewrite contract clauses or invoice footers. Splitting it
    // keeps that blast radius separate.
    //
    // No ->where() constraint on {type}: an invalid value must still hit the
    // controller so DocumentTemplateController::assertValidType() can return
    // a clean JSON 404, instead of the route failing to match and falling
    // through to the SPA catch-all route.
    Route::middleware(['permission:manage_document_templates'])->group(function () {
        Route::get('/document-templates', [DocumentTemplateController::class, 'index']);
        // El listado de plantillas base va dentro de show(); esto sólo baja el
        // cuerpo de la que el tenant elija (varios KB por plantilla).
        Route::get('/document-templates/{type}/starters/{slug}', [DocumentTemplateController::class, 'starter']);
        Route::get('/document-templates/{type}', [DocumentTemplateController::class, 'show']);
        Route::put('/document-templates/{type}', [DocumentTemplateController::class, 'update']);
        Route::post('/document-templates/{type}/reset', [DocumentTemplateController::class, 'reset']);
        Route::post('/document-templates/{type}/preview', [DocumentTemplateController::class, 'preview']);
    });

    // ─── SETTINGS ───
    Route::middleware(['permission:view_settings'])->group(function () {
        Route::post('/settings/cache/clear', [SettingsController::class, 'clearCache']);
    });

    // ─── HELP CENTER / MANUAL ───
    // La LECTURA queda abierta a cualquier autenticado: es el manual del
    // producto y la ruta /manual no exige permiso. La ESCRITURA no: hasta
    // 2026-07-30 cualquier usuario autenticado podía reescribir o borrar los
    // artículos de ayuda de su tenant.
    Route::get('/help-center', [HelpCenterController::class, 'index']);
    Route::middleware('permission:view_settings')->group(function () {
        Route::post('/help-center/categories', [HelpCenterController::class, 'storeCategory']);
        Route::put('/help-center/categories/{id}', [HelpCenterController::class, 'updateCategory']);
        Route::delete('/help-center/categories/{id}', [HelpCenterController::class, 'destroyCategory']);
        Route::post('/help-center/articles', [HelpCenterController::class, 'storeArticle']);
        Route::put('/help-center/articles/{id}', [HelpCenterController::class, 'updateArticle']);
        Route::delete('/help-center/articles/{id}', [HelpCenterController::class, 'destroyArticle']);
    });

    // ─── MASS ACTIONS / IMPORT ───
    Route::middleware(['permission:execute_mass_actions', 'throttle:bulk-ops'])->prefix('import')->group(function () {
        Route::get('template', [ImportController::class, 'downloadUnifiedTemplate']);
        Route::post('upload', [ImportController::class, 'importUnified']);
        Route::get('docs', [ImportController::class, 'fieldDocs']);
        Route::post('errors-excel', [ImportController::class, 'exportErrors']);

        // Bulk customer update (separate flow from initial bulk load)
        Route::get('customers-update-template', [ImportController::class, 'downloadCustomersUpdateTemplate']);
        Route::post('customers-update', [ImportController::class, 'importCustomersUpdate']);
        Route::get('customers-update-docs', [ImportController::class, 'customersUpdateFieldDocs']);

        // Bulk inventory load (devices with serial/MAC; stock/provider/branch by name)
        Route::get('inventory-template', [ImportController::class, 'downloadInventoryTemplate']);
        Route::post('inventory', [ImportController::class, 'importInventory']);
        Route::get('inventory-docs', [ImportController::class, 'inventoryFieldDocs']);
    });
});
