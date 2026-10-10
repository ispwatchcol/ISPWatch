<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PR C · Reparte `ticket_archive` y `ticket_restore`.
 *
 * A QUIÉN Y POR QUÉ A ESE
 *
 * CNO aprobó el archivado «para Administradores y Propietarios» (confirmación
 * por chat del 11/09/2026). **En ISPWatch no existe un rol «Propietario»**: se
 * verificó contra la base y los `code` son `admin`, `staff`, `technician`,
 * `accounting` y `client`, iguales en los cinco tenants. La única figura por
 * encima del administrador es el superadministrador global (`role_id == 1`), que
 * no es un rol de tenant sino un bypass de `CheckPermission`.
 *
 * Así que se concede a `code = 'admin'`, y el superadministrador pasa igual sin
 * que haga falta darle nada. Queda registrado como supuesto **S-1** del diseño
 * para que el cliente pueda rebatirlo: si «Propietario» designa otra figura, es
 * un rol nuevo y entra por el PR E, no por aquí.
 *
 * POR QUÉ NO A `staff`
 *
 * Archivar retira un expediente de la operación. El PR B ya estableció que los
 * dos permisos no se repartían a nadie porque la acción no existía todavía;
 * ahora existe, y darla al perfil de personal entero contradiría la aprobación,
 * que nombra dos roles y no cuatro. Un ISP que quiera concedérselo a su Staff lo
 * hace desde la pantalla de roles: es configuración, no despliegue.
 *
 * ESTA MIGRACIÓN SÓLO TOCA `role.permissions`. No hay cambio de esquema.
 */
return new class extends Migration
{
    private const PERMISOS = [
        Permissions::TICKET_ARCHIVE,
        Permissions::TICKET_RESTORE,
    ];

    /** Ver la cabecera: «Propietario» no existe, `admin` es lo que hay. */
    private const CODIGOS_CON_ACCESO = ['admin'];

    public function up(): void
    {
        $this->aplicar(agregar: true);
    }

    /**
     * Retira los dos permisos de los roles a los que esta migración se los dio.
     *
     * Revertirla NO desarchiva nada: los tickets archivados siguen archivados y
     * dejan de poder restaurarse desde la interfaz. Por eso revertir esta
     * migración exige revertir también la de esquema, que es la que devuelve los
     * tickets a la vista.
     */
    public function down(): void
    {
        $this->aplicar(agregar: false);
    }

    private function aplicar(bool $agregar): void
    {
        // Fila a fila por lo mismo que el backfill del PR B: `role.permissions`
        // es JSON y manipularlo en SQL exigiría operadores que difieren entre
        // PostgreSQL y SQLite, y la suite corre en los dos.
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

                if ($agregar && !in_array($rol->code ?? null, self::CODIGOS_CON_ACCESO, true)) {
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
