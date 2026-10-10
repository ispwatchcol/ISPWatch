<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reparte `invoice_void` a quien ya podía retirar una factura.
 *
 * POR QUÉ ESE CRITERIO Y NO UN ROL CONCRETO
 *
 * Este PR quita capacidad: `delete_invoice` deja de alcanzar cualquier factura
 * emitida, pagada, vencida o ligada a un ticket. Si no se diera nada a cambio,
 * el despliegue dejaría a Contabilidad sin ninguna forma de retirar una factura
 * equivocada, y el lunes siguiente alguien la estaría «arreglando» a mano en la
 * base de datos — que es exactamente lo que este PR existe para evitar.
 *
 * Así que se concede a **todo rol que hoy tenga `delete_invoice`**: son
 * literalmente quienes ya podían destruir una factura entera. Recibir la
 * versión conservadora de esa misma potestad no amplía a nadie su alcance.
 *
 * Es el mismo razonamiento del backfill del PR B: la transición se deduce de lo
 * que cada rol ya podía ejercer, no de una matriz nueva.
 *
 * NO se concede a quien sólo tiene `view_billing`. Ese permiso es de LECTURA, y
 * que hasta ahora bastara para poner una factura en `cancelled` desde el `PUT`
 * genérico era precisamente el agujero que este PR cierra.
 *
 * ESTA MIGRACIÓN SÓLO TOCA `role.permissions`. No hay cambio de esquema.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->aplicar(agregar: true);
    }

    /**
     * Revertirla deja a esos roles sin poder anular. No desanula ninguna
     * factura: las que ya estén en `void` siguen anuladas, porque el estado es
     * un dato contable y no un permiso.
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

                // Un rol con comodín ya lo tiene todo; tocarlo sería ruido.
                if (in_array('*', $permisos, true)) {
                    continue;
                }

                if ($agregar && !in_array(Permissions::DELETE_INVOICE, $permisos, true)) {
                    continue;
                }

                $nuevos = $agregar
                    ? array_values(array_unique([...$permisos, Permissions::INVOICE_VOID]))
                    : array_values(array_diff($permisos, [Permissions::INVOICE_VOID]));

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
