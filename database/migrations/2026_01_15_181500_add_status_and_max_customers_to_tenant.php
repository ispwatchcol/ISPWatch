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
            // Tenant plan status: trial, basic, pro, enterprise
            $table->string('status')->default('trial')->after('domain');

            // Maximum number of customers allowed based on plan
            // trial = 30, basic = 100, pro = 500, enterprise = 0 (unlimited)
            $table->integer('max_customers')->default(30)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->dropColumn(['status', 'max_customers']);
        });
    }
};
