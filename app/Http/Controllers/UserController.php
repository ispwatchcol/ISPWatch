<?php

/** @noinspection PhpUndefinedVariableInspection */

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Constants\Permissions;
use App\Traits\FixesSequences;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class UserController extends Controller
{
    use FixesSequences;

    /**
     * Display a listing of the users (staff members).
     */
    public function index(Request $request)
    {
        $tenantId = $request->input('tenant_id');

        if (!$tenantId) {
            return response()->json([
                'message' => 'tenant_id es requerido',
            ], 400);
        }

        $users = User::with(['role', 'tenant'])
            ->where('tenant_id', $tenantId)
            ->where('status', true)
            ->orderBy('id', 'asc')
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'user_name' => $user->user_name,
                    'user_lastname' => $user->user_lastname ?? '',
                    'email_tenant' => $user->email_tenant ?? $user->email,
                    'email' => $user->email,
                    'tel' => $user->tel,
                    'role_name' => $user->role->name ?? 'Sin rol',
                    'role_id' => $user->role_id,
                    'tenant_id' => $user->tenant_id,
                    'create_at' => $user->created_at,
                    'last_access' => $user->last_access ?? null,
                    'status' => $user->status,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    /**
     * Store a newly created user (staff member) in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'tenant_id' => 'required|integer|exists:tenant,id',
            'role_id' => 'required|integer|exists:role,id',
            'name' => 'required|string|max:255',
            'user_name' => 'required|string|max:255',
            'user_lastname' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'email_tenant' => 'nullable|string',
            'tel' => 'nullable|string|max:20',
            'password' => 'required|string|min:6',
            'permissions' => 'nullable|array',
        ]);

        $existingDisabledUser = User::where('email', $data['email'])
            ->where('status', false)
            ->first();

        if ($existingDisabledUser) {
            $existingDisabledUser->email = $existingDisabledUser->email . '_deleted_' . time();
            $existingDisabledUser->save();
        }

        $data['password'] = Hash::make($data['password']);
        $data['status'] = true;
        $data['created_at'] = now();

        // If no permissions provided, assign default permissions based on role
        if (empty($data['permissions'])) {
            $role = Role::find($data['role_id']);
            if ($role) {
                $data['permissions'] = Permissions::getPermissionsByRole($role->name);
            } else {
                $data['permissions'] = [];
            }
        }

        try {
            $user = User::create($data);
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'duplicate key') || str_contains($e->getMessage(), 'unique constraint')) {
                $this->fixSequence('users');
                $user = User::create($data);
            } else {
                throw $e;
            }
        }

        // Staff created internally is pre-verified — no email confirmation required.
        $user->markEmailAsVerified();

        return response()->json([
            'success' => true,
            'message' => 'Usuario creado correctamente.',
            'data' => $user,
        ], 201);
    }

    /**
     * Display the specified user.
     */
    public function show($id)
    {
        $user = User::with(['role', 'tenant'])->findOrFail($id);
        $userPermissions = $user->permissions ?? [];

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'user_name' => $user->user_name,
                'user_lastname' => $user->user_lastname,
                'email' => $user->email,
                'email_tenant' => $user->email_tenant ?? $user->email,
                'tel' => $user->tel,
                'role_id' => $user->role_id,
                'role_name' => $user->role->name ?? 'Sin rol',
                'permissions' => $userPermissions,
                'password' => '',
            ],
        ]);
    }

    /**
     * Update the specified user in storage.
     */
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'role_id' => 'sometimes|integer|exists:role,id',
            'user_name' => 'sometimes|string|max:255',
            'user_lastname' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'email_tenant' => 'nullable|string',
            'tel' => 'nullable|string|max:20',
            'password' => 'nullable|string|min:6',
            'permissions' => 'nullable|array',
        ]);

        if (isset($data['email'])) {
            $existingDisabledUser = User::where('email', $data['email'])
                ->where('status', false)
                ->where('id', '!=', $user->id)
                ->first();

            if ($existingDisabledUser) {
                $existingDisabledUser->email = $existingDisabledUser->email . '_deleted_' . time();
                $existingDisabledUser->save();
            }
        }

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $data['updated_at'] = now();

        $user->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Usuario actualizado correctamente.',
            'data' => $user,
        ]);
    }

    /**
     * Soft delete.
     */
    public function destroy($id)
    {
        $user = User::findOrFail($id);

        $updates = [
            'email' => $user->email . '_deleted_' . time(),
            'status' => false,
        ];

        if (Schema::hasColumn('users', 'deleted_at')) {
            $updates['deleted_at'] = now();
        }

        $user->update($updates);

        return response()->json([
            'success' => true,
            'message' => 'Usuario desactivado correctamente.',
        ]);
    }

    /**
     * Fix PostgreSQL sequence for a table.
     */
    private function fixSequence(string $table): void
    {
        $maxId = \Illuminate\Support\Facades\DB::table($table)->max('id') ?? 0;
        $newValue = $maxId + 1;
        \Illuminate\Support\Facades\DB::statement("SELECT setval('{$table}_id_seq', {$newValue}, false)");
    }
}
