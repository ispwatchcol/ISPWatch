<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Limpieza de deuda de esquema detectada por introspección del esquema real
 * (hallazgos M-4, M-5 y M-6 de docs/MEJORAS_RECOMENDADAS.md).
 *
 *  M-4  `inventory_stock.desc` estaba declarada como DATE cuando funcionalmente
 *       es la descripción del modelo de equipo. Hubo una migración previa de
 *       corrección de tipos (2026_02_13_160000) que no cubrió esta columna, así
 *       que el campo era inutilizable para su propósito. En producción está
 *       íntegramente a NULL, de modo que el cambio de tipo no pierde datos.
 *
 *  M-5  `service_plan.tenant_id` tenía DOS claves foráneas sobre la misma
 *       columna, con reglas de borrado distintas (SET NULL y NO ACTION). Con
 *       ambas presentes, la más restrictiva gana y el comportamiento real deja
 *       de ser el que dice la migración que lo definió.
 *
 *  M-6  `invoices.tenant_id`, `payments.tenant_id` y `router.tenant_id` usaban
 *       NO ACTION mientras el resto del esquema usa SET NULL o CASCADE. Dar de
 *       baja un tenant fallaba con un error de clave foránea sin explicación.
 *       Se homogeneízan a CASCADE: si se borra el ISP, sus facturas, pagos y
 *       routers dejan de tener sentido — y las tablas hijas de esas tres
 *       (invoice_items, payment_allocations, traffic_*) ya iban en CASCADE, así
 *       que el borrado ya era en cascada a partir del segundo nivel.
 *
 * Todo el bloque es específico de PostgreSQL: SQLite (los tests) no permite
 * alterar restricciones y no las necesita, porque el esquema de test se crea de
 * cero desde las migraciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->corregirTipoDescripcionInventario();
        $this->eliminarClaveForaneaDuplicada();
        $this->homogeneizarBorradoDeTenant();
    }

    /** M-4 */
    private function corregirTipoDescripcionInventario(): void
    {
        if (!Schema::hasColumn('inventory_stock', 'desc')) {
            return;
        }

        $tipo = $this->tipoDeColumna('inventory_stock', 'desc');

        if ($tipo !== 'date') {
            return; // ya corregida
        }

        // USING explícito: PostgreSQL no convierte date -> varchar por sí solo.
        DB::statement('ALTER TABLE inventory_stock ALTER COLUMN "desc" TYPE VARCHAR(255) USING "desc"::text');
    }

    /** M-5 */
    private function eliminarClaveForaneaDuplicada(): void
    {
        $fks = DB::select("
            SELECT tc.constraint_name, rc.delete_rule
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON tc.constraint_name = kcu.constraint_name
             AND tc.table_schema = kcu.table_schema
            JOIN information_schema.referential_constraints rc
              ON rc.constraint_name = tc.constraint_name
             AND rc.constraint_schema = tc.table_schema
            WHERE tc.table_schema = current_schema()
              AND tc.table_name = 'service_plan'
              AND tc.constraint_type = 'FOREIGN KEY'
              AND kcu.column_name = 'tenant_id'
        ");

        if (count($fks) < 2) {
            return; // ya está limpia
        }

        // Se conserva UNA (la de SET NULL, coherente con el resto del esquema)
        // y se eliminan las demás.
        $conservar = null;
        foreach ($fks as $fk) {
            if (strtoupper($fk->delete_rule) === 'SET NULL') {
                $conservar = $fk->constraint_name;
                break;
            }
        }
        $conservar ??= $fks[0]->constraint_name;

        foreach ($fks as $fk) {
            if ($fk->constraint_name === $conservar) {
                continue;
            }
            DB::statement("ALTER TABLE service_plan DROP CONSTRAINT \"{$fk->constraint_name}\"");
        }
    }

    /** M-6 */
    private function homogeneizarBorradoDeTenant(): void
    {
        foreach (['invoices', 'payments', 'router'] as $tabla) {
            if (!Schema::hasTable($tabla) || !Schema::hasColumn($tabla, 'tenant_id')) {
                continue;
            }

            $fks = DB::select("
                SELECT tc.constraint_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                  ON tc.constraint_name = kcu.constraint_name
                 AND tc.table_schema = kcu.table_schema
                WHERE tc.table_schema = current_schema()
                  AND tc.table_name = ?
                  AND tc.constraint_type = 'FOREIGN KEY'
                  AND kcu.column_name = 'tenant_id'
            ", [$tabla]);

            foreach ($fks as $fk) {
                DB::statement("ALTER TABLE {$tabla} DROP CONSTRAINT \"{$fk->constraint_name}\"");
            }

            DB::statement("
                ALTER TABLE {$tabla}
                ADD CONSTRAINT {$tabla}_tenant_id_foreign
                FOREIGN KEY (tenant_id) REFERENCES tenant(id) ON DELETE CASCADE
            ");
        }
    }

    private function tipoDeColumna(string $tabla, string $columna): ?string
    {
        $fila = DB::selectOne("
            SELECT data_type
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = ?
              AND column_name = ?
        ", [$tabla, $columna]);

        return $fila?->data_type;
    }

    public function down(): void
    {
        // Sin reversa: volver a poner una FK duplicada, un tipo de dato erróneo
        // y un ON DELETE que impide dar de baja un tenant sería reintroducir a
        // propósito los defectos que esta migración corrige.
    }
};
