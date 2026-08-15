<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 1 · R3 (contraer) — elimina las tres columnas enum de `support_ticket`.
 *
 * Último paso de la transición. Desde la R2 la clave foránea es la fuente de
 * verdad y desde la R2.5 nadie escribe ya la columna enum: aquí simplemente
 * desaparece lo que quedó congelado.
 *
 * SOBRE LA COMPROBACIÓN PREVIA — LEER ANTES DE «ENDURECERLA»
 *
 * La tentación natural es abortar si el espejo (`support_ticket.status`) no
 * coincide con el catálogo. **Sería un error, y además intermitente.**
 *
 * Desde la R2.5 el espejo está congelado A PROPÓSITO: cada cambio de estado
 * entre aquel despliegue y este lo deja obsoleto. Esa divergencia no es un
 * síntoma de nada — es el comportamiento diseñado. Un aborto basado en ella
 * pasaría en desarrollo (donde la R2.5 no llegó a correr y todo sigue
 * sincronizado) y fallaría en producción sin patrón previsible.
 *
 * Y fallar aquí es caro: esta migración corre dentro del `run_command` del
 * contenedor, ANTES de que levante Apache. Una migración que aborta deja el
 * contenedor nuevo sin arrancar.
 *
 * Lo que sí hace irrecuperable el dato es que la CLAVE FORÁNEA no esté
 * resuelta: si `status_id` fuese nulo, al dropear la columna no quedaría de
 * dónde sacar el estado de ese ticket. Eso es lo que aborta.
 *
 * La divergencia se cuenta igual y se registra en el log, porque es
 * información útil para el despliegue — pero nunca detiene nada.
 */
return new class extends Migration
{
    /** Columna enum => [columna FK, tabla de catálogo, constraint CHECK]. */
    private const COLUMNAS = [
        'status'   => ['status_id',   'ticket_status',   'support_ticket_status_check'],
        'priority' => ['priority_id', 'ticket_priority', 'support_ticket_priority_check'],
        'category' => ['category_id', 'ticket_category', 'support_ticket_category_check'],
    ];

    public function up(): void
    {
        $this->abortarSiElDatoNoEsRecuperable();
        $this->registrarDivergencia();

        if (DB::getDriverName() === 'pgsql') {
            // En PostgreSQL el CHECK cae solo al dropear la columna de la que
            // depende. Se suelta explícitamente de todos modos: deja el intento
            // registrado en la migración y no depende de esa cascada implícita.
            foreach (self::COLUMNAS as $columna) {
                DB::statement("ALTER TABLE support_ticket DROP CONSTRAINT IF EXISTS {$columna[2]}");
            }
        }

        // No hay índices sobre estas tres columnas (verificado contra el
        // esquema), así que el DROP no arrastra nada más. Si alguna vez se
        // añadiera uno, habría que soltarlo antes: en SQLite un DROP COLUMN
        // deja el índice apuntando a una columna inexistente.
        Schema::table('support_ticket', function (Blueprint $table) {
            $table->dropColumn(array_keys(self::COLUMNAS));
        });
    }

    /**
     * Única condición que detiene la migración: un ticket sin catálogo resuelto.
     *
     * Con la columna enum todavía presente el problema es reparable; una vez
     * dropeada, ese ticket habría perdido su estado para siempre. Por eso se
     * comprueba aquí y no en un checklist que alguien pueda saltarse.
     */
    private function abortarSiElDatoNoEsRecuperable(): void
    {
        foreach (self::COLUMNAS as $enum => [$fk, $tabla, $check]) {
            $sinResolver = DB::table('support_ticket')->whereNull($fk)->count();

            if ($sinResolver > 0) {
                throw new RuntimeException(
                    "R3 abortada: {$sinResolver} ticket(s) con `{$fk}` sin resolver. "
                    . "Dropear `{$enum}` les haría perder el dato de forma irreversible. "
                    . "Repara la clave foránea antes de reintentar:\n"
                    . "  UPDATE support_ticket SET {$fk} = (SELECT id FROM {$tabla} "
                    . "WHERE {$tabla}.code = support_ticket.{$enum}) WHERE {$fk} IS NULL;"
                );
            }

            // Defensa en profundidad: la FK es ON DELETE RESTRICT, así que no
            // debería existir un id apuntando a una fila inexistente. Si lo
            // hubiera, el código saldría nulo y el ticket quedaría sin estado.
            $rotos = DB::table('support_ticket')
                ->leftJoin($tabla, "{$tabla}.id", '=', "support_ticket.{$fk}")
                ->whereNull("{$tabla}.code")
                ->count();

            if ($rotos > 0) {
                throw new RuntimeException(
                    "R3 abortada: {$rotos} ticket(s) cuyo `{$fk}` no resuelve a ninguna fila de "
                    . "`{$tabla}`. El catálogo está incompleto o la integridad referencial se rompió."
                );
            }
        }
    }

    /**
     * Cuenta la divergencia del espejo y la deja en el log. NUNCA aborta.
     *
     * Ver la nota de cabecera: después de la R2.5 esta divergencia es lo
     * esperado, no un fallo. Se registra porque su magnitud dice cuánta
     * actividad hubo entre el despliegue de la R2.5 y este — dato útil para
     * quien revise el despliegue, e inútil como criterio de parada.
     */
    private function registrarDivergencia(): void
    {
        foreach (self::COLUMNAS as $enum => [$fk, $tabla, $check]) {
            $divergentes = DB::table('support_ticket')
                ->join($tabla, "{$tabla}.id", '=', "support_ticket.{$fk}")
                ->whereRaw("support_ticket.{$enum} IS DISTINCT FROM {$tabla}.code")
                ->count();

            Log::info('R3 · espejo congelado antes de eliminarlo', [
                'columna'     => $enum,
                'divergentes' => $divergentes,
                'nota'        => 'Esperado desde la R2.5: el espejo dejó de escribirse. No es un fallo.',
            ]);
        }
    }

    /**
     * Reconstruye las columnas DESDE EL CATÁLOGO, no desde ningún respaldo.
     *
     * Es la misma lógica del rollback manual documentado en
     * docs/RUNBOOK_DESPLIEGUE_R3_TICKETS.md. Las tres columnas no contenían ni
     * un bit de información propia —eran derivables al 100 % de la clave
     * foránea—, así que revertir no pierde nada.
     *
     * En producción se prefiere el SQL del runbook antes que este `down()`:
     * con `deploy_on_push` activo, un `migrate:rollback` volvería a aplicarse
     * en el siguiente despliegue y además no coordina la reversión del código.
     */
    public function down(): void
    {
        Schema::table('support_ticket', function (Blueprint $table) {
            // Nullable al crearlas: todavía no hay valores que cumplan NOT NULL.
            $table->string('status', 255)->nullable();
            $table->string('priority', 255)->nullable();
            $table->string('category', 255)->nullable();
        });

        foreach (self::COLUMNAS as $enum => [$fk, $tabla, $check]) {
            // Subconsulta correlacionada: SQL estándar, corre igual en
            // PostgreSQL (donde se despliega) y en SQLite (donde se prueba).
            DB::statement("
                UPDATE support_ticket
                SET {$enum} = (SELECT code FROM {$tabla} WHERE {$tabla}.id = support_ticket.{$fk})
                WHERE {$fk} IS NOT NULL
            ");
        }

        foreach (['status' => 'open', 'priority' => 'medium', 'category' => 'general'] as $enum => $defecto) {
            DB::table('support_ticket')->whereNull($enum)->update([$enum => $defecto]);
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE support_ticket ALTER COLUMN status SET NOT NULL, ALTER COLUMN status SET DEFAULT 'open'");
            DB::statement("ALTER TABLE support_ticket ALTER COLUMN priority SET NOT NULL, ALTER COLUMN priority SET DEFAULT 'medium'");
            DB::statement("ALTER TABLE support_ticket ALTER COLUMN category SET NOT NULL, ALTER COLUMN category SET DEFAULT 'general'");

            DB::statement("ALTER TABLE support_ticket ADD CONSTRAINT support_ticket_status_check CHECK (status::text = ANY (ARRAY['open','in_progress','resolved','closed']::text[]))");
            DB::statement("ALTER TABLE support_ticket ADD CONSTRAINT support_ticket_priority_check CHECK (priority::text = ANY (ARRAY['low','medium','high','urgent']::text[]))");
            DB::statement("ALTER TABLE support_ticket ADD CONSTRAINT support_ticket_category_check CHECK (category::text = ANY (ARRAY['technical','billing','services','general']::text[]))");
        }
    }
};
