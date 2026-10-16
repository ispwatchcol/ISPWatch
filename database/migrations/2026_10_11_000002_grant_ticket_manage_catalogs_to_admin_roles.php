<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reparte `ticket_manage_catalogs` a los roles de administración.
 *
 * El permiso se declaró en el PR B y quedó «sin uso» (D-13): no había ninguna
 * pantalla ni endpoint que administrara catálogos del ticket. Con el catálogo
 * de ASUNTOS (`ticket_subjects`) por fin tiene algo que gobernar: crear,
 * renombrar, ordenar, retirar y borrar los asuntos frecuentes de cada ISP.
 *
 * D-13 se delegó en el equipo el 2026-09-11 («va dentro de roles y
 * permisos»). La decisión aquí es la misma que con `ticket_reopen`: se le da
 * a `admin`, que es quien configura el resto del módulo, y a nadie más. Un ISP
 * que quiera que su supervisor también administre los asuntos lo hace desde
 * la pantalla de roles — configuración, no despliegue.
 *
 * Sin este backfill el botón «Asuntos» no aparecería a ningún administrador
 * existente: `getPermissionsByRole('admin')` sólo se consulta al CREAR un rol,
 * y la pantalla lee `role.permissions` guardado (regla 5 del manual del
 * desarrollador).
 *
 * ESTA MIGRACIÓN SÓLO TOCA `role.permissions`. No hay cambio de esquema.
 */
return new class extends Migration
{
    private const PERMISO = Permissions::TICKET_MANAGE_CATALOGS;

    private const CODIGOS_CON_ACCESO = ['admin'];

    public function up(): void
    {
        $this->aplicar(agregar: true);
    }

    public function down(): void
    {
        $this->aplicar(agregar: false);
    }

    private function aplicar(bool $agregar): void
    {
        // Fila a fila, como los backfills anteriores: `role.permissions` es
        // JSON y manipularlo en SQL exigiría operadores distintos en
        // PostgreSQL y SQLite.
        DB::table('role')->orderBy('id')->chunkById(200, function ($roles) use ($agregar) {
            foreach ($roles as $rol) {
                $permisos = json_decode($rol->permissions ?? '[]', true);

                if (!is_array($permisos) || in_array('*', $permisos, true)) {
                    continue;
                }

                if ($agregar && !in_array($rol->code ?? null, self::CODIGOS_CON_ACCESO, true)) {
                    continue;
                }

                $nuevos = $agregar
                    ? array_values(array_unique([...$permisos, self::PERMISO]))
                    : array_values(array_diff($permisos, [self::PERMISO]));

                if ($nuevos === $permisos) {
                    continue;
                }

                DB::table('role')->where('id', $rol->id)->update([
                    'permissions' => json_encode($nuevos),
                    'updated_at'  => now(),
                ]);
            }
        });
    }
};
