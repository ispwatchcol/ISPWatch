<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `delete_customers` sólo para roles con autoridad administrativa.
 *
 * QUÉ CAMBIA EN LA PRÁCTICA
 *
 * Hasta ahora `DELETE /api/customers/{customer}` exigía `edit_internet_service`.
 * Con ese permiso podían borrar clientes **20 roles** —los `Administrador`,
 * `Staff`, `Contabilidad` y `Tecnico` de todos los ISP—, y borrar un cliente
 * arrastra en cascada sus facturas, sus pagos, sus créditos y sus documentos.
 * El rol `Tecnico` tiene siete permisos en total y uno de ellos bastaba para
 * destruir el histórico contable de un abonado.
 *
 * A partir de aquí hace falta `delete_customers`, y **sólo se concede a los
 * roles con `code = 'admin'`**. Los otros quince lo pierden.
 *
 * POR QUÉ `code` Y NO EL NOMBRE NI EL ID
 *
 * Los roles son por tenant: el id varía entre ISP y el nombre es editable. El
 * `code` es el identificador estable, y es el criterio que ya usan
 * `CheckStaffProfile` y `AuthController` para reconocer a un administrador. Ver
 * el comentario de `CheckStaffProfile`: «never hard-code role_id (the old
 * [1, 2] check only worked for the first/global tenant)».
 *
 * NO SE CONCEDE POR ARRASTRE
 *
 * Sería más cómodo dárselo a todo rol que hoy tenga `edit_internet_service` —no
 * habría regresión de ningún tipo— pero eso dejaría exactamente el agujero que
 * este PR viene a cerrar. La retirada de la capacidad es el objetivo, no un
 * efecto colateral.
 *
 * LO QUE ESTA MIGRACIÓN NO HACE
 *
 * No toca `edit_internet_service`: ese permiso sigue existiendo y sigue
 * autorizando lo suyo. Sólo deja de autorizar el borrado.
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
     * Revertir NO devuelve el permiso a los quince roles que lo perdieron: eso
     * lo hace el `->middleware()` de la ruta, que en un rollback vuelve a
     * `edit_internet_service`. Aquí sólo se limpia lo que se agregó.
     */
    public function down(): void
    {
        $this->aplicar(agregar: false);
    }

    private function aplicar(bool $agregar): void
    {
        // Se recorre fila a fila y no con un UPDATE en bloque porque
        // `role.permissions` es JSON y manipularlo en SQL exigiría operadores
        // que difieren entre PostgreSQL y SQLite; la suite corre en los dos.
        // Son decenas de filas: el coste es irrelevante.
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

                $tiene = in_array(Permissions::DELETE_CUSTOMERS, $permisos, true);

                if ($agregar === $tiene) {
                    continue;
                }

                $permisos = $agregar
                    ? [...$permisos, Permissions::DELETE_CUSTOMERS]
                    : array_values(array_filter(
                        $permisos,
                        fn ($p) => $p !== Permissions::DELETE_CUSTOMERS
                    ));

                DB::table('role')->where('id', $rol->id)->update([
                    'permissions' => json_encode(array_values($permisos)),
                    'updated_at'  => now(),
                ]);
            }
        });
    }
};
