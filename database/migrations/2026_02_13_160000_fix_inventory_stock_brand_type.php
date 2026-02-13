<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Fix inventory_stock.brand: was integer, should be string
        Schema::table('inventory_stock', function (Blueprint $table) {
            $table->string('brand')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_stock', function (Blueprint $table) {
            $table->integer('brand')->nullable()->change();
        });
    }
};
