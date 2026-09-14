<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PR C · Archivar un ticket sin destruirlo.
 *
 * QUÉ AÑADE
 *
 *   deleted_at       timestamp NULL   — marca de archivado (soft delete)
 *   archived_by      bigint NULL      — quién archivó, FK users ON DELETE SET NULL
 *   archived_reason  varchar(500) NULL— por qué, obligatorio al archivar
 *
 * POR QUÉ `deleted_at` Y NO UN ESTADO NUEVO
 *
 * El proyecto anula el dinero por estado (`void`, `cancelled`, `anulado`) y esa
 * fue la primera opción considerada. Se descartó porque el estado del ticket ya
 * significa otra cosa —dónde está en el flujo de atención— y añadir «archivado»
 * al catálogo obligaría a excluirlo a mano en cada listado, cada estadística y
 * cada consulta del integrador. Se olvidaría una, y el diseño dejó escrito que
 * este código ya se quemó con ocultamientos silenciosos.
 *
 * Con `deleted_at` la exclusión la aplica el *global scope* de Eloquent en todas
 * partes a la vez, y lo que hay que auditar es la lista corta y explícita de
 * lecturas que SÍ deben seguir viendo el archivado (historial, adjuntos, cargos
 * y el propio detalle para quien puede restaurar). Esa auditoría está hecha y
 * documentada en `docs/cliente/CNO/DISENO_PERMISOS_Y_ARCHIVADO.md` §4.
 *
 * NO SE AÑADE `archived_at`: sería `deleted_at` con otro nombre, y dos columnas
 * que deben decir lo mismo acaban diciendo cosas distintas. El modelo expone el
 * atributo `archived_at` —leyendo `deleted_at`— para que la palabra «eliminado»
 * no aparezca nunca en el contrato de la API.
 *
 * `archived_by` VA CON `ON DELETE SET NULL`, no `CASCADE`: es la misma decisión
 * que el H-6 tomó para las notas y los adjuntos. Dar de baja al administrador
 * que archivó un ticket no puede desarchivarlo ni borrar el expediente. Quién
 * fue queda igualmente en `support_ticket_history`, que nadie puede tocar.
 *
 * NINGÚN DATO EXISTENTE CAMBIA. Todos los tickets quedan con las tres columnas
 * en NULL, que es exactamente «no archivado».
 */
return new class extends Migration
{
    private const TABLA = 'support_ticket';

    public function up(): void
    {
        Schema::table(self::TABLA, function (Blueprint $table) {
            // `softDeletes()` crea `deleted_at` con el nombre que espera el trait.
            if (!Schema::hasColumn(self::TABLA, 'deleted_at')) {
                $table->softDeletes();
            }

            if (!Schema::hasColumn(self::TABLA, 'archived_by')) {
                // `unsignedBigInteger` suelto y no `foreignId()->constrained()`:
                // la clave foránea se añade abajo sólo en PostgreSQL. SQLite no
                // sabe agregar una foránea a una tabla que ya existe sin
                // reconstruirla entera, y reconstruir `support_ticket` —con sus
                // ocho claves foráneas de catálogo— para una columna de
                // auditoría nula sería un riesgo desproporcionado.
                $table->unsignedBigInteger('archived_by')->nullable()->after('closed_at');
            }

            if (!Schema::hasColumn(self::TABLA, 'archived_reason')) {
                $table->string('archived_reason', 500)->nullable()->after('archived_by');
            }
        });

        // Todas las consultas de ticket pasan a llevar `deleted_at is null`
        // colgando del *global scope*. Sin índice, cada listado se come una
        // lectura secuencial que antes no hacía.
        $this->crearIndice();

        if (DB::getDriverName() === 'pgsql') {
            $this->foraneaEnPostgres();
        }
    }

    public function down(): void
    {
        // El orden importa: la foránea y el índice cuelgan de las columnas.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ' . self::TABLA . ' DROP CONSTRAINT IF EXISTS support_ticket_archived_by_foreign');
        }

        Schema::table(self::TABLA, function (Blueprint $table) {
            if ($this->existeIndice()) {
                $table->dropIndex('support_ticket_deleted_at_index');
            }
        });

        Schema::table(self::TABLA, function (Blueprint $table) {
            foreach (['archived_reason', 'archived_by'] as $columna) {
                if (Schema::hasColumn(self::TABLA, $columna)) {
                    $table->dropColumn($columna);
                }
            }

            if (Schema::hasColumn(self::TABLA, 'deleted_at')) {
                // ADVERTENCIA: revertir con tickets archivados los devuelve a la
                // operación ordinaria. No se pierde nada —el expediente nunca se
                // borró— pero reaparecen en listados y estadísticas. El evento
                // `ticket_archived` sigue en el historial y explica por qué.
                $table->dropSoftDeletes();
            }
        });
    }

    private function crearIndice(): void
    {
        if ($this->existeIndice()) {
            return;
        }

        Schema::table(self::TABLA, function (Blueprint $table) {
            $table->index('deleted_at', 'support_ticket_deleted_at_index');
        });
    }

    private function existeIndice(): bool
    {
        return Schema::hasIndex(self::TABLA, 'support_ticket_deleted_at_index');
    }

    /**
     * `IF EXISTS` antes de crear para que la migración sea idempotente: si un
     * despliegue a medias la dejó puesta, volver a ejecutarla no debe fallar.
     */
    private function foraneaEnPostgres(): void
    {
        DB::statement('ALTER TABLE ' . self::TABLA . ' DROP CONSTRAINT IF EXISTS support_ticket_archived_by_foreign');

        DB::statement(
            'ALTER TABLE ' . self::TABLA . ' ADD CONSTRAINT support_ticket_archived_by_foreign '
            . 'FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL'
        );
    }
};
