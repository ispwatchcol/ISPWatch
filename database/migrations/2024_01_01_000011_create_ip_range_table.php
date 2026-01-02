<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ip_range', function (Blueprint $table) {
            $table->id();
            $table->string('range');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_range');
    }
};
