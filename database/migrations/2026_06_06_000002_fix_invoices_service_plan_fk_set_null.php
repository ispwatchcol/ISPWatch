<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // invoices.service_id se creó con constrained() sin onDelete, es decir
        // RESTRICT (NO ACTION): impedía eliminar un plan que ya tuviera facturas.
        // Pasa a SET NULL para que el plan se pueda borrar; las facturas históricas
        // conservan descripción y montos, solo pierden el vínculo al plan.
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
            $table->foreign('service_id')->references('id')->on('service_plan')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
            $table->foreign('service_id')->references('id')->on('service_plan');
        });
    }
};
