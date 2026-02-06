<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerProfileController;
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

/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/
Route::post('/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| REGISTRATION
|--------------------------------------------------------------------------
*/
Route::post('/register', [RegistrationController::class, 'register']);
Route::post('/register/send-code', [RegistrationController::class, 'sendVerificationCode']);

/*
|--------------------------------------------------------------------------
| EMAIL VERIFICATION
|--------------------------------------------------------------------------
*/
Route::get('/verify-email/{id}/{hash}', [VerificationController::class, 'verify'])
    ->middleware(['signed'])
    ->name('verification.verify');
Route::post('/verify-email/resend', [VerificationController::class, 'resend'])
    ->name('verification.resend');

/*
|--------------------------------------------------------------------------
| DASHBOARD
|--------------------------------------------------------------------------
*/
Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

/*
|--------------------------------------------------------------------------
| RUTAS PERSONALIZADAS (Deben ir ANTES de apiResources)
|--------------------------------------------------------------------------
*/
Route::get('/customers/statistics', [CustomerProfileController::class, 'statistics']);
Route::get('/customers/map', [CustomerProfileController::class, 'mapData']);
Route::post('/customers/{id}/provision', [CustomerProfileController::class, 'provision']);
Route::post('/customers/bulk-provision', [CustomerProfileController::class, 'bulkProvision']);
Route::post('/customers/{id}/suspend', [CustomerProfileController::class, 'suspend']);
Route::post('/customers/{id}/activate', [CustomerProfileController::class, 'activate']);

// VPN Routes (require authentication)
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/routers/{router}/vpn-script', [RouterController::class, 'generateVpnScript']);
    Route::post('/routers/{router}/verify-vpn', [RouterController::class, 'verifyVpnConnection']);

    // Router Interfaces Routes
    Route::get('/routers/{router}/interfaces', [RouterController::class, 'getInterfaces']);
    Route::post('/routers/{router}/set-wan-interface', [RouterController::class, 'setWanInterface']);

    // Firewall Block Rules
    Route::post('/routers/{router}/apply-block-rules', [RouterController::class, 'applyBlockRules']);
    Route::get('/routers/{router}/verify-block-rules', [RouterController::class, 'verifyBlockRules']);
    Route::get('/routers/{router}/test-ssh-connection', [RouterController::class, 'testClientSshConnection']);

    // Test MikroTik CORE connection
    Route::get('/routers/test-core-connection', [RouterController::class, 'testCoreConnection']);

    // Diagnóstico: Probar creación de secret en el CORE
    Route::post('/routers/{router}/test-secret-sync', [RouterController::class, 'testSecretSync']);
    Route::get('/routers/{router}/test-secret-sync', [RouterController::class, 'testSecretSync']);
});

/*
|--------------------------------------------------------------------------
| BILLING MODULE
|--------------------------------------------------------------------------
*/
Route::group([], function () {
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
});

// Support routes with permissions
Route::middleware(['auth:sanctum', 'staff_profile'])->group(function () {
    Route::get('/support/statistics', [SupportTicketController::class, 'statistics']);
    Route::post('/support/{id}/message', [SupportTicketController::class, 'addMessage']);
    Route::put('/support/messages/{id}', [SupportTicketController::class, 'updateMessage']);
    Route::delete('/support/messages/{id}', [SupportTicketController::class, 'deleteMessage']);
    Route::patch('/support/{id}/status', [SupportTicketController::class, 'updateStatus']);
});

/*
|--------------------------------------------------------------------------
| API RESOURCES (CRUD Completo: index, show, store, update, destroy)
|--------------------------------------------------------------------------
*/
Route::apiResources([
    'customers' => CustomerProfileController::class,
    'routers' => RouterController::class,
    'inventory' => InventoryDeviceController::class,
    'staff' => UserController::class,
    'plans' => PlanController::class,
    'sectorials' => SectorialController::class,
    'support' => SupportTicketController::class, // Most methods likely need staff_profile, check controller constructor or middleware usage
]);

/*
|--------------------------------------------------------------------------
| CATALOGOS / LISTAS SIMPLES
|--------------------------------------------------------------------------
*/
// Tenant routes
Route::get('/tenants/{id}', [TenantController::class, 'show']);
Route::put('/tenants/{id}', [TenantController::class, 'update']);

Route::get('/roles', [RoleController::class, 'index']);

// System Settings
Route::post('/settings/cache/clear', [SettingsController::class, 'clearCache']);

// Import Data Routes
Route::prefix('import')->middleware(['auth:sanctum'])->group(function () {
    Route::get('template/{type}', [App\Http\Controllers\ImportController::class, 'downloadTemplate']);
    Route::post('{type}', [App\Http\Controllers\ImportController::class, 'import']);
    Route::get('docs/{type}', [App\Http\Controllers\ImportController::class, 'fieldDocs']);
});
