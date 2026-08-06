<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Índices de los listados de Finanzas (Facturación y Gastos).
 *
 * Los dos listados piden siempre la misma forma: los registros de UN tenant
 * ordenados por fecha descendente. Los índices que ya existían no cubren ese
 * acceso concreto:
 *
 *  1. `invoices (tenant_id, issue_date)` — BillingController::index filtra por
 *     tenant y ordena por `issue_date desc`. Los existentes cubren otras rutas:
 *     `invoices_customer_status_idx` sirve a la ficha de un cliente y
 *     `invoices_tenant_period_idx` al filtro por período (`period_start`), no
 *     al orden por fecha de emisión del listado general.
 *
 *  2. `expenses (tenant_id, expense_date)` — ExpenseController::index filtra por
 *     tenant (global scope BelongsToTenant) y ordena por `expense_date desc`.
 *     La tabla sólo tenía índices sueltos de una columna (`tenant_id`,
 *     `expense_date`, `status`), que no sirven para filtrar y ordenar a la vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function ($table) {
            $table->index(['tenant_id', 'issue_date'], 'invoices_tenant_issue_date_idx');
        });

        Schema::table('expenses', function ($table) {
            $table->index(['tenant_id', 'expense_date'], 'expenses_tenant_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function ($table) {
            $table->dropIndex('invoices_tenant_issue_date_idx');
        });

        Schema::table('expenses', function ($table) {
            $table->dropIndex('expenses_tenant_date_idx');
        });
    }
};
