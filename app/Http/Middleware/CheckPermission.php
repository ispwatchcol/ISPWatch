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

        // Eager-load role if not already loaded
        if (!$user->relationLoaded('role')) {
            $user->load('role');
        }

        if (!$user->role) {
            return response()->json([
                'message' => 'Forbidden - No role assigned to your account'
            ], 403);
        }

        // Check if user's role has the required permission
        if (!$user->role->hasPermission($permission)) {
            return response()->json([
                'message' => 'Forbidden - You do not have permission to perform this action',
                'required_permission' => $permission,
            ], 403);
        }

        return $next($request);
    }
}
