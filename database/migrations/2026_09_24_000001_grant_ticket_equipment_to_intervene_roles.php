<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PR F3 · Reparte `ticket_equipment` a quien ya registra la visita.
 *
 * POR QUÉ HAY QUE REPARTIRLO Y NO BASTA CON DECLARARLO
 *
 * Es la lección de **P-52**: `ticket_close_override`, `ticket_reopen` y
 * `ticket_manage_catalogs` se declararon sin concedérselos a nadie y el cierre
 * con excepción quedó inalcanzable por cualquier vía. Un permiso nuevo sin
 * backfill no es una capacidad nueva, es una función muerta. De ahí que el
 * reparto viaje en el mismo PR que la funcionalidad.
 *
 * A QUIÉN, Y POR QUÉ A ÉSOS
 *
 * A los roles que hoy tienen `ticket_intervene`. La § 18 le da al Técnico de
 * campo «visita, evidencias, **materiales, equipos**, pruebas finales y
 * propuesta de cierre»: equipos e intervención salen de la misma frase, así que
 * quien ya puede registrar la visita es exactamente quien debe poder cargarle
 * el equipo que dejó.
 *
 * No se usa `view_support`, que es de lectura, ni `ticket_edit`, que la matriz
 * de la § 3 le niega al Técnico de campo — sería dar la sección a todos menos a
 * quien tiene que usarla.
 *
 * A QUIÉN NO
 *
 * A `client` y `accounting` no les llega, y no por una exclusión explícita:
 * llega solo a quien tenga `ticket_intervene`, y ninguno de los dos lo tiene.
 * Mover un aparato descuenta existencias y cambia la custodia de un bien; el
 * portal del cliente y la contabilidad no hacen ni una cosa ni la otra.
 *
 * IDEMPOTENTE: sólo añade lo que falta, no reordena lo existente, y no toca los
 * roles con comodín `*` —que ya lo tienen todo— ni los que no intervienen.
 */
return new class extends Migration
{
    /** Quien ya registra la visita es quien carga el equipo de esa visita. */
    private const PERMISO_DE_REFERENCIA = Permissions::TICKET_INTERVENE;

    private const PERMISOS = [Permissions::TICKET_EQUIPMENT];

    public function up(): void
    {
        $this->aplicar(agregar: true);
    }

    /**
     * Retira sólo lo que esta migración concedió.
     *
     * Seguro porque `ticket_equipment` nace aquí: nadie lo tenía antes, así que
     * quitarlo devuelve el sistema exactamente al estado previo.
     */
    public function down(): void
    {
        $this->aplicar(agregar: false);
    }

    private function aplicar(bool $agregar): void
    {
        // Fila a fila y no en SQL: `role.permissions` es JSON, y manipularlo en
        // el motor exigiría operadores que difieren entre PostgreSQL y SQLite
        // —la suite corre en los dos—. Mismo criterio que los backfills del
        // PR B, del PR C y del PR F1.
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

                if ($agregar && !in_array(self::PERMISO_DE_REFERENCIA, $permisos, true)) {
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
