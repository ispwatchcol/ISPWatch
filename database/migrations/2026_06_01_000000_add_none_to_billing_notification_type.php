<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Adds the 'none' notification_type so a router's billing can disable
     * invoice/reminder notifications entirely. BillingService and
     * PaymentReminderService only send when the type is email/whatsapp/both,
     * so 'none' is silently skipped on both channels.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // enum() on Postgres is a varchar + CHECK constraint. Widen it.
            DB::statement('ALTER TABLE billing DROP CONSTRAINT IF EXISTS billing_notification_type_check');
            DB::statement("ALTER TABLE billing ADD CONSTRAINT billing_notification_type_check CHECK (notification_type::text = ANY (ARRAY['email','whatsapp','both','none']::text[]))");
        } else {
            // SQLite (test suite): rebuild the column without the enum CHECK
            // so 'none' is accepted. Laravel recreates the table for us.
            Schema::table('billing', function (Blueprint $table) {
                $table->string('notification_type')->default('email')->change();
            });
        }
    }

    public function down(): void
    {
        // Reclassify disabled rows back to 'email' before narrowing the
        // constraint again so the check does not reject existing data.
        DB::statement("UPDATE billing SET notification_type = 'email' WHERE notification_type = 'none'");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE billing DROP CONSTRAINT IF EXISTS billing_notification_type_check');
            DB::statement("ALTER TABLE billing ADD CONSTRAINT billing_notification_type_check CHECK (notification_type::text = ANY (ARRAY['email','whatsapp','both']::text[]))");
        }
    }
};
