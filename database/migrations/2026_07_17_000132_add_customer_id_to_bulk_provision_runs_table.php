<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nullable: solo se puebla para runs de UN cliente (disparados desde
     * store/update/provision individual); los runs de bulk real (muchos
     * customer_ids en un mismo run) lo dejan null. Sirve para (a) detectar
     * un aprovisionamiento ya en curso para ese cliente y reutilizarlo en
     * vez de disparar un job duplicado, y (b) para poder auditar qué runs
     * tocaron a un cliente puntual sin parsear el JSON de `results`.
     */
    public function up(): void
    {
        Schema::table('bulk_provision_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->after('tenant_id');
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('bulk_provision_runs', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'status']);
            $table->dropColumn('customer_id');
        });
    }
};
