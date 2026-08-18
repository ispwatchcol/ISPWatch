<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * El auto-servicio agrega `manage_own_api_keys`, y la autorización lee la
     * columna JSON `role.permissions` sembrada en la base — no
     * Permissions::getPermissionsByRole(). Sin este backfill, los roles admin
     * que ya existen se quedan con el set anterior y la pestaña "Llaves API"
     * no le aparece a nadie, **sin ningún error visible que lo explique**.
     *
     * Es exactamente lo que pasó con `manage_document_templates` y con
     * `manage_api_keys`. Tercera vez: la lección es que todo permiso nuevo
     * necesita su migración de backfill en el MISMO PR que lo introduce.
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
