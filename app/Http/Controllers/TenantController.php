<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\UpdateTenantRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TenantController extends Controller
{
    /**
     * Get tenant information by ID.
     * google_maps_api_key is declared $hidden on the model so it is NEVER
     * included in toArray()/toJson(). We only expose a boolean flag so the
     * frontend can show a "key configured" placeholder without seeing the value.
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

            // toArray() already excludes google_maps_api_key (it's in $hidden).
            // We add a safe boolean so the UI knows whether a key is set.
            $data = $tenant->toArray();
            $data['has_google_maps_key'] = !empty($tenant->google_maps_api_key);

            return response()->json([
                'success' => true,
                'data'    => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener información del tenant',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update tenant information
     * Only administrators can update tenant information
     */
    public function update(UpdateTenantRequest $request, $id)
    {
        try {
            // SECURITY FIX (OWASP A01): Use authenticated user, not user_id from input.
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            // Eager-load role if not already loaded
            if (!$user->relationLoaded('role')) {
                $user->load('role');
            }

            if (!$user->role || strtolower($user->role->name) !== 'administrador') {
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

            // Validation is handled by UpdateTenantRequest.
            // Only update the fields that were actually sent in the request.
            $tenant->update($request->only(array_keys($request->rules())));

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

    /**
     * Return the Google Maps configuration for the authenticated user's tenant.
     *
     * Readable by any authenticated user (so non-admins can view the customer
     * map), unlike the full tenant payload which requires manage_tenant.
     * The Maps JavaScript API key is necessarily exposed client-side, so
     * returning it here is safe; it should be restricted by HTTP referrer in
     * the Google Cloud Console.
     */
    public function mapsConfig(Request $request)
    {
        $user = $request->user();

        if (!$user || !$user->tenant_id) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no autenticado o no pertenece a ningún tenant'
            ], 401);
        }

        $tenant = Tenant::find($user->tenant_id);

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant no encontrado'
            ], 404);
        }

        $apiKey = $tenant->google_maps_api_key;

        return response()->json([
            'success' => true,
            'data' => [
                'google_maps_api_key' => $apiKey,
                'has_key' => !empty($apiKey),
            ],
        ]);
    }

    /**
     * Update current tenant configuration
     */
    public function updateConfig(UpdateTenantRequest $request)
    {
        try {
            $user = $request->user();

            if (!$user || !$user->tenant_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado o no pertenece a ningún tenant'
                ], 401);
            }

            return DB::transaction(function () use ($user, $request) {
                $tenant = Tenant::lockForUpdate()->find($user->tenant_id);

                if (!$tenant) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tenant no encontrado'
                    ], 404);
                }

                $tenant->update($request->only(array_keys($request->rules())));

                return response()->json([
                    'success' => true,
                    'message' => 'Configuración de la empresa actualizada correctamente',
                    'data' => $tenant
                ]);
            });

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la configuración: ' . $e->getMessage()
            ], 500);
        }
    }
}
