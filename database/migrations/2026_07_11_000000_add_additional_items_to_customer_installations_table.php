<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adicionales itemizados de la instalación: [{description, amount}, ...].
     * additional_charges se mantiene como el total agregado (compatibilidad
     * con reportes existentes); este campo guarda el desglose.
     */
    public function up(): void
    {
        Schema::table('customer_installations', function (Blueprint $table) {
            if (!Schema::hasColumn('customer_installations', 'additional_items')) {
                $table->json('additional_items')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_installations', function (Blueprint $table) {
            if (Schema::hasColumn('customer_installations', 'additional_items')) {
                $table->dropColumn('additional_items');
            }
        });
    }
};
