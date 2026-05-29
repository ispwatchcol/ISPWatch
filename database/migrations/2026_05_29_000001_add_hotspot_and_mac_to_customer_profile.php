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
            // HotSpot access credentials (used when the router control mode is HotSpot).
            $table->string('hotspot_username')->nullable()->after('pppoe_local_address')->comment('HotSpot login username');
            $table->string('hotspot_password')->nullable()->after('hotspot_username')->comment('HotSpot login password');

            // MAC address — required to create a static DHCP lease when the router
            // control mode is DHCP Leases.
            $table->string('mac_address', 17)->nullable()->after('hotspot_password')->comment('Client MAC for static DHCP lease / IP-MAC binding');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_profile', function (Blueprint $table) {
            $table->dropColumn(['hotspot_username', 'hotspot_password', 'mac_address']);
        });
    }
};
