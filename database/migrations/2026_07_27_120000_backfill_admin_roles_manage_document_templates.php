<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * #195 (plantillas de documentos) agregó el permiso `manage_document_templates`
     * a Permissions::getAllPermissions() y a getPermissionsByRole('admin'), pero los
     * permisos que la app realmente lee viven en la columna JSON `role.permissions`,
     * que se sembró ANTES de #195. Resultado: todos los roles admin existentes quedan
     * con el set incompleto (les falta justo ese permiso) y ningún administrador ve la
     * pestaña "Plantillas de Documentos" en Configuración.
     *
     * Re-sincroniza cada rol admin (de todos los tenants, y el global) al set completo
     * de permisos — mismo enfoque que 2026_05_27_155553_fix_admin_role_permissions_and_code.
     * Idempotente: volver a asignar el set completo no hace daño si ya lo tienen.
     */
    public function up(): void
    {
        $allPermissions = array_keys(
            array_merge(...array_values(\App\Constants\Permissions::getAllPermissions()))
        );

        // DB::table (query builder) ignora el global scope de tenant del modelo Role,
        // así que alcanza los roles admin de TODOS los tenants en una sola sentencia.
        DB::table('role')
            ->where('code', 'admin')
            ->update(['permissions' => json_encode($allPermissions)]);
    }

    public function down(): void
    {
        // No reversible: quitar un permiso que los admin legítimamente necesitan sería
        // peor que el desfase que esta migración corrige.
    }
};
