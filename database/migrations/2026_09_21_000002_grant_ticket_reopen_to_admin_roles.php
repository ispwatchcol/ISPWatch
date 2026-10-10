<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reparte `ticket_reopen` a los roles de administración.
 *
 * EL FALLO QUE ESTO CIERRA
 *
 * El PR #4 declaró el endpoint de reapertura, su permiso y su matriz — pero **no
 * repartió el permiso a nadie**. El resultado es que un Administrador abría un
 * ticket cerrado y no encontraba el botón «Reabrir» por ninguna parte: no era un
 * error de interfaz, era que la API le habría respondido 403 y la pantalla no
 * ofrece lo que el servidor va a rechazar.
 *
 * Medido en la base antes de corregir: los cinco roles `admin` tenían 17 de los
 * 20 permisos `ticket_*`. Faltaban exactamente los tres que ninguna migración
 * llegó a repartir — `ticket_reopen`, `ticket_close_override` y
 * `ticket_manage_catalogs`.
 *
 * POR QUÉ FALTABA, Y NO ES UN DESPISTE AISLADO
 *
 * `Permissions::getPermissionsByRole('admin')` devuelve la lista completa, pero
 * eso sólo se consulta al CREAR un rol. Los roles que ya existen conservan su
 * lista guardada, así que cada permiso nuevo necesita su backfill — es la regla
 * 5 del manual del desarrollador, y el PR B y el PR C sí la siguieron.
 *
 * SÓLO `ticket_reopen`, Y SÓLO A `admin`
 *
 * `ticket_close_override` autoriza cerrar **incumpliendo** las reglas del §15:
 * es una potestad de excepción, y repartirla por migración a todos los
 * administradores sería tomar por el cliente una decisión que el §18 le asigna
 * al Supervisor. Se deja sin conceder a conciencia, documentado en el
 * seguimiento. `ticket_manage_catalogs` sigue sin endpoint (**D-13**).
 *
 * Reabrir es distinto: es parte del ciclo de vida que el documento describe
 * —«Reabierto» es uno de los nueve estados auxiliares del §7, y la modalidad
 * STR del Anexo B es «la afectación reaparece después del cierre»— y sin él el
 * flujo no se puede recorrer entero. No es una excepción: es una operación
 * ordinaria que estaba inalcanzable.
 *
 * NO se concede a `staff` ni a `technician`: reabrir revierte una decisión de
 * cierre, y el §18 sitúa esa autoridad en el Supervisor. Un ISP que quiera
 * dársela a otro rol lo hace desde la pantalla de roles — configuración, no
 * despliegue.
 *
 * ESTA MIGRACIÓN SÓLO TOCA `role.permissions`. No hay cambio de esquema y no se
 * mueve ni un ticket.
 */
return new class extends Migration
{
    private const PERMISO = Permissions::TICKET_REOPEN;

    /** Ver la cabecera: sólo administración, y a conciencia. */
    private const CODIGOS_CON_ACCESO = ['admin'];

    public function up(): void
    {
        $this->aplicar(agregar: true);
    }

    /**
     * Revertirla vuelve a dejar la reapertura inalcanzable desde la interfaz.
     * NO cambia el estado de ningún ticket ya reabierto: el estado es un dato
     * del expediente, no un permiso.
     */
    public function down(): void
    {
        $this->aplicar(agregar: false);
    }

    private function aplicar(bool $agregar): void
    {
        // Fila a fila por lo mismo que los backfills anteriores:
        // `role.permissions` es JSON y manipularlo en SQL exigiría operadores
        // que difieren entre PostgreSQL y SQLite, y la suite corre en los dos.
        DB::table('role')->orderBy('id')->chunkById(200, function ($roles) use ($agregar) {
            foreach ($roles as $rol) {
                $permisos = json_decode($rol->permissions ?? '[]', true);

                if (!is_array($permisos)) {
                    continue;
                }

                // Un rol con comodín ya lo tiene todo; tocarlo sería ruido. Y el
                // comodín NO sustituye a la matriz: `CheckPermission` lo acepta,
                // pero la transición se sigue validando en el controlador.
                if (in_array('*', $permisos, true)) {
                    continue;
                }

                if ($agregar && !in_array($rol->code ?? null, self::CODIGOS_CON_ACCESO, true)) {
                    continue;
                }

                $nuevos = $agregar
                    ? array_values(array_unique([...$permisos, self::PERMISO]))
                    : array_values(array_diff($permisos, [self::PERMISO]));

                // Idempotente: si ya lo tenía, no se escribe ni se toca
                // `updated_at`.
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
