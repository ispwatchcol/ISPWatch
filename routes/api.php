<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerProfileController;
use App\Http\Controllers\RouterController;
use App\Http\Controllers\InventoryDeviceController;
use App\Http\Controllers\UserController;

Route::post('/login', [AuthController::class, 'login']);

Route::apiResource('customers', CustomerProfileController::class);

Route::apiResource('routers', RouterController::class);

Route::apiResource('inventory/devices', InventoryDeviceController::class);

Route::apiResource('users', UserController::class);