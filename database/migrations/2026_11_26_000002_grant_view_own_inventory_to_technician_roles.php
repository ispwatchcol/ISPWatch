<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reparte `view_own_inventory` («Ver sólo mis equipos») a los roles técnicos.
 *
 * El ISP pidió (2026-11-26, bitácora § 138) que el técnico vea únicamente los
 * equipos que tiene asignados. El permiso nuevo abre la pantalla «Mis equipos»;
 * sin este reparto nacería apagado en todos lados (P-52: un permiso nuevo sin
 * backfill es una función muerta).
 *
 * A QUIÉN: a los roles con `code = 'technician'`. Es una capacidad de LECTURA
 * de lo propio: no le quita nada a nadie ni le abre lo de otros. A un rol que
 * ya tenga `view_inventory` no le cambia nada (ese ya lo ve todo); para que un
 * técnico vea SÓLO lo suyo, su rol tiene que quedarse sin `view_inventory`, y
 * eso lo decide cada ISP en Roles.
 *
 * IDEMPOTENTE: sólo añade lo que falta. No toca los roles con comodín `*`.
 */
return new class extends Migration
{
    private const PERMISOS = [Permissions::VIEW_OWN_INVENTORY];

    public function up(): void
    {
        $this->aplicar(agregar: true);
    }

    /** Seguro: `view_own_inventory` nace aquí, nadie lo tenía antes. */
    public function down(): void
    {
        $this->aplicar(agregar: false);
    }

    private function aplicar(bool $agregar): void
    {
        // Fila a fila: `role.permissions` es JSON y la suite corre en
        // PostgreSQL y SQLite. Mismo criterio que los demás backfills.
        DB::table('role')->orderBy('id')->chunkById(200, function ($roles) use ($agregar) {
            foreach ($roles as $rol) {
                $permisos = json_decode($rol->permissions ?? '[]', true);

                if (!is_array($permisos) || in_array('*', $permisos, true)) {
                    continue;
                }

                if ($agregar && $rol->code !== 'technician') {
                    continue;
                }

                $nuevos = $agregar
                    ? array_values(array_unique(array_merge($permisos, self::PERMISOS)))
                    : array_values(array_diff($permisos, self::PERMISOS));

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
