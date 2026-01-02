<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('router_ip_range', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('router_id');
            $table->unsignedBigInteger('range_id');
            $table->timestamps();

            $table->foreign('router_id')->references('id')->on('router')->onDelete('cascade');
            $table->foreign('range_id')->references('id')->on('ip_range')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_ip_range');
    }
};
