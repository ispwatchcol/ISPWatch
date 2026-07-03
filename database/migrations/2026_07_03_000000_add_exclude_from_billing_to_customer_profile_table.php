<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-customer switch to leave a client OUT of the automated billing lifecycle.
 *
 * When true the monthly job never invoices them, they never receive payment
 * reminders nor invoice/reminder notifications (email/WhatsApp), and the
 * automatic overdue-cut ignores them. Distinct from courtesy plans
 * (is_courtesy -> user_services.status = 'gratis'), which are plan-level; this
 * is a client-level flag for "special" clients billed/handled manually.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('customer_profile', 'exclude_from_billing')) {
            Schema::table('customer_profile', function (Blueprint $table) {
                $table->boolean('exclude_from_billing')->default(false)->after('estrato');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_profile', 'exclude_from_billing')) {
            Schema::table('customer_profile', function (Blueprint $table) {
                $table->dropColumn('exclude_from_billing');
            });
        }
    }
};
