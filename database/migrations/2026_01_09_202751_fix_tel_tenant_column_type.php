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
        Schema::table('tenant', function (Blueprint $table) {
            if (!Schema::hasColumn('tenant', 'tel_tenant')) {
                $table->string('tel_tenant', 50)->nullable();
            } else {
                $table->string('tel_tenant', 50)->nullable()->change();
            }

            if (!Schema::hasColumn('tenant', 'email_tenant')) {
                $table->string('email_tenant')->nullable();
            }
            if (!Schema::hasColumn('tenant', 'address_tenant')) {
                $table->string('address_tenant')->nullable();
            }
            if (!Schema::hasColumn('tenant', 'logo')) {
                $table->string('logo')->nullable();
            }
            if (!Schema::hasColumn('tenant', 'zone_tenant')) {
                $table->string('zone_tenant')->nullable();
            }
            if (!Schema::hasColumn('tenant', 'currency_tenant')) {
                $table->string('currency_tenant')->nullable();
            }
            if (!Schema::hasColumn('tenant', 'timezone')) {
                $table->string('timezone')->nullable();
            }
            if (!Schema::hasColumn('tenant', 'currency')) {
                $table->string('currency')->default('COP');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            // We won't drop them to avoid data loss in production, 
            // but we can revert tel_tenant change if strictly needed.
            // For recovery purposes, checking existence is safer.
        });
    }
};
