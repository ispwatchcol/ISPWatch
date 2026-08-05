<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-customer switch to silence invoice/reminder notifications (email/
 * WhatsApp) without affecting billing itself.
 *
 * Unlike exclude_from_billing, this does NOT stop invoice generation nor
 * overdue-cut processing — the invoice is created and mora/suspensión run
 * exactly the same. It only gates the notification send in
 * BillingService::notifyInvoiceCreated and PaymentReminderService::sendDueReminders.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('customer_profile', 'notify_invoice')) {
            Schema::table('customer_profile', function (Blueprint $table) {
                $table->boolean('notify_invoice')->default(true)->after('exclude_from_billing');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_profile', 'notify_invoice')) {
            Schema::table('customer_profile', function (Blueprint $table) {
                $table->dropColumn('notify_invoice');
            });
        }
    }
};
