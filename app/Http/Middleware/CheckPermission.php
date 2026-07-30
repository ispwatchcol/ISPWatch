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
     * Acepta UNO O VARIOS permisos con semántica OR: `permission:a,b` deja pasar
     * a quien tenga `a` **o** `b`.
     *
     * El OR no es un capricho: hay datos de referencia que una pantalla necesita
     * aunque el permiso "dueño" de ese módulo no sea suyo. El formulario de alta
     * de cliente carga planes, sectoriales y routers, y el rol Técnico tiene
     * `add_clients` pero NO `view_plans`, `view_sectorials` ni `manage_routers`.
     * Exigir sólo el permiso dueño dejaría el formulario con los desplegables
     * vacíos. La escritura de esos módulos sí exige el permiso dueño a secas.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
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
        // Mirrors frontend logic in resources/js/stores/auth.js
        if ((int) $user->role_id === 1) {
            return $next($request);
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'Forbidden - You do not have permission to perform this action',
            'required_permission' => count($permissions) === 1
                ? $permissions[0]
                : $permissions,
        ], 403);
    }
}
