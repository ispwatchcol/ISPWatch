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
                'email_tenant' => 'sometimes|nullable|email|max:255',
                'tel' => 'sometimes|nullable|string|max:50',
                'address' => 'sometimes|nullable|string|max:500',
                'timezone' => 'sometimes|nullable|string|max:100',
                'currency' => 'sometimes|nullable|string|max:10',
            ]);

            // Map inputs to database columns
            $updateData = [];
            if ($request->has('name'))
                $updateData['name'] = $request->name;
            if ($request->has('domain'))
                $updateData['domain'] = $request->domain;
            if ($request->has('email_tenant'))
                $updateData['email_tenant'] = $request->email_tenant;
            if ($request->has('tel'))
                $updateData['tel_tenant'] = $request->tel;
            if ($request->has('address'))
                $updateData['address_tenant'] = $request->address;
            if ($request->has('timezone'))
                $updateData['zone_tenant'] = $request->timezone;
            if ($request->has('currency'))
                $updateData['currency_tenant'] = $request->currency;

            $tenant->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Información del tenant actualizada correctamente',
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
                'message' => 'Error al actualizar el tenant: ' . $e->getMessage()
            ], 500);
        }
    }
}
