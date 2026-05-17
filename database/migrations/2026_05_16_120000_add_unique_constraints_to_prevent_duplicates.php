<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_plan', function (Blueprint $table) {
            $table->unique(['name', 'tenant_id']);
        });

        Schema::table('router', function (Blueprint $table) {
            $table->unique(['name', 'tenant_id']);
        });

        Schema::table('sectorial', function (Blueprint $table) {
            $table->unique('name');
        });

        Schema::table('customer_profile', function (Blueprint $table) {
            $table->unique(['name', 'last_name']);
        });
    }

    public function down(): void
    {
        Schema::table('service_plan', function (Blueprint $table) {
            $table->dropUnique('service_plan_name_tenant_id_unique');
        });

        Schema::table('router', function (Blueprint $table) {
            $table->dropUnique('router_name_tenant_id_unique');
        });

        Schema::table('sectorial', function (Blueprint $table) {
            $table->dropUnique('sectorial_name_unique');
        });

        Schema::table('customer_profile', function (Blueprint $table) {
            $table->dropUnique('customer_profile_name_last_name_unique');
        });
    }
};
