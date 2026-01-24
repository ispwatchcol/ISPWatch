<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        if (!Auth::check()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Check if user has role Admin (1) or Staff (2)
        $allowedRoles = [1, 2];
        if (!in_array(Auth::user()->role_id, $allowedRoles)) {
            return response()->json(['message' => 'Unauthorized. Staff or Admin role required.'], 403);
        }

        return $next($request);
    }
}
