<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ip_assignment', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_range')->nullable();
            $table->enum('ip_asig', ['static', 'dynamic', 'reserved'])->default('dynamic');
            $table->enum('status', ['available', 'assigned', 'blocked'])->default('available');
            $table->unsignedBigInteger('router_id')->nullable();
            $table->timestamps();

            $table->foreign('id_range')->references('id')->on('ip_range')->onDelete('set null');
            $table->foreign('router_id')->references('id')->on('router')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_assignment');
    }
};
