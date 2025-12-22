<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('service_plan', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('speed_down');
            $table->string('speed_up');
            $table->integer('cost_product_id')->nullable();
            $table->integer('cost_product')->nullable();
            $table->string('commit')->nullable();
            $table->string('type')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_plan');
    }
};
