<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Tenant;
use App\Models\User;

class TenantController extends Controller
{
    /**
     * Get tenant information by ID
     */
    public function show($id)
    {
        try {
            $tenant = Tenant::find($id);

            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant no encontrado'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $tenant
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener información del tenant',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update tenant information
     * Only administrators can update tenant information
     */
    public function update(Request $request, $id)
    {
        try {
            // Validate that the user is an administrator
            $userId = $request->input('user_id');

            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            $user = User::with('role')->find($userId);

            if (!$user || !$user->role || strtolower($user->role->name) !== 'administrador') {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permisos para realizar esta acción'
                ], 403);
            }

            // Verify that the user belongs to the tenant they're trying to update
            if ($user->tenant_id != $id) {
                return response()->json([
                    'success' => false,
                    'message' => 'No puedes modificar información de otro tenant'
                ], 403);
            }

            $tenant = Tenant::find($id);

            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant no encontrado'
                ], 404);
            }

            // Validate input
            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'domain' => 'sometimes|required|string|max:255',
            ]);

            // Update tenant
            $tenant->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Tenant actualizado correctamente',
                'data' => $tenant
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de validación inválidos',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar información del tenant',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
