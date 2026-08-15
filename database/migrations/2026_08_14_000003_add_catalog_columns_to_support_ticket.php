<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 1 · R1 (expandir) — columnas de catálogo en `support_ticket`.
 *
 * ESTRICTAMENTE ADITIVA. Las tres columnas enum (`status`, `priority`,
 * `category`) siguen intactas y siguen siendo la fuente de lectura de toda la
 * aplicación. Aquí sólo se agregan las claves foráneas y se rellenan.
 *
 * El desmontaje de los enums es la R3 y necesita aprobación aparte. Esa
 * separación es justamente lo que hace esta migración reversible: si algo sale
 * mal, se revierte sin haber perdido un solo dato, porque el dato viejo nunca
 * dejó de estar donde estaba.
 *
 * POR QUÉ EL BACKFILL NO NECESITA MAPEO MANUAL
 *
 * Las tres columnas son NOT NULL con default y su dominio está cerrado por un
 * CHECK constraint desde 2024: en producción no puede existir un valor fuera
 * de los doce que la migración anterior sembró con esa misma grafía. El
 * backfill es entonces un join por código, y su completitud es verificable —
 * no una apuesta. Si quedara una sola fila sin resolver, esta migración
 * aborta en vez de dejar el hueco callado.
 *
 * `closed_at` nace vacía a propósito, incluso para los tickets ya cerrados: no
 * existe ningún registro de cuándo se cerraron (es justamente la brecha que la
 * columna viene a cerrar) y rellenarla con `updated_at` sería inventar un dato
 * que después nadie podría distinguir de uno real.
 */
return new class extends Migration
{
    /** Columna del enum => tabla de catálogo que la reemplaza. */
    private const MIGRADAS = [
        'status'   => ['columna' => 'status_id',   'tabla' => 'ticket_status'],
        'priority' => ['columna' => 'priority_id', 'tabla' => 'ticket_priority'],
        'category' => ['columna' => 'category_id', 'tabla' => 'ticket_category'],
    ];

    /**
     * Vocabulario de diagnóstico. Entran ahora, nullable y sin captura en la
     * interfaz todavía, para que el contrato OpenAPI se publique una sola vez
     * con el juego completo de campos — que es lo que el integrador pidió de
     * forma explícita. En PostgreSQL agregar una columna nullable es
     * instantáneo y no reescribe la tabla, así que el coste es nulo.
     *
     * `suspected_cause_id` y `confirmed_cause_id` apuntan AL MISMO catálogo:
     * es lo que permite comparar el diagnóstico sugerido con el confirmado.
     */
    private const DIAGNOSTICO = [
        'symptom_id'          => 'ticket_symptom',
        'suspected_cause_id'  => 'ticket_cause',
        'confirmed_cause_id'  => 'ticket_cause',
        'solution_id'         => 'ticket_solution',
        'result_id'           => 'ticket_result',
    ];

    public function up(): void
    {
        Schema::table('support_ticket', function (Blueprint $table) {
            foreach (self::MIGRADAS as $destino) {
                $table->unsignedBigInteger($destino['columna'])->nullable();
                // RESTRICT y no SET NULL: perder la fila de catálogo dejaría el
                // ticket histórico sin poder decir en qué estado quedó. Que la
                // base de datos lo impida es la mitad de la regla "los códigos
                // no se borran, se retiran".
                $table->foreign($destino['columna'])
                    ->references('id')->on($destino['tabla'])->onDelete('restrict');
                $table->index($destino['columna']);
            }

            foreach (self::DIAGNOSTICO as $columna => $tabla) {
                $table->unsignedBigInteger($columna)->nullable();
                $table->foreign($columna)->references('id')->on($tabla)->onDelete('restrict');
            }

            $table->timestamp('closed_at')->nullable();
        });

        $this->backfill();
        $this->verificar();
    }

    /**
     * Subconsulta correlacionada en vez de UPDATE ... FROM: la primera es SQL
     * estándar y corre igual en PostgreSQL y en SQLite. La segunda obligaría a
     * bifurcar por motor, y el motor con el que se prueba (SQLite) no es el
     * motor con el que se despliega (PostgreSQL) — exactamente el tipo de
     * divergencia que ya produjo un fallo en este mismo módulo con LIKE/ILIKE.
     */
    private function backfill(): void
    {
        foreach (self::MIGRADAS as $enum => $destino) {
            DB::statement("
                UPDATE support_ticket
                SET {$destino['columna']} = (
                    SELECT id FROM {$destino['tabla']}
                    WHERE {$destino['tabla']}.code = support_ticket.{$enum}
                )
                WHERE {$enum} IS NOT NULL
            ");
        }
    }

    /**
     * La consulta que decide si el backfill fue completo: cualquier fila con
     * valor en el enum y sin id resuelto es un huérfano. Debe dar cero.
     *
     * Aborta la migración si no da cero. Es deliberado: una migración de datos
     * a medias que se reporta como exitosa es peor que una que falla, porque
     * el problema se descubre semanas después y ya con tickets nuevos encima.
     */
    private function verificar(): void
    {
        foreach (self::MIGRADAS as $enum => $destino) {
            $huerfanos = DB::table('support_ticket')
                ->whereNotNull($enum)
                ->whereNull($destino['columna'])
                ->count();

            if ($huerfanos > 0) {
                throw new RuntimeException(
                    "Backfill incompleto: {$huerfanos} ticket(s) con `{$enum}` sin correspondencia en "
                    . "`{$destino['tabla']}`. Revisa qué códigos existen en la columna y no en el catálogo "
                    . "antes de reintentar; la migración no continúa para no dejar el hueco en silencio."
                );
            }
        }
    }

    public function down(): void
    {
        Schema::table('support_ticket', function (Blueprint $table) {
            foreach (array_keys(self::DIAGNOSTICO) as $columna) {
                $table->dropForeign([$columna]);
            }

            foreach (self::MIGRADAS as $destino) {
                $table->dropForeign([$destino['columna']]);
                // El índice se suelta explícitamente ANTES que la columna: en
                // SQLite, DROP COLUMN no arrastra sus índices y la tabla queda
                // con uno apuntando a una columna inexistente, que es un error
                // duro en la siguiente operación de esquema. Verificado
                // ejecutando el rollback de verdad, no por inspección.
                $table->dropIndex([$destino['columna']]);
            }

            $table->dropColumn(array_merge(
                array_column(self::MIGRADAS, 'columna'),
                array_keys(self::DIAGNOSTICO),
                ['closed_at'],
            ));
        });
    }
};
