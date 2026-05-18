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
            $table->string('pppoe_local_address', 45)->nullable()->after('pppoe_password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_profile', function (Blueprint $table) {
            $table->dropColumn('pppoe_local_address');
        });
    }
};
