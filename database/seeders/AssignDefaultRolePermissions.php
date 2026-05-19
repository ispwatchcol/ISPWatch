<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Constants\Permissions;
use Illuminate\Database\Seeder;

class AssignDefaultRolePermissions extends Seeder
{
    public function run(): void
    {
        $roles = [
            'Administrador' => Permissions::getPermissionsByRole('Administrador'),
            'Técnico' => Permissions::getPermissionsByRole('Técnico'),
            'Contabilidad' => Permissions::getPermissionsByRole('Contabilidad'),
            'Cliente' => [],
        ];

        foreach ($roles as $roleName => $permissions) {
            $role = Role::where('name', $roleName)->first();
            if ($role) {
                $role->update(['permissions' => $permissions]);
            }
        }
    }
}
