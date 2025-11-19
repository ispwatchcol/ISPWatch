<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerProfileController;
use App\Http\Controllers\RouterController;
use App\Http\Controllers\InventoryDeviceController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RoleController;

Route::post('/login', [AuthController::class, 'login']);

Route::apiResources([
    'customers' => CustomerProfileController::class,
    'routers' => RouterController::class,
    'inventory/devices' => InventoryDeviceController::class,
    'staff' => UserController::class,
]);

Route::get('/roles', [RoleController::class, 'index']);
