<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('router', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ip')->nullable();
            $table->string('user_rb')->nullable();
            $table->string('password_rb')->nullable();
            $table->string('lan_interface')->nullable();
            $table->json('coordinates')->nullable();
            $table->unsignedBigInteger('cut_type_id')->nullable();
            $table->unsignedBigInteger('billing_router_id')->nullable();
            $table->string('firmware_version')->nullable();
            $table->enum('status', ['active', 'inactive', 'maintenance'])->default('active');
            $table->text('comments')->nullable();
            $table->timestamps();

            $table->foreign('cut_type_id')->references('id')->on('cut_type')->onDelete('set null');
            $table->foreign('billing_router_id')->references('id')->on('billing')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router');
    }
};
