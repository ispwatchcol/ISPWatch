<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
// use Illuminate\Support\Facades\Hash; // <--- YA NO ES NECESARIO IMPORTAR ESTO

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email_tenant' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('email_tenant', $request->email_tenant)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Credenciales incorrectas.'
            ], 401);
        }

        // 👇 CAMBIO CLAVE: Comparamos texto plano directamente
        // Si la password enviada es DIFERENTE a la de la base de datos, falla.
        if ($request->password !== $user->password) {
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
                // ... resto de tus datos
                'email_tenant' => $user->email_tenant,
            ]
        ]);
    }
}
