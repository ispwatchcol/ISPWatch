<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['id' => 1, 'name' => 'Administrador'],
            ['id' => 2, 'name' => 'Staff'],
            ['id' => 3, 'name' => 'Cliente'],
        ];

        foreach ($roles as $role) {
            DB::table('role')->updateOrInsert(
                ['id' => $role['id']],
                array_merge($role, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
