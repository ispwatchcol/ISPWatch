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
        Schema::table('customer_profile', function (Blueprint $table) {
            $table->string('ip_user', 45)->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->unsignedBigInteger('sectorial_id')->nullable();

            // Foreign keys
            $table->foreign('service_id')->references('id')->on('service_plan')->onDelete('set null');
            $table->foreign('sectorial_id')->references('id')->on('sectorial')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_profile', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
            $table->dropForeign(['sectorial_id']);
            $table->dropColumn(['ip_user', 'service_id', 'sectorial_id']);
        });
    }
};
