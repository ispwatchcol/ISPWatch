<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        // Validar datos que vienen del frontend
        $request->validate([
            'user_name' => 'required|string',
            'password' => 'required|string',
        ]);

        // Buscar el usuario en la tabla 'user'
        $user = DB::table('user')
            ->where('user_name', $request->user_name)
            ->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no encontrado.'
            ], 404);
        }

        // ⚠️ Comparación en texto plano (NO recomendado en producción)
        if ($user->password !== $request->password) {
            return response()->json([
                'status' => 'error',
                'message' => 'Contraseña incorrecta.'
            ], 401);
        }

        // Retornar datos si el login es correcto
        return response()->json([
            'status' => 'success',
            'user' => [
                'id' => $user->id,
                'user_name' => $user->user_name,
                'email' => $user->email ?? null,
                'role' => $user->role ?? 'user',
            ]
        ], 200);
    }
}
