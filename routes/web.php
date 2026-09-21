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
// `health` queda excluido: las rutas de `routes/health.php` se registran DESPUÉS
// de este archivo (van en el callback `then` de bootstrap/app.php, para quedar
// sin middleware), y Laravel resuelve por orden de registro. Sin esta exclusión
// el catch-all atiende `/health` y devuelve el HTML del SPA con un 200 —que es
// justo la respuesta que haría inútil al chequeo: un monitor externo vería 200 y
// nunca alertaría. Ocurrió de verdad y hay una prueba que lo fija.
//
// El patrón excluye `health` exacto y todo lo que cuelgue de `health/`, pero no
// toca rutas del SPA que sólo empiecen igual (`/healthcare` sigue funcionando).
//
// `api` queda excluido por la misma razón y desde KAN-97 (P-41): las rutas de
// la API se registran ANTES que estas (ver ApplicationBuilder), pero una URL
// `/api/...` que no case con ninguna caía aquí y devolvía el HTML del SPA con
// un 200. Para un integrador eso es peor que un 404: su cliente HTTP da la
// petición por buena y falla después al leer el JSON que nunca llegó. La
// respuesta correcta la da `Route::fallback()` en routes/api.php.
Route::get('/{any}', function () {
    return view('app');
})->where('any', '(?!health$|health/|api$|api/).*');
