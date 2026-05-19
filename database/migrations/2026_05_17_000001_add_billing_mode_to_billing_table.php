<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * billing_mode controls which month a generated invoice covers:
     *   - 'anticipado' (default): the month the job runs (cobro por adelantado)
     *   - 'vencido'             : the previous month (cobro del mes consumido)
     *
     * Default 'anticipado' preserves the existing behavior.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('billing', 'billing_mode')) {
            Schema::table('billing', function (Blueprint $table) {
                $table->string('billing_mode')->default('anticipado');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('billing', 'billing_mode')) {
            Schema::table('billing', function (Blueprint $table) {
                $table->dropColumn('billing_mode');
            });
        }
    }
};
