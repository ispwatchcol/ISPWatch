<?php

use Illuminate\Support\Facades\Route;

// ✅ Carga la vista principal del SPA
Route::view('/', 'welcome');

// ✅ Si necesitas mantener estas rutas de Blade por ahora
Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

// ✅ Rutas de Breeze / Fortify / Jetstream
require __DIR__ . '/auth.php';

// ✅ Debe ir AL FINAL
// Redirige cualquier ruta desconocida a la vista welcome
// para que Vue Router maneje el routing
Route::get('/{any}', function () {
    return view('welcome');
})->where('any', '.*');
