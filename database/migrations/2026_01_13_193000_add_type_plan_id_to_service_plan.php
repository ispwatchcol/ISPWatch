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
        Schema::table('service_plan', function (Blueprint $table) {
            // Add type_plan_id column and link to type_plans table
            if (!Schema::hasColumn('service_plan', 'type_plan_id')) {
                $table->unsignedBigInteger('type_plan_id')->nullable()->after('type');
                $table->foreign('type_plan_id')->references('id')->on('type_plans')->onDelete('set null');
            }

            // Add tenant_id if not exists
            if (!Schema::hasColumn('service_plan', 'tenant_id')) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('type_plan_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_plan', function (Blueprint $table) {
            $table->dropForeign(['type_plan_id']);
            $table->dropColumn(['type_plan_id', 'tenant_id']);
        });
    }
};
