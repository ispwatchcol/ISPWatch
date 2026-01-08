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
Route::post('/routers/{router}/apply-block-rules', [RouterController::class, 'applyBlockRules']);

// Support routes with permissions
Route::get('/support/statistics', [SupportTicketController::class, 'statistics'])
    ->middleware('permission:support.statistics');
Route::post('/support/{id}/message', [SupportTicketController::class, 'addMessage']);
Route::patch('/support/{id}/status', [SupportTicketController::class, 'updateStatus'])
    ->middleware('permission:support.update');

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
    'support' => SupportTicketController::class,
]);

/*
|--------------------------------------------------------------------------
| CATALOGOS / LISTAS SIMPLES
|--------------------------------------------------------------------------
*/
Route::get('/roles', [RoleController::class, 'index']);
