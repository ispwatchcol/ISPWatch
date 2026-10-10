<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `delete_inventory` sólo para roles con autoridad administrativa. KAN-99.
 *
 * QUÉ CAMBIA EN LA PRÁCTICA
 *
 * Hasta ahora los cuatro `destroy` del grupo de inventario —equipos, stock,
 * proveedores y sucursales— exigían `view_inventory`, un permiso de LECTURA.
 * Cualquier rol que pudiera abrir la pantalla de inventario podía vaciarla.
 *
 * El fallo es preexistente; lo que cambió es el alcance. Mientras ninguna
 * pantalla expusiera el borrado de equipos era teórico. KAN-98 añadió el botón
 * Eliminar en la tarjeta de equipo, y con él pasó a estar a un clic — para el
 * rol `Staff` incluido, que trae `view_inventory` de fábrica.
 *
 * POR QUÉ `code` Y NO EL NOMBRE NI EL ID
 *
 * Los roles son por tenant: el id varía entre ISP y el nombre es editable. El
 * `code` es el identificador estable, y es el mismo criterio que ya usaron
 * `CheckStaffProfile`, `AuthController` y la migración hermana de
 * `delete_customers` (2026_08_31_000001).
 *
 * NO SE CONCEDE POR ARRASTRE
 *
 * Sería más cómodo dárselo a todo rol que hoy tenga `view_inventory` —no habría
 * regresión de ningún tipo— pero eso dejaría exactamente el agujero que esta
 * migración viene a cerrar. La retirada de la capacidad es el objetivo.
 *
 * POR QUÉ HACE FALTA ESTA MIGRACIÓN Y NO BASTA EL SEEDER
 *
 * Un permiso nuevo NO llega solo a los roles existentes: el frontend lee
 * `role.permissions` de la base, no `getPermissionsByRole()`, y no hay bypass
 * de superadministrador. Sin este relleno los administradores verían el
 * inventario sin poder borrar nada y sin explicación. Ya pasó con
 * `manage_document_templates`.
 *
 * LO QUE ESTA MIGRACIÓN NO HACE
 *
 * No toca `view_inventory`: sigue existiendo y sigue autorizando ver, crear y
 * editar. Sólo deja de autorizar el borrado.
 */
return new class extends Migration
{
    /** Código de rol con autoridad administrativa inequívoca. */
    private const CODIGO_ADMIN = 'admin';

    public function up(): void
    {
        $this->aplicar(agregar: true);
    }

    /**
     * Quita el permiso de los roles a los que esta migración se lo dio.
     *
     * Revertir NO devuelve la capacidad a los roles que la perdieron: eso lo
     * hace el `->middleware()` de las rutas, que en un rollback vuelve a
     * `view_inventory`. Aquí sólo se limpia lo que se agregó.
     */
    public function down(): void
    {
        $this->aplicar(agregar: false);
    }

    private function aplicar(bool $agregar): void
    {
        // Fila a fila y no con un UPDATE en bloque porque `role.permissions` es
        // JSON y manipularlo en SQL exigiría operadores que difieren entre
        // PostgreSQL y SQLite; la suite corre en los dos. Son decenas de filas.
        DB::table('role')->orderBy('id')->chunkById(200, function ($roles) use ($agregar) {
            foreach ($roles as $rol) {
                if (($rol->code ?? null) !== self::CODIGO_ADMIN) {
                    continue;
                }

                $permisos = json_decode($rol->permissions ?? '[]', true);

                if (!is_array($permisos)) {
                    continue;
                }

                // Un rol con comodín ya lo tiene todo; añadirlo sería ruido.
                if (in_array('*', $permisos, true)) {
                    continue;
                }

                $tiene = in_array(Permissions::DELETE_INVENTORY, $permisos, true);

                if ($agregar === $tiene) {
                    continue;
                }

                $permisos = $agregar
                    ? [...$permisos, Permissions::DELETE_INVENTORY]
                    : array_values(array_filter(
                        $permisos,
                        fn ($p) => $p !== Permissions::DELETE_INVENTORY
                    ));

                DB::table('role')->where('id', $rol->id)->update([
                    'permissions' => json_encode(array_values($permisos)),
                    'updated_at'  => now(),
                ]);
            }
        });
    }
};
