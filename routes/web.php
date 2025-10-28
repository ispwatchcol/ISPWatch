<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

require __DIR__.'/auth.php';

// 👇 Esta debe ir AL FINAL, siempre después de las demás rutas
Route::get('/{any}', function () {
    return view('welcome');
})->where('any', '.*');
