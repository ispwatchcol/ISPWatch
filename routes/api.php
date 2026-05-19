<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerProfileController;
use App\Http\Controllers\CustomerDocumentController;
use App\Http\Controllers\RouterController;
use App\Http\Controllers\InventoryDeviceController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SectorialController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\VerificationController;
use App\Http\Controllers\PaymentReminderController;
use App\Http\Controllers\ImportController;

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

// ─── PUBLIC CATALOGS (no auth required) ───
Route::get('/roles', [RoleController::class, 'index']);

/*
|--------------------------------------------------------------------------
| PROTECTED ROUTES (require auth:sanctum)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->group(function () {

    // ─── DASHBOARD ───
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

    // ─── CUSTOMERS (custom routes before apiResource) ───
    Route::get('/customers/statistics', [CustomerProfileController::class, 'statistics']);
    Route::get('/customers/map', [CustomerProfileController::class, 'mapData']);
    Route::post('/customers/{id}/provision', [CustomerProfileController::class, 'provision'])
        ->middleware('permission:manage_customers');
    Route::post('/customers/bulk-provision', [CustomerProfileController::class, 'bulkProvision'])
        ->middleware('permission:manage_customers');
    Route::post('/customers/{id}/suspend', [CustomerProfileController::class, 'suspend'])
        ->middleware('permission:suspend_customers');
    Route::post('/customers/{id}/activate', [CustomerProfileController::class, 'activate'])
        ->middleware('permission:suspend_customers');

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
    Route::post('/routers/{router}/set-wan-interface', [RouterController::class, 'setWanInterface'])
        ->middleware('permission:manage_routers');
    Route::post('/routers/{router}/apply-block-rules', [RouterController::class, 'applyBlockRules'])
        ->middleware('permission:suspend_customers');
    Route::get('/routers/{router}/verify-block-rules', [RouterController::class, 'verifyBlockRules']);
    Route::get('/routers/{router}/test-ssh-connection', [RouterController::class, 'testClientSshConnection']);
    Route::get('/routers/test-core-connection', [RouterController::class, 'testCoreConnection']);
    Route::post('/routers/{router}/test-secret-sync', [RouterController::class, 'testSecretSync'])
        ->middleware('permission:manage_routers');
    Route::get('/routers/{router}/test-secret-sync', [RouterController::class, 'testSecretSync']);
    Route::get('/routers/{router}/test-queue-sync', [RouterController::class, 'testQueueSync']);

    // PPPoE plan sync
    Route::post('/plans/{plan}/sync-pppoe-profile', [PlanController::class, 'syncPppoeProfile']);

    // ─── BILLING ───
    Route::middleware(['permission:billing.view'])->group(function () {
        Route::get('/billing/stats', [BillingController::class, 'getStats']);
        Route::get('/billing/invoices', [BillingController::class, 'index']);
        Route::get('/billing/invoices/{id}', [BillingController::class, 'show']);
        Route::post('/billing/invoices', [BillingController::class, 'store']);
        Route::post('/billing/invoices/{id}/items', [BillingController::class, 'addItems']);
        Route::get('/billing/invoices/{id}/pdf', [BillingController::class, 'downloadPdf']);
        Route::get('/billing/payments', [BillingController::class, 'getPayments']);
        Route::post('/billing/payments', [BillingController::class, 'registerPayment']);
        Route::get('/billing/customers/{customerId}/balance', [BillingController::class, 'getCustomerBalance']);
        Route::post('/billing/run-monthly', [BillingController::class, 'runMonthlyGeneration']);
        Route::post('/billing/run-overdue', [BillingController::class, 'processOverdue']);
        Route::get('/billing/configs', [BillingController::class, 'getBillingConfigs']);
        Route::put('/billing/configs/{id}', [BillingController::class, 'updateBillingConfig']);
        Route::post('/billing/run-auto-cut', [BillingController::class, 'runAutoCut']);

        // Payment Reminders
        Route::post('/billing/invoices/{id}/send-reminder', [PaymentReminderController::class, 'sendReminder']);
        Route::post('/billing/invoices/bulk-reminders', [PaymentReminderController::class, 'sendBulkReminders']);
        Route::get('/billing/whatsapp-status', [PaymentReminderController::class, 'checkWhatsAppStatus']);
    });

    // ─── SUPPORT (requires staff profile) ───
    Route::middleware(['staff_profile'])->group(function () {
        Route::get('/support/statistics', [SupportTicketController::class, 'statistics']);
        Route::post('/support/{id}/message', [SupportTicketController::class, 'addMessage']);
        Route::put('/support/messages/{id}', [SupportTicketController::class, 'updateMessage']);
        Route::delete('/support/messages/{id}', [SupportTicketController::class, 'deleteMessage']);
        Route::patch('/support/{id}/status', [SupportTicketController::class, 'updateStatus']);
    });

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
    Route::middleware(['permission:staff.view'])->group(function () {
        Route::apiResource('staff', UserController::class);
    });

    // ─── CATALOGS ───
    Route::get('/roles/permissions', [RoleController::class, 'permissions'])
        ->middleware('permission:manage_roles');
    Route::middleware('permission:manage_roles')->group(function () {
        Route::apiResource('roles', RoleController::class);
    });

    Route::get('/tenants/{id}', [TenantController::class, 'show'])
        ->middleware('permission:manage_tenant');
    Route::put('/tenants/{id}', [TenantController::class, 'update'])
        ->middleware('permission:manage_tenant');
    Route::match(['put', 'patch'], '/tenant/config', [TenantController::class, 'updateConfig'])
        ->middleware('permission:manage_tenant');

    // ─── SETTINGS ───
    Route::middleware(['permission:settings.view'])->group(function () {
        Route::post('/settings/cache/clear', [SettingsController::class, 'clearCache']);
    });

    // ─── MASS ACTIONS / IMPORT ───
    Route::middleware(['permission:mass_actions.execute'])->prefix('import')->group(function () {
        Route::get('template', [ImportController::class, 'downloadUnifiedTemplate']);
        Route::post('upload', [ImportController::class, 'importUnified']);
        Route::get('docs', [ImportController::class, 'fieldDocs']);
        Route::post('errors-excel', [ImportController::class, 'exportErrors']);
    });
});
