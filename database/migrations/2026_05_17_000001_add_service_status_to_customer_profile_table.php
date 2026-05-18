<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Multi-state service status. The legacy boolean `status` column is kept
     * in sync (activo/gratis => true, suspendido/cancelado => false) so the
     * customer list, router block-list logic and billing keep working.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('customer_profile', 'service_status')) {
            Schema::table('customer_profile', function (Blueprint $table) {
                $table->string('service_status', 20)->default('activo')->after('status');
            });
        }

        // Backfill from the legacy boolean status.
        DB::table('customer_profile')->where('status', true)->update(['service_status' => 'activo']);
        DB::table('customer_profile')->where('status', false)->update(['service_status' => 'suspendido']);

        // Customers whose current service is a courtesy plan are 'gratis'.
        $gratisUserIds = DB::table('user_services')
            ->where('status', 'gratis')
            ->pluck('user_id')
            ->all();

        if (!empty($gratisUserIds)) {
            DB::table('customer_profile')
                ->whereIn('user_id', $gratisUserIds)
                ->update(['service_status' => 'gratis']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('customer_profile', 'service_status')) {
            Schema::table('customer_profile', function (Blueprint $table) {
                $table->dropColumn('service_status');
            });
        }
    }
};
