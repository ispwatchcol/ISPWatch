<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Índices del listado de recaudos (Finanzas → Pagos / Recaudos).
 *
 * La vista pide siempre lo mismo: los recaudos de UN tenant ordenados por fecha
 * descendente, y para cada página trae sus asignaciones a facturas. Faltaban
 * dos accesos:
 *
 *  1. `payments (tenant_id, payment_date)` — el listado filtraba por tenant y
 *     ordenaba por fecha sin índice que cubriera las dos cosas; el existente
 *     `payments_customer_date_idx` sólo sirve para la ficha de un cliente.
 *
 *  2. `payment_allocations (payment_id)` y `(invoice_id)` — la tabla no tenía
 *     ningún índice aparte de su PK, pese a que el eager load de cada página
 *     hace `where payment_id in (...)`, el filtro por número de factura entra
 *     por `invoice_id` y el borrado de un pago revierte por `payment_id`.
 *     Eran recorridos completos de tabla en todas esas rutas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function ($table) {
            $table->index(['tenant_id', 'payment_date'], 'payments_tenant_date_idx');
        });

        Schema::table('payment_allocations', function ($table) {
            $table->index('payment_id', 'payment_allocations_payment_idx');
            $table->index('invoice_id', 'payment_allocations_invoice_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function ($table) {
            $table->dropIndex('payments_tenant_date_idx');
        });

        Schema::table('payment_allocations', function ($table) {
            $table->dropIndex('payment_allocations_payment_idx');
            $table->dropIndex('payment_allocations_invoice_idx');
        });
    }
};
