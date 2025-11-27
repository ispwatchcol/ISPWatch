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

            // Asegúrate de cargar la relación 'role' con "with('role')"
            $user = User::with('role')->where('email_tenant', $request->email_tenant)->first();

            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Credenciales incorrectas.'], 401);
            }

            // Verificación de contraseña (texto plano temporalmente)
            if ($request->password !== $user->password) {
                return response()->json(['success' => false, 'message' => 'Credenciales incorrectas.'], 401);
            }

            $user->update(['last_access' => now()]);

            // 👇 AQUÍ ESTÁ LA CLAVE: Construir manualmente la respuesta con role_name
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'user_name' => $user->user_name,
                    'user_lastname' => $user->user_lastname, // Agrega esto si lo usas
                    'email_tenant' => $user->email_tenant,
                    'role_id' => $user->role_id,
                    // Usamos optional() por si el rol es null
                    'role_name' => optional($user->role)->name ?? 'Sin rol', 
                ]
            ]);
        }
}
