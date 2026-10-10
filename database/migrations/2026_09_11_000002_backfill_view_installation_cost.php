<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reparte `view_installation_cost` entre los roles que YA veían la cartera.
 *
 * QUÉ PROBLEMA RESUELVE EL PERMISO
 *
 * El bloque «Información de Cartera» del detalle de una orden —valor de la
 * instalación, adicionales, descuento, abono y saldo— estaba gobernado por
 * `edit_discount`, cuya etiqueta era «Editar Descuento». Nadie que administrara
 * roles podía adivinar que la casilla del descuento era la que mostraba el
 * valor de la instalación, y el rol Técnico, que no la trae, no veía el
 * apartado ni tenía casilla alguna que marcar para verlo.
 *
 * POR QUÉ HACE FALTA ESTA MIGRACIÓN
 *
 * Un permiso nuevo no llega solo a los roles ya sembrados: la autorización lee
 * `role.permissions` de la base, no `getPermissionsByRole()`, y no hay bypass
 * de superadministrador. Sin este relleno los administradores verían una
 * casilla nueva sin marcar y `permissions:sync` —que existe justo para esto—
 * sólo corre si alguien se acuerda de ejecutarlo a mano.
 *
 * A QUIÉN SE LO DA, Y A QUIÉN NO
 *
 * Sólo a los roles `code = 'admin'` y a los que ya tienen `edit_discount`
 * (Staff y Contabilidad, típicamente). A esos dos grupos NO les concede ninguna
 * capacidad nueva: ya leían la cartera por la vía de `edit_discount`. Lo único
 * que cambia es que la casilla queda marcada y el catálogo del administrador
 * deja de mentir.
 *
 * Al rol Técnico NO se lo da. Qué ve un técnico de campo es una decisión de
 * cada ISP —uno querrá que sepa cuánto cobrar, otro no—, y el objeto de este
 * cambio es que exista la casilla, no tomar la decisión por el cliente. Quien
 * administre los roles de su empresa la marca cuando corresponda.
 *
 * LO QUE NO CAMBIA
 *
 * `edit_discount` sigue siendo el único permiso que autoriza GUARDAR la
 * cartera: `PUT /installations/{id}/billing` emite o recalcula la factura de
 * instalación y da por recibido un abono. Esto es un permiso de lectura.
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
     * Nadie pierde acceso al revertir: los roles alcanzados aquí son justamente
     * los que ya leían la cartera por `edit_discount`, que sigue intacto.
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
                $permisos = json_decode($rol->permissions ?? '[]', true);

                if (!is_array($permisos)) {
                    continue;
                }

                // Un rol con comodín ya lo tiene todo; añadirlo sería ruido.
                if (in_array('*', $permisos, true)) {
                    continue;
                }

                $alcanzado = ($rol->code ?? null) === self::CODIGO_ADMIN
                    || in_array(Permissions::EDIT_DISCOUNT, $permisos, true);

                if (!$alcanzado) {
                    continue;
                }

                $tiene = in_array(Permissions::VIEW_INSTALLATION_COST, $permisos, true);

                if ($agregar === $tiene) {
                    continue;
                }

                $permisos = $agregar
                    ? [...$permisos, Permissions::VIEW_INSTALLATION_COST]
                    : array_values(array_filter(
                        $permisos,
                        fn ($p) => $p !== Permissions::VIEW_INSTALLATION_COST
                    ));

                DB::table('role')->where('id', $rol->id)->update([
                    'permissions' => json_encode(array_values($permisos)),
                    'updated_at'  => now(),
                ]);
            }
        });
    }
};
