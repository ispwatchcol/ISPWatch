<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Constants\Permissions;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    /**
     * Display a listing of all roles.
     */
    public function index()
    {
        $tenantId = auth()->user()->tenant_id;

        // Tenant-specific roles PLUS global roles (tenant_id NULL), so tenants
        // that predate per-tenant role seeding still see their assigned roles.
        // A global role shadowed by a tenant role with the same name is omitted
        // (mirrors the preference in Role::idByName). Global roles are flagged
        // is_global: they are shared across tenants, so the UI must not offer
        // edit/delete for them (update/destroy reject them anyway).
        $all = Role::withoutGlobalScope('tenant')
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            })
            ->orderBy('name')
            ->get();

        $tenantNames = $all->whereNotNull('tenant_id')->pluck('name')->all();

        $roles = $all
            ->reject(fn($role) => $role->tenant_id === null && in_array($role->name, $tenantNames))
            ->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'permissions' => $role->permissions ?? [],
                    'is_global' => $role->tenant_id === null,
                    'created_at' => $role->created_at,
                    'updated_at' => $role->updated_at,
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => $roles,
        ]);
    }

    /**
     * Get all available permissions for UI selection
     */
    public function permissions()
    {
        $allPermissions = Permissions::getAllPermissions();

        return response()->json([
            'success' => true,
            'data' => [
                'available' => $allPermissions,
            ],
        ]);
    }

    /**
     * Store a newly created role.
     */
    public function store(Request $request)
    {
        $tenantId = auth()->user()->tenant_id;

        $data = $request->validate([
            'name' => 'required|string|max:255|unique:role,name,NULL,id,tenant_id,' . $tenantId,
            'permissions' => 'nullable|array',
        ]);

        $data['permissions'] = $data['permissions'] ?? [];
        $data['tenant_id'] = $tenantId;

        $role = Role::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Rol creado correctamente.',
            'data' => $role,
        ], 201);
    }

    /**
     * Display the specified role.
     */
    public function show($id)
    {
        $tenantId = auth()->user()->tenant_id;

        // Readable: own tenant roles AND global roles. Global stays read-only —
        // update/destroy below keep the tenant-only filter.
        $role = Role::withoutGlobalScope('tenant')
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            })
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions ?? [],
                'is_global' => $role->tenant_id === null,
                'created_at' => $role->created_at,
                'updated_at' => $role->updated_at,
            ],
        ]);
    }

    /**
     * Update the specified role.
     */
    public function update(Request $request, $id)
    {
        $tenantId = auth()->user()->tenant_id;
        $role = Role::where('tenant_id', $tenantId)->findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255|unique:role,name,' . $role->id . ',id,tenant_id,' . $tenantId,
            'permissions' => 'nullable|array',
        ]);

        if (isset($data['permissions'])) {
            $data['permissions'] = $data['permissions'];
        }

        $role->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Rol actualizado correctamente.',
            'data' => $role,
        ]);
    }

    /**
     * Delete the specified role.
     */
    public function destroy($id)
    {
        $tenantId = auth()->user()->tenant_id;
        $role = Role::where('tenant_id', $tenantId)->findOrFail($id);

        // Prevent deletion of core roles (check both code and name)
        $coreCodes = ['admin', 'client', 'technician', 'accounting', 'staff'];
        $coreNames = ['Administrador', 'Cliente', 'Tecnico', 'Técnico', 'Contabilidad', 'Staff'];

        if (in_array($role->code, $coreCodes) || in_array($role->name, $coreNames)) {
            return response()->json([
                'success' => false,
                'message' => 'No se pueden eliminar los roles predefinidos.',
            ], 403);
        }

        // Check if role is in use
        if ($role->users()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar un rol que está en uso.',
            ], 403);
        }

        $role->delete();

        return response()->json([
            'success' => true,
            'message' => 'Rol eliminado correctamente.',
        ]);
    }
}
