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
        // SECURITY FIX (OWASP A01): Always use the authenticated user from Sanctum/session.
        // Never trust user_id from request input — that allows privilege escalation.
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthorized - Authentication required'
            ], 401);
        }

        // Eager-load the user's OWN role, bypassing the tenant global scope on the
        // Role model. That scope is for LISTING roles by tenant visibility; a user's
        // own assigned role must always resolve regardless of tenant matching, or a
        // tenant_id mismatch silently nulls the role and yields a false 403.
        // Mirrors the login flow in AuthController@login.
        $user->load(['role' => fn($q) => $q->withoutGlobalScope('tenant')]);

        if (!$user->role) {
            return response()->json([
                'message' => 'Forbidden - No role assigned to your account'
            ], 403);
        }

        // Superadmin bypass: role_id == 1 (Administrador) has full access.
        // Mirrors frontend logic in resources/js/services/auth.js
        if ((int) $user->role_id === 1) {
            return $next($request);
        }

        if (!$user->role->hasPermission($permission)) {
            return response()->json([
                'message' => 'Forbidden - You do not have permission to perform this action',
                'required_permission' => $permission,
            ], 403);
        }

        return $next($request);
    }
}
