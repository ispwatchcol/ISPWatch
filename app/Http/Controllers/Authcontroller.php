<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)
            ->orWhere('email_tenant', $request->email)
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Credenciales incorrectas.'
            ], 401);
        }

        $isValid = false;

        // Si no funciona, intenta comparar texto plano (solo para desarrollo)
        if (!$isValid && $request->password === $user->password) {
            $isValid = true;
        }

        if (!$isValid) {
            return response()->json([
                'success' => false,
                'message' => 'Credenciales incorrectas.'
            ], 401);
        }

        $user->update(['last_access' => now()]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'user_name' => $user->user_name,
                'role_id' => $user->role_id,
                'role_name' => optional($user->role)->name ?? 'Sin rol',
                'tenant_id' => $user->tenant_id,
                'email' => $user->email,
                'email_tenant' => $user->email_tenant,
            ]
        ]);
    }
}
