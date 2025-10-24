<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * Display a listing of the users.
     */
    public function index()
    {
        $users = User::with([
            'role', 'tenant', 'sectorial',
        ])->select(
            'id',
            'user_name',
            'email',
            'role_id',
            'tenant_id',
            'sectorial_id',
            'created_at',
        )->get()->map(
            function ($user) {
                return [
                    'id' => $user->id,
                    'user_name' => $user->user_name,
                    'email' => $user->email,
                    'role' => $user->role->name ?? '-',
                    'tenant' => $user->tenant->name ?? '-',
                    'sectorial' => $user->sectorial->name ?? '-',
                    'created_at' => $user->created_at->format('Y-m-d'),
                ];
            }
        );

        return response()->json($users);
    }

    /**
     * Store a newly created user in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'tenant_id' => 'nullable|integer|exists:tenant, id',
            'role_id' => 'required|integer|exists:role, id',
            'sectorial_id' => 'nullable|integer|exists:sectorial, id',
            'user_name' => 'required|string|max:255|unique:user,user_name',
            'email' => 'required|email|unique:user,email',
            'password' => 'required|string|min:6',
        ]);

        $data['password'] = Hash::make($data['password']);

        $user = User::create($data);

        return response()->json([
            'message' => 'Usuario creado correctamente. ✅',
            'user' => $user,
        ], 201);
    }

    /**
     * Display the specified user.
     */
    public function show(User $user)
    {
        return response()->json($user->load(['role', 'tenant', 'sectorial']));
    }

    /**
     * Update the specified user in storage.
     */
    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'tenant_id' => 'nullable|integer|exists:tenant,id',
            'role_id' => 'sometimes|integer|exists:role,id',
            'sectorial_id' => 'nullable|integer|exists:sectorial,id',
            'user_name' => 'sometimes|string|max:255|unique:user,user_name,' . $user->id,
            'email' => 'sometimes|email|unique:user,email,' . $user->id,
            'password' => 'nullable|string|min:6',
        ]);

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);

        return response()->json([
            'message' => 'Usuario actualizado correctamente. ✅',
            'user' => $user,
        ]);
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy(User $user)
    {
        $user->delete();

        return response()->json([
            'message' => 'Usuario eliminado correctamente. ✅',
        ]);
    }
}
