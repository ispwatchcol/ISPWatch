<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_device', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_id')->nullable();
            $table->unsignedBigInteger('provider_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('serial')->nullable();
            $table->string('mac')->nullable();
            $table->timestamps();

            $table->foreign('stock_id')->references('id')->on('inventory_stock')->onDelete('set null');
            $table->foreign('provider_id')->references('id')->on('inventory_provider')->onDelete('set null');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('branch_id')->references('id')->on('inventory_branch')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_device');
    }
};
