<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Para clientes de fibra: nap_port indica qué puerto de la caja NAP
 * (sectorial_id, cuando ese elemento es de tipo nap) ocupa el cliente.
 * String para admitir etiquetas no numéricas ("P3", "A1") además de "3".
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_profile', function (Blueprint $table) {
            if (!Schema::hasColumn('customer_profile', 'nap_port')) {
                $table->string('nap_port', 20)->nullable()->after('sectorial_id')
                    ->comment('Puerto de la caja NAP que ocupa el cliente (fibra)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_profile', function (Blueprint $table) {
            if (Schema::hasColumn('customer_profile', 'nap_port')) {
                $table->dropColumn('nap_port');
            }
        });
    }
};
