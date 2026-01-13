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

/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/
Route::post('/login', [AuthController::class, 'login']);

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

// VPN Routes
Route::get('/routers/{router}/vpn-script', [RouterController::class, 'generateVpnScript']);
Route::post('/routers/{router}/verify-vpn', [RouterController::class, 'verifyVpnConnection']);

// Router Interfaces Routes
Route::get('/routers/{router}/interfaces', [RouterController::class, 'getInterfaces']);
Route::post('/routers/{router}/set-wan-interface', [RouterController::class, 'setWanInterface']);

// Firewall Block Rules
// Firewall Block Rules
Route::post('/routers/{router}/apply-block-rules', [RouterController::class, 'applyBlockRules']);

/*
|--------------------------------------------------------------------------
| BILLING MODULE
|--------------------------------------------------------------------------
*/
Route::group([], function () {
    Route::get('/billing/invoices', [BillingController::class, 'index']);
    Route::get('/billing/invoices/{id}', [BillingController::class, 'show']);
    Route::post('/billing/invoices', [BillingController::class, 'store']);
    Route::post('/billing/invoices/{id}/items', [BillingController::class, 'addItems']);
    Route::get('/billing/invoices/{id}/pdf', [BillingController::class, 'downloadPdf']);
    Route::post('/billing/payments', [BillingController::class, 'registerPayment']);
    Route::get('/billing/customers/{customerId}/balance', [BillingController::class, 'getCustomerBalance']);
    Route::post('/billing/run-monthly', [BillingController::class, 'runMonthlyGeneration']);
});

// Support routes with permissions
Route::middleware(['auth:sanctum', 'staff_profile'])->group(function () {
    Route::get('/support/statistics', [SupportTicketController::class, 'statistics']);
    Route::post('/support/{id}/message', [SupportTicketController::class, 'addMessage']);
    Route::patch('/support/{id}/status', [SupportTicketController::class, 'updateStatus']);
    // Additional staff-only support routes if needed
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

