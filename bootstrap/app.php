<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use App\Helpers\ErrorMessages;
use App\Support\DatabaseFailure;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        // `/health/deep` va sin middleware a propósito: el grupo `web` abre
        // sesión y el grupo `api` aplica throttle, y ambos dependen de la base
        // de datos. Con cualquiera de los dos, el chequeo se caería junto con lo
        // que debe diagnosticar. Ver routes/health.php.
        then: function (): void {
            Route::middleware([])->group(__DIR__ . '/../routes/health.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust all proxies (required for DigitalOcean App Platform)
        $middleware->trustProxies(at: '*');

        // Redirigir invitados (no autenticados) a tu login Vue
        $middleware->redirectGuestsTo('/');

        // Register custom middleware aliases
        $middleware->alias([
            'can_do'           => \App\Http\Middleware\CheckPermission::class,
            'permission'       => \App\Http\Middleware\CheckPermission::class,
            'staff_profile'    => \App\Http\Middleware\CheckStaffProfile::class,

            // API pública de solo lectura (/api/v1/partner).
            'api_key'          => \App\Http\Middleware\EnsureApiKeyRequest::class,
            'deny_api_clients' => \App\Http\Middleware\DenyApiClients::class,

            // Abilities de Sanctum: `ability:a,b` exige AL MENOS una; se usa
            // esa semántica (y no `abilities`, que las exige todas) porque
            // cada endpoint depende de un único permiso de lectura.
            'ability'          => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
            'abilities'        => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
        ]);

        // Add global security headers to all responses
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // El prefijo del consecutivo de contratos es texto libre y su espacio
        // final es significativo: en «Contrato N° » es el separador que eligió
        // el ISP. TrimStrings se lo comía y el número salía «Contrato N°00012».
        \Illuminate\Foundation\Http\Middleware\TrimStrings::except([
            'contract_prefix',
        ]);

        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        // Límite general de peticiones de la API (limitador 'api', definido en
        // AppServiceProvider::configureRateLimiting). Sin esto la API no tenía
        // ningún límite salvo el manual del login.
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Handle database exceptions with user-friendly messages
        $exceptions->render(function (QueryException $e, $request) {
            $infrastructure = DatabaseFailure::isInfrastructure($e);

            \Log::error(
                ($infrastructure ? 'Database unavailable: ' : 'Database error: ') . $e->getMessage(),
                [
                    'sql' => $e->getSql(),
                    'bindings' => $e->getBindings(),
                    'code' => $e->errorInfo[0] ?? null,
                    'infrastructure' => $infrastructure,
                ]
            );

            // ── La base de datos no responde ────────────────────────────────
            // 503 y NUNCA una redirección. Sin base de datos no hay sesión, y
            // `redirect()->back()` sin sesión ni Referer apunta a la raíz del
            // sitio: cada intento reproduce el error y vuelve a redirigir. Eso
            // es exactamente el ERR_TOO_MANY_REDIRECTS del 2026-08-20.
            //
            // El código también importa: un 422 afirma que los datos enviados
            // están mal, y mandó el diagnóstico en la dirección equivocada
            // durante horas. Un 503 dice la verdad —el servidor no puede
            // atender— y es lo que el centinela externo sabe interpretar.
            if ($infrastructure) {
                $body = [
                    'success' => false,
                    'message' => 'El servicio no está disponible en este momento. Estamos trabajando para restablecerlo; intenta de nuevo en unos minutos.',
                ];

                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json($body, 503)->header('Retry-After', '60');
                }

                return response()
                    ->view('errors.database-unavailable', [], 503)
                    ->header('Retry-After', '60');
            }

            // ── Error de datos: la base está viva ───────────────────────────
            // Una restricción violada, una columna que falta. La sesión existe,
            // así que redirigir es seguro y 422 describe bien el problema.
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => ErrorMessages::getDatabaseErrorMessage($e),
                ], 422);
            }

            return redirect()->back()
                ->withErrors(['error' => ErrorMessages::getDatabaseErrorMessage($e)])
                ->withInput();
        });
    })->create();
