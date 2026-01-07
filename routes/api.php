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
