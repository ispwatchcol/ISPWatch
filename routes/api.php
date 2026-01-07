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

// VPN Routes
Route::get('/routers/{router}/vpn-script', [RouterController::class, 'generateVpnScript']);
Route::post('/routers/{router}/verify-vpn', [RouterController::class, 'verifyVpnConnection']);

// Router Interfaces Routes
Route::get('/routers/{router}/interfaces', [RouterController::class, 'getInterfaces']);
Route::post('/routers/{router}/set-wan-interface', [RouterController::class, 'setWanInterface']);

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
]);

/*
|--------------------------------------------------------------------------
| CATALOGOS / LISTAS SIMPLES
|--------------------------------------------------------------------------
*/
Route::get('/roles', [RoleController::class, 'index']);
