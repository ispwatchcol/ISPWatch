<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consecutivo de contratos, espejo del de facturas
 * (2026_01_19_000001_add_invoice_numbering_to_tenants).
 *
 * - tenant.contract_prefix      → prefijo configurable por ISP (ej. "FIBRAX").
 *                                 Vacío = se usa el prefijo por defecto "CTR".
 * - tenant.next_contract_number → contador, se reserva con lockForUpdate.
 * - customer_documents.contract_number → el consecutivo ya formateado que
 *   quedó impreso dentro del PDF. Único por tenant; NULL en todo lo que no
 *   sea un contrato generado por el sistema (en PostgreSQL y SQLite los NULL
 *   no chocan entre sí en un índice único).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->string('contract_prefix', 20)->nullable();
            $table->unsignedInteger('next_contract_number')->default(1);
        });

        Schema::table('customer_documents', function (Blueprint $table) {
            $table->string('contract_number', 40)->nullable();
            $table->unique(['tenant_id', 'contract_number'], 'unique_tenant_contract_number');
        });
    }

    public function down(): void
    {
        Schema::table('customer_documents', function (Blueprint $table) {
            $table->dropUnique('unique_tenant_contract_number');
            $table->dropColumn('contract_number');
        });

        Schema::table('tenant', function (Blueprint $table) {
            $table->dropColumn(['contract_prefix', 'next_contract_number']);
        });
    }
};
