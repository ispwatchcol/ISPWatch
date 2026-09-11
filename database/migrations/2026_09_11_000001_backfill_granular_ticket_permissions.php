<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PR B · Reparte las capacidades de ticket que cada rol YA ejercía.
 *
 * EL PRINCIPIO: NADIE GANA NI PIERDE NADA
 *
 * El despliegue no puede cambiar lo que un usuario podía hacer ayer. Por eso el
 * reparto no se inventa: se deduce de las dos puertas que gobiernan hoy las
 * rutas de ticket, y se le da a cada rol exactamente lo que esas puertas ya le
 * dejaban pasar.
 *
 * LAS DOS PUERTAS DE HOY
 *
 *   A· `permission:view_support` — listar, crear, ver, editar (contenido,
 *      asignación, prioridad, categoría, diagnóstico y adjuntos), ver evidencia
 *      y leer el historial.
 *
 *   B· `staff_profile` — notas, transiciones de estado, cargos y estadísticas.
 *      NO comprueba un permiso: comprueba el CÓDIGO DE ROL (`admin` o `staff`)
 *      más el superadmin global. Es una puerta distinta y hay que respetarla
 *      tal cual, o roles que hoy no pueden anotar recibirían esa capacidad.
 *
 * EL ALGORITMO
 *
 *   1. Rol con comodín `*`  → no se toca. Ya lo tiene todo.
 *   2. Rol con `view_support` → recibe el grupo A.
 *   3. Rol con `code` ∈ {admin, staff} → recibe además el grupo B.
 *      Se comprueba el código y NO `view_support`: son puertas independientes,
 *      y un rol `staff` sin `view_support` sí puede anotar hoy.
 *   4. Rol sin `view_support` y sin código de personal → no recibe nada.
 *
 * LO QUE NO SE CONCEDE A NADIE
 *
 * `ticket_close_override`, `ticket_reopen`, `ticket_archive`, `ticket_restore`
 * y `ticket_manage_catalogs`. Esas acciones **no existen todavía** en el
 * sistema: archivar y restaurar son el PR C, reabrir no está ni diseñado, el
 * cierre con excepción llega con las reglas del PR #4 y no hay pantalla de
 * catálogos. Concederlas sería dar capacidades nuevas, justo lo contrario de
 * una transición compatible.
 *
 * `ticket_close` SÍ se concede junto al grupo B: cerrar un ticket hoy es poner
 * `status = closed`, que cualquiera con `staff_profile` puede hacer. Separarlo
 * sin concederlo quitaría una capacidad existente.
 *
 * `view_support` NO SE RETIRA. Sigue gobernando instalaciones, sectoriales e
 * inventario, y sigue siendo la llave de compatibilidad durante la transición.
 *
 * LOS ROLES DEFINITIVOS NO SE CONFIGURAN AQUÍ. La matriz de la sección 18 está
 * pendiente de confirmación del cliente (**D-09**).
 *
 * IDEMPOTENTE: sólo añade lo que falta y no reordena lo existente.
 */
return new class extends Migration
{
    /** Capacidades que abría `permission:view_support` en las rutas de ticket. */
    private const GRUPO_VIEW_SUPPORT = [
        Permissions::TICKET_VIEW,
        Permissions::TICKET_CREATE,
        Permissions::TICKET_EDIT,
        Permissions::TICKET_ASSIGN,
        Permissions::TICKET_SET_PRIORITY,
        Permissions::TICKET_SET_CATEGORY,
        Permissions::TICKET_DIAGNOSE,
        Permissions::TICKET_CONFIRM_CAUSE,
        Permissions::TICKET_ATTACH,
        Permissions::TICKET_VIEW_EVIDENCE,
        Permissions::TICKET_VIEW_HISTORY,
    ];

    /** Capacidades que abría `staff_profile` (código de rol admin/staff). */
    private const GRUPO_STAFF_PROFILE = [
        Permissions::TICKET_NOTE,
        Permissions::TICKET_TRANSITION,
        Permissions::TICKET_CLOSE,
        Permissions::TICKET_EXPORT,
    ];

    /** Códigos que `CheckStaffProfile` deja pasar. */
    private const CODIGOS_DE_PERSONAL = ['admin', 'staff'];

    public function up(): void
    {
        $this->aplicar(agregar: true);
    }

    /**
     * Quita los permisos granulares que esta migración repartió.
     *
     * Seguro porque `view_support` nunca se retiró: al revertir, las rutas
     * vuelven a exigirlo y todo el mundo recupera lo que tenía. Los permisos
     * que un administrador hubiera asignado a mano después también se pierden —
     * es el precio de un `down()` que no puede distinguir su origen, y por eso
     * revertir esta migración exige revertir también el código.
     */
    public function down(): void
    {
        $this->aplicar(agregar: false);
    }

    private function aplicar(bool $agregar): void
    {
        $todos = array_merge(self::GRUPO_VIEW_SUPPORT, self::GRUPO_STAFF_PROFILE);

        // Fila a fila y no con un UPDATE en bloque: `role.permissions` es JSON y
        // manipularlo en SQL exigiría operadores que difieren entre PostgreSQL y
        // SQLite, y la suite corre en los dos. Son decenas de filas.
        DB::table('role')->orderBy('id')->chunkById(200, function ($roles) use ($agregar, $todos) {
            foreach ($roles as $rol) {
                $permisos = json_decode($rol->permissions ?? '[]', true);

                if (!is_array($permisos)) {
                    continue;
                }

                // Un rol con comodín ya lo tiene todo; tocarlo sería ruido.
                if (in_array('*', $permisos, true)) {
                    continue;
                }

                $nuevos = $agregar
                    ? $this->concederA($rol, $permisos)
                    : array_values(array_diff($permisos, $todos));

                if ($nuevos === $permisos) {
                    continue;
                }

                DB::table('role')->where('id', $rol->id)->update([
                    'permissions' => json_encode(array_values($nuevos)),
                    'updated_at'  => now(),
                ]);
            }
        });
    }

    /**
     * @param  array<int, string>  $permisos
     * @return array<int, string>
     */
    private function concederA(object $rol, array $permisos): array
    {
        $conceder = [];

        if (in_array(Permissions::VIEW_SUPPORT, $permisos, true)) {
            $conceder = self::GRUPO_VIEW_SUPPORT;
        }

        if (in_array($rol->code ?? null, self::CODIGOS_DE_PERSONAL, true)) {
            $conceder = array_merge($conceder, self::GRUPO_STAFF_PROFILE);
        }

        if ($conceder === []) {
            return $permisos;
        }

        return array_values(array_unique(array_merge($permisos, $conceder)));
    }
};
