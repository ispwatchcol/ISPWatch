<?php

namespace App\Console\Commands;

use App\Constants\Permissions;
use App\Models\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reconcilia los roles canónicos con el catálogo de permisos del código.
 *
 * El problema que resuelve: la autorización lee `role.permissions` (una columna
 * JSON **sembrada en la base de datos**), mientras que
 * `Permissions::getPermissionsByRole()` declara en el código qué permisos
 * corresponde a cada rol. Nada reconciliaba ambas fuentes, así que cada permiso
 * nuevo exigía acordarse de escribir una migración de backfill a mano — y
 * olvidarlo no produce ningún error: simplemente desaparece una pestaña para
 * todos los administradores, que es lo que pasó con `manage_document_templates`
 * (admins con 34 de 35 permisos sin ver Plantillas).
 *
 * Es ADITIVO por diseño: sólo añade permisos que falten. Nunca quita ninguno,
 * porque un tenant puede haber ajustado a mano los permisos de su rol y una
 * sincronización no debe deshacer ese trabajo. Para quitar permisos está la
 * pantalla de Roles.
 *
 * Los roles se identifican por `code` (admin/staff/technician/accounting/client),
 * no por `name`: el nombre es texto libre por tenant ('Tecnico' vs 'Técnico').
 */
class SyncRolePermissions extends Command
{
    protected $signature = 'permissions:sync
                            {--dry-run : Muestra qué cambiaría sin escribir nada}
                            {--tenant= : Limita la sincronización a un tenant}';

    protected $description = 'Añade a los roles canónicos los permisos que les falten según App\Constants\Permissions (aditivo: nunca quita).';

    /** code de la tabla `role` => clave que entiende Permissions::getPermissionsByRole(). */
    private const CODE_TO_ROLE = [
        'admin'      => 'admin',
        'staff'      => 'staff',
        'technician' => 'technician',
        'accounting' => 'accounting',
        'client'     => 'client',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenant = $this->option('tenant');

        $query = Role::withoutGlobalScope('tenant')->orderBy('id');
        if ($tenant !== null) {
            $query->where('tenant_id', $tenant);
        }

        $roles = $query->get();

        if ($roles->isEmpty()) {
            $this->warn('No hay roles que sincronizar.');
            return self::SUCCESS;
        }

        $actualizados = 0;
        $saltados     = 0;
        $filas        = [];

        foreach ($roles as $role) {
            $code = $role->code;

            // Rol personalizado (sin code canónico): no se toca. Su conjunto de
            // permisos es una decisión deliberada del tenant.
            if (!$code || !isset(self::CODE_TO_ROLE[$code])) {
                $saltados++;
                continue;
            }

            $esperados = Permissions::getPermissionsByRole(self::CODE_TO_ROLE[$code]);
            $actuales  = is_array($role->permissions) ? $role->permissions : [];

            // El comodín ya lo concede todo: no hay nada que añadir.
            if (in_array('*', $actuales, true)) {
                $saltados++;
                continue;
            }

            $faltantes = array_values(array_diff($esperados, $actuales));

            if ($faltantes === []) {
                $saltados++;
                continue;
            }

            $filas[] = [
                $role->id,
                $role->name,
                $role->tenant_id ?? 'global',
                count($faltantes),
                implode(', ', array_slice($faltantes, 0, 4)) . (count($faltantes) > 4 ? '…' : ''),
            ];

            if (!$dryRun) {
                // Escritura directa: evita el modelo para no disparar el global
                // scope de tenant ni observadores en un comando de mantenimiento.
                DB::table('role')->where('id', $role->id)->update([
                    'permissions' => json_encode(array_values(array_unique(
                        array_merge($actuales, $faltantes)
                    ))),
                    'updated_at'  => now(),
                ]);
            }

            $actualizados++;
        }

        if ($filas !== []) {
            $this->table(['ID', 'Rol', 'Tenant', 'Faltaban', 'Permisos añadidos'], $filas);
        }

        $verbo = $dryRun ? 'se actualizarían' : 'actualizados';
        $this->info("Roles {$verbo}: {$actualizados}. Sin cambios: {$saltados}.");

        if ($dryRun && $actualizados > 0) {
            $this->comment('Ejecuta sin --dry-run para aplicar.');
        }

        return self::SUCCESS;
    }
}
