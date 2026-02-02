<?php

/** @noinspection PhpUndefinedVariableInspection */

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Traits\FixesSequences;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    use FixesSequences;
    /**
     * Display a listing of the users (staff members).
     */
    public function index(Request $request)
    {
        // got tenant_id from request (we sent from vue)
        $tenantId = $request->input('tenant_id');

        if (!$tenantId) {
            return response()->json([
                'message' => 'tenant_id es requerido'
            ], 400);
        }

        $users = User::with(['role', 'tenant',])
            ->where('tenant_id', $tenantId)
            ->where('status', true) // only active users
            ->orderBy('id', 'asc')
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
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
        ]);

        // Check if email exists for a disabled user
        $existingDisabledUser = User::where('email', $data['email'])
            ->where('status', false)
            ->first();

        if ($existingDisabledUser) {
            // Rename the old user's email to free it up
            $existingDisabledUser->email = $existingDisabledUser->email . '_deleted_' . time();
            $existingDisabledUser->save();
        }

        $data['password'] = Hash::make($data['password']);
        $data['status'] = true;
        $data['email_verified_at'] = now();
        $data['created_at'] = now();

        try {
            $user = User::create($data);
        } catch (\Illuminate\Database\QueryException $e) {
            // Check if it's a duplicate key error (sequence out of sync)
            if (str_contains($e->getMessage(), 'duplicate key') || str_contains($e->getMessage(), 'unique constraint')) {
                // Auto-fix the sequence and retry
                $this->fixSequence('users');
                $user = User::create($data);
            } else {
                throw $e;
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Usuario creado correctamente. ✅',
            'data' => $user,
        ], 201);
    }

    /**
     * Display the specified user.
     */
    public function show($id)
    {
        $user = User::with(['role', 'tenant'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'user_name' => $user->user_name,
                'user_lastname' => $user->user_lastname,
                'email' => $user->email,
                'email_tenant' => $user->email_tenant ?? $user->email,
                'tel' => $user->tel,
                'role_id' => $user->role_id,
                'role_name' => $user->role->name ?? 'Sin rol',
                'password' => '', // don't return password
            ]
        ]);
    }

    /**
     * Update the specified user in storage.
     */
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $data = $request->validate([
            'role_id' => 'sometimes|integer|exists:role,id',
            'user_name' => 'sometimes|string|max:255',
            'user_lastname' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'email_tenant' => 'nullable|string',
            'tel' => 'nullable|string|max:20',
            'password' => 'nullable|string|min:6',
        ]);

        // Check if email exists for a disabled user (other than the current one)
        if (isset($data['email'])) {
            $existingDisabledUser = User::where('email', $data['email'])
                ->where('status', false)
                ->where('id', '!=', $user->id) // don't rename our own email if we are disabled
                ->first();

            if ($existingDisabledUser) {
                // Rename the old user's email to free it up
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
            'message' => 'Usuario actualizado correctamente. ✅',
            'data' => $user,
        ]);
    }

    /**
     * Soft delete.
     */
    public function destroy($id)
    {
        $user = User::findOrFail($id);

        $user->update([
            'email' => $user->email . '_deleted_' . time(),
            'status' => false,
            'deleted_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Usuario desactivado correctamente. ✅',
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
