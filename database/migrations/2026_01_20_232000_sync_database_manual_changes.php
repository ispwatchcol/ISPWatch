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
        // Tables that need tenant_id
        $tables = [
            'router',
            'sectorial',
            'inventory_stock',
            'inventory_provider',
            'inventory_branch',
            'inventory_device',
            'ip_range',
            'billing',
        ];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    if (!Schema::hasColumn($tableName, 'tenant_id')) {
                        $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
                        $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('set null');
                    }
                });
            }
        }

        // Add missing foreign keys to tables where column already exists but FK might be missing
        Schema::table('service_plan', function (Blueprint $table) {
            if (Schema::hasColumn('service_plan', 'tenant_id')) {
                // Check if foreign key exists is tricky in standard Laravel, 
                // but usually safe to wrap in try-catch or just rely on the user's manual state.
                // However, we'll just attempt to add it if it's a manual sync.
                try {
                    $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('set null');
                } catch (\Exception $e) {
                    // Might already exist
                }
            }
        });

        // Ensure router has wan_interface if not already there (though migration exists)
        if (Schema::hasTable('router')) {
            Schema::table('router', function (Blueprint $table) {
                if (!Schema::hasColumn('router', 'wan_interface')) {
                    $table->string('wan_interface')->nullable()->after('lan_interface');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'router',
            'sectorial',
            'inventory_stock',
            'inventory_provider',
            'inventory_branch',
            'inventory_device',
            'ip_range',
            'billing',
        ];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropForeign(['tenant_id']);
                    $table->dropColumn('tenant_id');
                });
            }
        }

        Schema::table('service_plan', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
        });
    }
};
