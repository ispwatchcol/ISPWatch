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
//
// `health/` queda excluido: las rutas de `routes/health.php` se registran DESPUÉS
// de este archivo (van en el callback `then` de bootstrap/app.php, para quedar
// sin middleware), y Laravel resuelve por orden de registro. Sin esta exclusión
// el catch-all atiende `/health/deep` y devuelve el HTML del SPA con un 200
// —que es justo la respuesta que haría inútil al chequeo: un monitor externo
// vería 200 y nunca alertaría.
Route::get('/{any}', function () {
    return view('app');
})->where('any', '(?!health/).*');
