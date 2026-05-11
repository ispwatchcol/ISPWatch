<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customer_profile', function (Blueprint $table) {
            $table->string('pppoe_username', 100)->nullable()->after('router_id');
            $table->string('pppoe_password', 100)->nullable()->after('pppoe_username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_profile', function (Blueprint $table) {
            $table->dropColumn(['pppoe_username', 'pppoe_password']);
        });
    }
};
