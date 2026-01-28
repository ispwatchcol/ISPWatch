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
        Schema::table('router', function (Blueprint $table) {
            $table->string('vpn_username')->nullable()->after('wan_interface')->comment('Usuario VPN único para L2TP');
            $table->string('vpn_password')->nullable()->after('vpn_username')->comment('Contraseña VPN para L2TP');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('router', function (Blueprint $table) {
            $table->dropColumn(['vpn_username', 'vpn_password']);
        });
    }
};
