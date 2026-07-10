<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckStaffProfile
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Roles are per-tenant, so their IDs vary between tenants — never hard-code
        // role_id (the old [1, 2] check only worked for the first/global tenant and
        // wrongly 403'd every other tenant's Admin/Staff). Identify Admin/Staff by
        // role CODE instead, plus the global superadmin (role_id == 1) bypass.
        // Load the role bypassing the Role tenant global scope so a tenant_id
        // mismatch can't silently null it (mirrors AuthController@login).
        $user->loadMissing(['role' => fn($q) => $q->withoutGlobalScope('tenant')]);

        $isSuperadmin = (int) $user->role_id === 1;
        $code = $user->role?->code;

        if (!$isSuperadmin && !in_array($code, ['admin', 'staff'], true)) {
            return response()->json(['message' => 'Unauthorized. Staff or Admin role required.'], 403);
        }

        return $next($request);
    }
}
