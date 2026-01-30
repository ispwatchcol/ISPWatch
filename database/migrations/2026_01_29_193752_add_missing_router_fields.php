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
            // Network configuration fields
            $table->string('ipv6')->nullable()->after('ip')->comment('IPv6 address');
            $table->string('failover')->nullable()->after('ipv6')->comment('Failover IP/Mikrotik Cloud');
            $table->string('external_id')->nullable()->after('failover')->comment('External identifier');

            // Port configuration
            $table->integer('puerto_api')->default(8728)->after('password_rb')->comment('MikroTik API port');
            $table->integer('puerto_www')->default(80)->after('puerto_api')->comment('MikroTik WWW port');

            // MikroTik feature flags
            $table->boolean('agregar_cliente_mkt')->default(false)->after('comments')->comment('Auto-add client to MikroTik');
            $table->boolean('historial_trafico')->default(false)->after('agregar_cliente_mkt')->comment('Traffic history tracking');
            $table->boolean('simple_queue')->default(false)->after('historial_trafico')->comment('Simple Queue control');
            $table->boolean('control_pcq')->default(false)->after('simple_queue')->comment('PCQ + Address-list control');
            $table->boolean('hotspot')->default(false)->after('control_pcq')->comment('HotSpot control');
            $table->boolean('pppoe')->default(false)->after('hotspot')->comment('PPPOE control');
            $table->boolean('ip_bindings')->default(false)->after('pppoe')->comment('IP Bindings');
            $table->boolean('amarre')->default(false)->after('ip_bindings')->comment('IP/MAC binding');
            $table->boolean('dhcp_leases')->default(false)->after('amarre')->comment('DHCP Leases control');
            $table->boolean('falla_general')->default(false)->after('dhcp_leases')->comment('General failure status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('router', function (Blueprint $table) {
            $table->dropColumn([
                'ipv6',
                'failover',
                'external_id',
                'puerto_api',
                'puerto_www',
                'agregar_cliente_mkt',
                'historial_trafico',
                'simple_queue',
                'control_pcq',
                'hotspot',
                'pppoe',
                'ip_bindings',
                'amarre',
                'dhcp_leases',
                'falla_general',
            ]);
        });
    }
};
