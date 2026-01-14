<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Database\QueryException;
use App\Helpers\ErrorMessages;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Redirigir invitados (no autenticados) a tu login Vue
        $middleware->redirectGuestsTo('/');

        // Register custom middleware aliases
        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'staff_profile' => \App\Http\Middleware\CheckStaffProfile::class,
        ]);

        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Handle database exceptions with user-friendly messages
        $exceptions->render(function (QueryException $e, $request) {
            // Log the original error for debugging
            \Log::error('Database error: ' . $e->getMessage(), [
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
                'code' => $e->errorInfo[0] ?? null,
            ]);

            // Return user-friendly error message
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => ErrorMessages::getDatabaseErrorMessage($e),
                ], 422);
            }

            // For web requests, you can redirect back with error
            return redirect()->back()
                ->withErrors(['error' => ErrorMessages::getDatabaseErrorMessage($e)])
                ->withInput();
        });
    })->create();
