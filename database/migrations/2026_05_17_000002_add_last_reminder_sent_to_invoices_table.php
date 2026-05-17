<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Tracks when a payment reminder was last sent for an invoice so the
     * automated reminder job is idempotent (one reminder per billing cycle).
     * The code already referenced this column but it was never created.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('invoices', 'last_reminder_sent')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->timestamp('last_reminder_sent')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('invoices', 'last_reminder_sent')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn('last_reminder_sent');
            });
        }
    }
};
