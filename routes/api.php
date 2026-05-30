<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerProfileController;
use App\Http\Controllers\CustomerDocumentController;
use App\Http\Controllers\CustomerInstallationController;
use App\Http\Controllers\ProspectController;
use App\Http\Controllers\RouterController;
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
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\VerificationController;
use App\Http\Controllers\PaymentReminderController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\HelpCenterController;

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
    Route::get('/customers/statistics', [CustomerProfileController::class, 'statistics']);
    Route::get('/customers/map', [CustomerProfileController::class, 'mapData']);
    Route::post('/customers/{id}/provision', [CustomerProfileController::class, 'provision'])
        ->middleware('permission:activate_deactivate_clients');
    Route::post('/customers/bulk-provision', [CustomerProfileController::class, 'bulkProvision'])
        ->middleware('permission:activate_deactivate_clients');
    // Aprovisionamiento masivo asíncrono (job en cola + polling de progreso).
    Route::post('/customers/bulk-provision-async', [CustomerProfileController::class, 'bulkProvisionAsync'])
        ->middleware('permission:activate_deactivate_clients');
    Route::get('/customers/bulk-provision-status/{jobId}', [CustomerProfileController::class, 'bulkProvisionStatus'])
        ->middleware('permission:activate_deactivate_clients');
    Route::post('/customers/{id}/suspend', [CustomerProfileController::class, 'suspend'])
        ->middleware('permission:activate_deactivate_clients');
    Route::post('/customers/{id}/activate', [CustomerProfileController::class, 'activate'])
        ->middleware('permission:activate_deactivate_clients');

    // ─── CUSTOMER INSTALLATIONS ───
    Route::get('/installations', [CustomerInstallationController::class, 'all']);
    Route::post('/installations', [CustomerInstallationController::class, 'createWithProspect']);
    Route::get('/installations/technicians', [CustomerInstallationController::class, 'technicians']);
    Route::get('/installations/customers', [CustomerInstallationController::class, 'customersForInstallation']);
    Route::get('/installations/{installation}', [CustomerInstallationController::class, 'show']);
    Route::put('/installations/{installation}/prospect', [CustomerInstallationController::class, 'updateProspect']);
    Route::get('/customers/{customer}/installations', [CustomerInstallationController::class, 'index']);
    Route::post('/customers/{customer}/installations', [CustomerInstallationController::class, 'store']);
    Route::put('/customers/installations/{installation}', [CustomerInstallationController::class, 'update']);
    Route::delete('/customers/installations/{installation}', [CustomerInstallationController::class, 'destroy']);
    Route::put('/installations/{installation}/sheet', [CustomerInstallationController::class, 'saveSheet']);
    Route::post('/installations/{installation}/photos', [CustomerInstallationController::class, 'uploadPhotos']);
    Route::post('/installations/{installation}/sign', [CustomerInstallationController::class, 'sign']);

    // ─── PROSPECTS ───
    Route::apiResource('prospects', ProspectController::class);
    Route::post('/prospects/{prospect}/mark-converted', [ProspectController::class, 'markConverted']);
    Route::post('/prospects/{prospect}/installations', [CustomerInstallationController::class, 'storeForProspect']);

    // ─── CUSTOMER DOCUMENTS & CONTRACT ───
    Route::get('/customers/{customer}/documents', [CustomerDocumentController::class, 'index']);
    Route::post('/customers/{customer}/documents', [CustomerDocumentController::class, 'store']);
    Route::delete('/customers/documents/{document}', [CustomerDocumentController::class, 'destroy']);
    Route::get('/customers/{customer}/contract-data', [CustomerDocumentController::class, 'contractData']);
    Route::post('/customers/{customer}/contract-sign', [CustomerDocumentController::class, 'signContract']);

    // ─── ROUTER MANAGEMENT ───
    Route::get('/routers/{router}/free-ips', [RouterController::class, 'getFreeIps']);
    Route::get('/routers/{router}/vpn-script', [RouterController::class, 'generateVpnScript'])
        ->middleware('permission:manage_routers');
    Route::post('/routers/{router}/verify-vpn', [RouterController::class, 'verifyVpnConnection'])
        ->middleware('permission:manage_routers');
    Route::get('/routers/{router}/interfaces', [RouterController::class, 'getInterfaces']);
    Route::get('/routers/{router}/traffic', [RouterController::class, 'trafficHistory']);
    Route::post('/routers/{router}/set-wan-interface', [RouterController::class, 'setWanInterface'])
        ->middleware('permission:manage_routers');
    Route::post('/routers/{router}/apply-block-rules', [RouterController::class, 'applyBlockRules'])
        ->middleware('permission:activate_deactivate_clients');
    Route::get('/routers/{router}/verify-block-rules', [RouterController::class, 'verifyBlockRules']);
    Route::get('/routers/{router}/test-ssh-connection', [RouterController::class, 'testClientSshConnection']);
    Route::get('/routers/test-core-connection', [RouterController::class, 'testCoreConnection']);
    Route::post('/routers/{router}/test-secret-sync', [RouterController::class, 'testSecretSync'])
        ->middleware('permission:manage_routers');
    // SECURITY FIX (OWASP A01): the GET variant must require the same
    // permission as POST — otherwise it is a method-based authz bypass.
    Route::get('/routers/{router}/test-secret-sync', [RouterController::class, 'testSecretSync'])
        ->middleware('permission:manage_routers');
    Route::get('/routers/{router}/test-queue-sync', [RouterController::class, 'testQueueSync']);

    // Plan → RB engine sync (per control method)
    Route::post('/plans/{plan}/sync-pppoe-profile', [PlanController::class, 'syncPppoeProfile']);
    Route::post('/plans/{plan}/sync-hotspot-profile', [PlanController::class, 'syncHotspotProfile']);
    Route::post('/plans/{plan}/sync-pcq-engine', [PlanController::class, 'syncPcqEngine']);

    // ─── BILLING ───
    Route::middleware(['permission:view_billing'])->group(function () {
        Route::get('/billing/stats', [BillingController::class, 'getStats']);
        Route::get('/billing/invoices', [BillingController::class, 'index']);
        Route::get('/billing/invoices/{id}', [BillingController::class, 'show']);
        Route::post('/billing/invoices', [BillingController::class, 'store']);
        Route::put('/billing/invoices/{id}', [BillingController::class, 'update']);
        Route::post('/billing/invoices/{id}/items', [BillingController::class, 'addItems']);
        Route::get('/billing/invoices/{id}/pdf', [BillingController::class, 'downloadPdf']);
        Route::get('/billing/payments', [BillingController::class, 'getPayments']);
        Route::post('/billing/payments', [BillingController::class, 'registerPayment']);
        Route::put('/billing/payments/{id}', [BillingController::class, 'updatePayment']);
        Route::delete('/billing/payments/{id}', [BillingController::class, 'deletePayment']);
        Route::get('/billing/customers/{customerId}/balance', [BillingController::class, 'getCustomerBalance']);
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
    });

    // ─── BILLING ACTION LOGS (failover de facturación) ───
    Route::middleware(['permission:execute_mass_actions'])->group(function () {
        Route::get('/billing/action-logs',             [BillingActionLogController::class, 'index']);
        Route::get('/billing/action-logs/stats',       [BillingActionLogController::class, 'stats']);
        Route::post('/billing/action-logs/{id}/retry', [BillingActionLogController::class, 'retry']);
        Route::post('/billing/action-logs/retry-all',  [BillingActionLogController::class, 'retryAll']);
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
    Route::get('/sectorials/{sectorial}/photos',  [SectorialPhotoController::class, 'index']);
    Route::post('/sectorials/{sectorial}/photos', [SectorialPhotoController::class, 'store']);
    Route::delete('/sectorials/{sectorial}/photos/{photo}', [SectorialPhotoController::class, 'destroy']);

    Route::get('/sectorials/{sectorial}/notes',  [SectorialNoteController::class, 'index']);
    Route::post('/sectorials/{sectorial}/notes', [SectorialNoteController::class, 'store']);
    Route::put('/sectorials/{sectorial}/notes/{note}', [SectorialNoteController::class, 'update']);
    Route::delete('/sectorials/{sectorial}/notes/{note}', [SectorialNoteController::class, 'destroy']);

    Route::get('/sectorials/{sectorial}/history', [SectorialHistoryController::class, 'index']);
    Route::get('/sectorials/{sectorial}/tickets', [SectorialHistoryController::class, 'tickets']);

    // ─── CRUD RESOURCES ───
    Route::apiResources([
        'customers' => CustomerProfileController::class,
        'routers' => RouterController::class,
        'inventory' => InventoryDeviceController::class,
        'plans' => PlanController::class,
        'sectorials' => SectorialController::class,
        'support'    => SupportTicketController::class,
    ]);

    // ─── STAFF ───
    Route::middleware(['permission:view_staff'])->group(function () {
        Route::apiResource('staff', UserController::class);
    });

    // ─── CATALOGS ───
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

    // ─── SETTINGS ───
    Route::middleware(['permission:view_settings'])->group(function () {
        Route::post('/settings/cache/clear', [SettingsController::class, 'clearCache']);
    });

    // ─── HELP CENTER / MANUAL ───
    Route::get('/help-center', [HelpCenterController::class, 'index']);
    Route::post('/help-center/categories', [HelpCenterController::class, 'storeCategory']);
    Route::put('/help-center/categories/{id}', [HelpCenterController::class, 'updateCategory']);
    Route::delete('/help-center/categories/{id}', [HelpCenterController::class, 'destroyCategory']);
    Route::post('/help-center/articles', [HelpCenterController::class, 'storeArticle']);
    Route::put('/help-center/articles/{id}', [HelpCenterController::class, 'updateArticle']);
    Route::delete('/help-center/articles/{id}', [HelpCenterController::class, 'destroyArticle']);

    // ─── MASS ACTIONS / IMPORT ───
    Route::middleware(['permission:execute_mass_actions'])->prefix('import')->group(function () {
        Route::get('template', [ImportController::class, 'downloadUnifiedTemplate']);
        Route::post('upload', [ImportController::class, 'importUnified']);
        Route::get('docs', [ImportController::class, 'fieldDocs']);
        Route::post('errors-excel', [ImportController::class, 'exportErrors']);

        // Bulk customer update (separate flow from initial bulk load)
        Route::get('customers-update-template', [ImportController::class, 'downloadCustomersUpdateTemplate']);
        Route::post('customers-update', [ImportController::class, 'importCustomersUpdate']);
        Route::get('customers-update-docs', [ImportController::class, 'customersUpdateFieldDocs']);
    });
});
