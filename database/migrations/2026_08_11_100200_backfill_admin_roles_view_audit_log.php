<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * La bitácora de auditoría agrega el permiso `view_audit_log`, pero la
     * autorización lee la columna JSON `role.permissions` sembrada en la base —
     * no Permissions::getPermissionsByRole(). Sin este backfill los roles admin
     * existentes se quedarían sin la pestaña "Auditoría" y sin ningún error que
     * lo explicara. Ya pasó dos veces: `manage_document_templates` y
     * `manage_api_keys`.
     *
     * Idempotente: reasignar el set completo no hace daño si ya lo tienen.
     */
    public function up(): void
    {
        $allPermissions = array_keys(
            array_merge(...array_values(\App\Constants\Permissions::getAllPermissions()))
        );

        DB::table('role')
            ->where('code', 'admin')
            ->update(['permissions' => json_encode($allPermissions)]);
    }

    public function down(): void
    {
        // No reversible: ver 2026_07_27_120000.
    }
};
