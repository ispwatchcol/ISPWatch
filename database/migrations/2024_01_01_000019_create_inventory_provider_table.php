<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_provider', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('addr')->nullable();
            $table->string('city')->nullable();
            $table->string('identification')->nullable();
            $table->string('advisor_name')->nullable();
            $table->string('advisor_phone')->nullable();
            $table->string('advisor_email')->nullable();
            $table->string('advisor_position')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_provider');
    }
};
