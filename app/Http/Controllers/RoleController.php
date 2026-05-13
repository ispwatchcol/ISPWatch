<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Constants\Permissions;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $roles = Role::all();

        return response()->json([
            'success' => true,
            'data' => $roles,
        ]);
    }

    /**
     * Get all available permissions and role default permissions
     */
    public function permissions()
    {
        $allPermissions = Permissions::getAllPermissions();
        $roles = Role::all();

        $rolePermissions = [];
        foreach ($roles as $role) {
            $rolePermissions[$role->name] = Permissions::getPermissionsByRole($role->name);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'available' => $allPermissions,
                'roleDefaults' => $rolePermissions,
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Role $role)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Role $role)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Role $role)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Role $role)
    {
        //
    }
}
