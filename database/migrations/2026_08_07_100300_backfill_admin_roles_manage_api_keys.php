<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * La API pública agrega el permiso `manage_api_keys`, pero la autorización
     * lee la columna JSON `role.permissions` sembrada en la base — no
     * Permissions::getPermissionsByRole(). Sin este backfill los roles admin
     * existentes quedarían con el set incompleto y la pestaña "Llaves API" no
     * aparecería para nadie, sin ningún error visible que lo explique. Es
     * exactamente lo que pasó con `manage_document_templates`.
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
