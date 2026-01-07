<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Update roles with permissions
        DB::table('role')->where('id', 1)->update([
            'permissions' => json_encode(['*']) // Admin - all permissions
        ]);

        DB::table('role')->where('id', 2)->update([
            'permissions' => json_encode([
                'support.view',
                'support.create',
                'support.update',
                'support.delete',
                'support.statistics',
                'customers.view',
                'customers.create',
                'customers.update',
            ]) // Staff
        ]);

        DB::table('role')->where('id', 3)->update([
            'permissions' => json_encode([
                'support.view.own',
                'support.create',
            ]) // Cliente
        ]);

        echo "✅ Role permissions seeded successfully!\n";
    }
}
