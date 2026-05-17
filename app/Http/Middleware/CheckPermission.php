<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        // Si el usuario es superadmin, podría tener acceso total.

        $user->loadMissing('role');

        if (!$user->role || !$user->role->hasPermission($permission)) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos suficientes para realizar esta acción.',
            ], 403);
        }

        return $next($request);
    }
}
