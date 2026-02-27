<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('billing', function (Blueprint $table) {
            // Hora del día en que se ejecuta el corte automático (formato HH:MM:SS)
            $table->time('cut_time')->default('00:00:00')->after('cut_day')
                ->comment('Hora del día en que se ejecuta el corte automático');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billing', function (Blueprint $table) {
            $table->dropColumn('cut_time');
        });
    }
};
