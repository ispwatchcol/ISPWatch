<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('billing', function (Blueprint $table) {
            // Cambiar columnas de DATE a INTEGER para guardar solo el número del día (1-31)
            $table->integer('create_invoice')->nullable()->change();
            $table->integer('payment_day')->nullable()->change();
            $table->integer('payment_reminder')->nullable()->change();
            $table->integer('cut_day')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('billing', function (Blueprint $table) {
            // Revertir a DATE
            $table->date('create_invoice')->nullable()->change();
            $table->date('payment_day')->nullable()->change();
            $table->date('payment_reminder')->nullable()->change();
            $table->date('cut_day')->nullable()->change();
        });
    }
};
