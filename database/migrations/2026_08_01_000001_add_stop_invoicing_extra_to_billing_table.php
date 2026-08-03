<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tope de facturación por router: cuántas facturas pendientes se le siguen
 * emitiendo a un moroso DESPUÉS de haber alcanzado el umbral de corte
 * (billing.overdue_invoices) antes de dejar de facturarlo por completo.
 *
 * Tope efectivo = overdue_invoices + stop_invoicing_extra.
 * Ejemplo (2 + 2): corta al llegar a 2 vencidas y deja de emitir cuando el
 * cliente acumula 4 facturas pendientes.
 *
 * NULL = sin tope (se factura indefinidamente, comportamiento histórico).
 * Las configuraciones existentes quedan en 2, que es la política pedida.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('billing', 'stop_invoicing_extra')) {
            Schema::table('billing', function (Blueprint $table) {
                $table->smallInteger('stop_invoicing_extra')
                    ->nullable()
                    ->default(2)
                    ->after('overdue_invoices');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('billing', 'stop_invoicing_extra')) {
            Schema::table('billing', function (Blueprint $table) {
                $table->dropColumn('stop_invoicing_extra');
            });
        }
    }
};
