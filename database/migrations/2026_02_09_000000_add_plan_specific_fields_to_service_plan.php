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
            // Campos específicos para Queue
            if (!Schema::hasColumn('service_plan', 'priority')) {
                $table->integer('priority')->nullable()->after('type_plan_id');
            }
            if (!Schema::hasColumn('service_plan', 'burst_download')) {
                $table->string('burst_download')->nullable()->after('priority');
            }
            if (!Schema::hasColumn('service_plan', 'burst_upload')) {
                $table->string('burst_upload')->nullable()->after('burst_download');
            }

            // Campos específicos para PPPoE
            if (!Schema::hasColumn('service_plan', 'pppoe_pool')) {
                $table->string('pppoe_pool')->nullable()->after('burst_upload');
            }
            if (!Schema::hasColumn('service_plan', 'local_address')) {
                $table->string('local_address')->nullable()->after('pppoe_pool');
            }

            // Campos específicos para Hotspot
            if (!Schema::hasColumn('service_plan', 'shared_users')) {
                $table->integer('shared_users')->nullable()->after('local_address');
            }
            if (!Schema::hasColumn('service_plan', 'session_timeout')) {
                $table->string('session_timeout')->nullable()->after('shared_users');
            }
            if (!Schema::hasColumn('service_plan', 'idle_timeout')) {
                $table->string('idle_timeout')->nullable()->after('session_timeout');
            }

            // Campos específicos para PCQ
            if (!Schema::hasColumn('service_plan', 'pcq_rate')) {
                $table->string('pcq_rate')->nullable()->after('idle_timeout');
            }
            if (!Schema::hasColumn('service_plan', 'address_mask')) {
                $table->string('address_mask')->nullable()->after('pcq_rate');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_plan', function (Blueprint $table) {
            $table->dropColumn([
                'priority',
                'burst_download',
                'burst_upload',
                'pppoe_pool',
                'local_address',
                'shared_users',
                'session_timeout',
                'idle_timeout',
                'pcq_rate',
                'address_mask',
            ]);
        });
    }
};
