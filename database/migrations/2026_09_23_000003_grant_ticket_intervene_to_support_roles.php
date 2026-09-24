<?php

use App\Constants\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PR F1 · Reparte `ticket_intervene` a quien ya opera tickets en campo.
 *
 * POR QUÉ HAY QUE REPARTIRLO Y NO BASTA CON DECLARARLO
 *
 * `ticket_close_override`, `ticket_reopen` y `ticket_manage_catalogs` se
 * declararon en el PR B sin concedérselos a nadie, y el resultado está anotado
 * como **P-52**: el cierre con excepción quedó inalcanzable por cualquier vía.
 * Un permiso nuevo sin backfill no es una capacidad nueva, es una función
 * muerta. De ahí que el reparto vaya en el mismo PR que la funcionalidad.
 *
 * A QUIÉN
 *
 * A los roles que HOY pueden adjuntar evidencia (`ticket_attach`). Es el
 * conjunto más cercano a «quien atiende el ticket sobre el terreno»: la § 18 le
 * da al Técnico de campo «visita, evidencias, materiales, equipos, pruebas
 * finales y propuesta de cierre» — evidencia e intervención salen de la misma
 * frase, así que quien ya podía adjuntar es exactamente quien debe poder
 * registrar la visita.
 *
 * No se usa `view_support` como criterio, que es lo que hizo el PR B: desde
 * aquel backfill los permisos granulares ya existen, y `ticket_attach` describe
 * mejor la capacidad real que el permiso heredado.
 *
 * IDEMPOTENTE: sólo añade lo que falta, no reordena lo existente, y no toca los
 * roles con comodín `*` —que ya lo tienen todo— ni los que no adjuntan.
 */
return new class extends Migration
{
    /** Quien ya podía adjuntar evidencia es quien registra la visita. */
    private const PERMISO_DE_REFERENCIA = Permissions::TICKET_ATTACH;

    private const PERMISOS = [Permissions::TICKET_INTERVENE];

    public function up(): void
    {
        $this->aplicar(agregar: true);
    }

    /**
     * Retira sólo lo que esta migración concedió.
     *
     * Seguro porque `ticket_intervene` nace aquí: nadie lo tenía antes, así que
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
        // PR B y del PR C.
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
