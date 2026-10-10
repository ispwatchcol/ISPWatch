<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gasto automático al ingresar inventario — interruptor por empresa (KAN-91).
 *
 * `inventory_entry_creates_expense` nace en **false** a propósito. Muchos ISP ya
 * registran a mano la factura del proveedor como gasto; encenderlo sin avisar
 * contaría esa compra dos veces y el balance mentiría hacia abajo, que es el
 * peor sentido en el que puede mentir.
 *
 * `expenses.inventory_movement_id` es el enlace al movimiento que originó el
 * gasto, y va **único**: es lo que vuelve idempotente la operación. Sin ese
 * índice, un reintento —o una importación que se repite— crearía el gasto dos
 * veces, y no habría forma de distinguir un gasto automático de uno escrito a
 * mano para poder anularlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->boolean('inventory_entry_creates_expense')->default(false);
            $table->unsignedBigInteger('inventory_expense_category_id')->nullable();

            $table->foreign('inventory_expense_category_id')
                ->references('id')->on('expense_categories')
                ->nullOnDelete();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('inventory_movement_id')->nullable()->after('created_by');

            $table->foreign('inventory_movement_id')
                ->references('id')->on('inventory_movements')
                ->nullOnDelete();

            // Único, no un índice corriente: es la garantía de idempotencia.
            // En Postgres los NULL no chocan entre sí, así que los gastos
            // escritos a mano (todos con NULL) conviven sin problema.
            $table->unique('inventory_movement_id');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropUnique(['inventory_movement_id']);
            $table->dropForeign(['inventory_movement_id']);
            $table->dropColumn('inventory_movement_id');
        });

        Schema::table('tenant', function (Blueprint $table) {
            $table->dropForeign(['inventory_expense_category_id']);
            $table->dropColumn(['inventory_entry_creates_expense', 'inventory_expense_category_id']);
        });
    }
};
