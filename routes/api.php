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
| API RESOURCES
|--------------------------------------------------------------------------
*/
Route::apiResources([
    'customers'  => CustomerProfileController::class,
    'routers'    => RouterController::class,
    'inventory'  => InventoryDeviceController::class,
    'staff'      => UserController::class,
    'plans'      => PlanController::class, // ✅ ESTO FALTABA
]);

/*
|--------------------------------------------------------------------------
| CATALOGS / LISTS
|--------------------------------------------------------------------------
*/
Route::get('/roles', [RoleController::class, 'index']);
Route::get('/sectorials', [SectorialController::class, 'index']);
