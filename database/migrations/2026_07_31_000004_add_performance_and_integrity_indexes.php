<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índices de rendimiento e integridad (hallazgos M-7 y M-8).
 *
 *  M-7  `invoices` (1 168 filas), `payments` (1 086) y `user_services` (987) no
 *       tenían más índices que su PK y algún único. No es un problema de volumen
 *       —todavía— sino de frecuencia: la generación mensual y el corte automático
 *       recorren esas tablas por CADA cliente y en CADA ejecución horaria.
 *
 *  M-8  La regla "un cliente por IP dentro del mismo router" se validaba SÓLO en
 *       CustomerProfileController. Cualquier otra vía de escritura —importación
 *       masiva, actualización masiva, tinker— podía duplicarla, y una IP repetida
 *       en un router significa dos clientes peleándose la misma dirección.
 *       Se añade el índice único parcial que faltaba, gemelo del que ya protege
 *       `pppoe_username`. Verificado antes de escribir la migración: en
 *       producción hay 0 duplicados, así que la creación no puede fallar.
 *
 * Los índices parciales (WHERE) son exclusivos de PostgreSQL. En SQLite se crea
 * la variante sin filtro cuando tiene sentido, y se omite el único parcial: los
 * tests no dependen de esa restricción y un único total sobre columnas nulables
 * cambiaría el comportamiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        $esPostgres = DB::getDriverName() === 'pgsql';

        // ── M-7: índices de rendimiento ─────────────────────────────────────
        Schema::table('invoices', function ($table) {
            // El listado de facturas de un cliente y el filtro por estado.
            $table->index(['customer_id', 'status'], 'invoices_customer_status_idx');
            // La auditoría mensual busca por tenant + periodo.
            $table->index(['tenant_id', 'period_start'], 'invoices_tenant_period_idx');
        });

        Schema::table('user_services', function ($table) {
            // La generación mensual pregunta esto por cada cliente del router.
            $table->index(['user_id', 'status'], 'user_services_user_status_idx');
        });

        Schema::table('payments', function ($table) {
            $table->index(['customer_id', 'payment_date'], 'payments_customer_date_idx');
        });

        if ($esPostgres) {
            // Índice parcial: sólo las facturas que aún deben dinero. Es la
            // consulta exacta del corte automático (facturas vencidas con saldo)
            // y el filtro deja fuera a las ya pagadas, que son la mayoría.
            DB::statement("
                CREATE INDEX IF NOT EXISTS invoices_overdue_idx
                ON invoices (due_date)
                WHERE balance_due > 0
            ");
        } else {
            Schema::table('invoices', function ($table) {
                $table->index('due_date', 'invoices_due_date_idx');
            });
        }

        // ── M-8: unicidad de IP por router ──────────────────────────────────
        if ($esPostgres) {
            DB::statement("
                CREATE UNIQUE INDEX IF NOT EXISTS customer_profile_ip_router_unique
                ON customer_profile (router_id, ip_user)
                WHERE ip_user IS NOT NULL AND ip_user <> '' AND router_id IS NOT NULL
            ");
        }
    }

    public function down(): void
    {
        $esPostgres = DB::getDriverName() === 'pgsql';

        Schema::table('invoices', function ($table) {
            $table->dropIndex('invoices_customer_status_idx');
            $table->dropIndex('invoices_tenant_period_idx');
        });

        Schema::table('user_services', function ($table) {
            $table->dropIndex('user_services_user_status_idx');
        });

        Schema::table('payments', function ($table) {
            $table->dropIndex('payments_customer_date_idx');
        });

        if ($esPostgres) {
            DB::statement('DROP INDEX IF EXISTS invoices_overdue_idx');
            DB::statement('DROP INDEX IF EXISTS customer_profile_ip_router_unique');
        } else {
            Schema::table('invoices', function ($table) {
                $table->dropIndex('invoices_due_date_idx');
            });
        }
    }
};
