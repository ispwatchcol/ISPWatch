<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reparte `ticket_charge` a quien hoy cobra las visitas de un ticket.
 *
 * POR QUÉ HACE FALTA
 *
 * Las operaciones de ticket —notas, cambios de estado, cerrar, reabrir, cobros
 * y estadísticas— vivían detrás de `staff_profile` (CheckStaffProfile), que no
 * mira permisos: deja pasar a los roles con `code` `admin` o `staff`. Un técnico
 * con «Tickets · cerrar» marcado en Roles veía el botón y la API le respondía
 * 403 (reporte del 2026-11-26, P-45). Esa puerta se retira: cada ruta queda
 * gobernada sólo por su permiso, que es lo que se configura en Roles.
 *
 * Todas tenían ya su permiso encima (PR B) salvo los COBROS, que sólo tenían
 * `staff_profile`. Sin este reparto, al retirarla nadie podría cobrar —el
 * mismo fallo que P-52: un permiso nuevo sin backfill es una función muerta—.
 *
 * A QUIÉN
 *
 * A exactamente quien `staff_profile` dejaba pasar: los roles con `code`
 * `admin` o `staff`. Nadie gana ni pierde nada; desde aquí, dar o quitar el
 * cobro a otro rol es una casilla en Roles.
 *
 * El superadministrador (`role_id` 1) no lo necesita: CheckPermission lo deja
 * pasar siempre. Los roles con comodín `*` ya lo tienen todo.
 *
 * IDEMPOTENTE: sólo añade lo que falta y no reordena lo existente.
 *
 * DESPLIEGUE: correr ANTES del código (`migrate:both`). El código viejo no mira
 * `ticket_charge`, así que tenerlo antes no cambia nada; el código nuevo sin
 * este reparto dejaría a Staff sin cobrar hasta que se migre.
 */
return new class extends Migration
{
    /** Los códigos que CheckStaffProfile dejaba pasar. */
    private const CODIGOS_QUE_COBRABAN = ['admin', 'staff'];

    private const PERMISOS = [Permissions::TICKET_CHARGE];

    public function up(): void
    {
        $this->aplicar(agregar: true);
    }

    /**
     * Retira sólo lo que esta migración concedió. Seguro porque `ticket_charge`
     * nace aquí: nadie lo tenía antes. Revertirla exige revertir también el
     * código (las rutas de cobro volverían a quedar sin puerta de rol).
     */
    public function down(): void
    {
        $this->aplicar(agregar: false);
    }

    private function aplicar(bool $agregar): void
    {
        // Fila a fila y no en SQL: `role.permissions` es JSON y manipularlo en
        // el motor exigiría operadores distintos en PostgreSQL y SQLite (la
        // suite corre en los dos). Mismo criterio que los backfills del PR B.
        DB::table('role')->orderBy('id')->chunkById(200, function ($roles) use ($agregar) {
            foreach ($roles as $rol) {
                $permisos = json_decode($rol->permissions ?? '[]', true);

                if (!is_array($permisos)) {
                    continue;
                }

                // Un rol con comodín ya lo tiene todo; tocarlo sería ruido.
                if (in_array('*', $permisos, true)) {
                    continue;
                }

                if ($agregar && !in_array($rol->code, self::CODIGOS_QUE_COBRABAN, true)) {
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
