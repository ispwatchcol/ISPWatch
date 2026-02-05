<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/dashboard');
});

// Portal de pago para usuarios suspendidos
Route::get('/portal-pago', function () {
    return view('payment-portal');
});

// Sanctum CSRF Cookie route (required for SPA authentication)
// This provides the CSRF token needed for login/register
Route::middleware('web')->group(function () {
    Route::get('/sanctum/csrf-cookie', [\Laravel\Sanctum\Http\Controllers\CsrfCookieController::class, 'show']);
});

// SPA catch-all route (must be last)
Route::get('/{any}', function () {
    return view('app');
})->where('any', '.*');
