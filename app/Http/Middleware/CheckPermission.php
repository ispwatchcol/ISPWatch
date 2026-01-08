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
        // For now, we'll get user from request (in production, use auth()->user())
        // This assumes user_id is passed in request for testing
        $userId = $request->user_id ?? $request->input('user_id') ?? 1;

        $user = \App\Models\User::with('role')->find($userId);

        if (!$user || !$user->role) {
            return response()->json([
                'message' => 'Unauthorized - No user or role found'
            ], 403);
        }

        // Check if user's role has the required permission
        if (!$user->role->hasPermission($permission)) {
            return response()->json([
                'message' => 'Forbidden - You do not have permission to perform this action',
                'required_permission' => $permission,
                'your_permissions' => $user->role->permissions ?? []
            ], 403);
        }

        return $next($request);
    }
}
