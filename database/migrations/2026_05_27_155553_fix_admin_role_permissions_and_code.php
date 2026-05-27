<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure codes are set (in case the previous migration missed any)
        DB::statement("
            UPDATE role SET code = CASE
                WHEN LOWER(name) LIKE '%administrador%' THEN 'admin'
                WHEN LOWER(name) LIKE '%staff%'         THEN 'staff'
                WHEN LOWER(name) LIKE '%cliente%'       THEN 'client'
                WHEN LOWER(name) LIKE '%contabilidad%'  THEN 'accounting'
                WHEN LOWER(name) LIKE '%tecnico%'
                  OR LOWER(name) LIKE '%técnico%'       THEN 'technician'
                ELSE LOWER(REGEXP_REPLACE(name, '[^a-zA-Z0-9]', '_', 'g'))
            END
            WHERE code IS NULL
        ");

        // Assign the full permission set to every admin role across all tenants
        $allPermissions = array_keys(
            array_merge(...array_values(\App\Constants\Permissions::getAllPermissions()))
        );

        $adminRoles = DB::table('role')->where('code', 'admin')->get(['id']);
        foreach ($adminRoles as $role) {
            DB::table('role')->where('id', $role->id)->update([
                'permissions' => json_encode($allPermissions),
            ]);
        }
    }

    public function down(): void
    {
        // Not reversible: permissions were in an unknown state before this migration
    }
};
