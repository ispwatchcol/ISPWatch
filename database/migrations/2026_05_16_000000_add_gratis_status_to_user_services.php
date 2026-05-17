<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Adds the 'gratis' status to user_services so customers on a courtesy
     * plan can be flagged as non-billable: BillingService only invoices
     * services whose status is 'active', so 'gratis' is silently skipped.
     *
     * Also backfills the real data set:
     *   1. Customers created manually from the UI never got a user_services
     *      row (it was only created on Excel import), so the billing engine
     *      never saw them. Create the missing row from customer_profile.
     *   2. Existing services on a courtesy plan -> 'gratis'.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // enum() on Postgres is a varchar + CHECK constraint. Widen it.
            DB::statement('ALTER TABLE user_services DROP CONSTRAINT IF EXISTS user_services_status_check');
            DB::statement("ALTER TABLE user_services ADD CONSTRAINT user_services_status_check CHECK (status::text = ANY (ARRAY['active','suspended','cancelled','expired','gratis']::text[]))");
        } else {
            // SQLite (test suite): rebuild the column without the enum CHECK
            // so 'gratis' is accepted. Laravel recreates the table for us.
            Schema::table('user_services', function (Blueprint $table) {
                $table->string('status')->default('active')->change();
            });
        }

        // Data backfill only makes sense against the real (pgsql) dataset;
        // test databases are built per-test from fixtures.
        if ($driver !== 'pgsql') {
            return;
        }

        // 1. Mirror manually-created customers into user_services so the
        //    monthly billing job (which is driven by user_services) sees them.
        DB::statement("
            INSERT INTO user_services (user_id, service_plan_id, status, start_date, created_at, updated_at)
            SELECT cp.user_id,
                   cp.service_id,
                   CASE WHEN sp.is_courtesy THEN 'gratis' ELSE 'active' END,
                   NOW(), NOW(), NOW()
            FROM customer_profile cp
            JOIN users u ON u.id = cp.user_id AND u.role_id = 3
            JOIN service_plan sp ON sp.id = cp.service_id
            WHERE cp.service_id IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM user_services us WHERE us.user_id = cp.user_id
              )
        ");

        // 2. Existing active services on a courtesy plan -> 'gratis' so the
        //    monthly job stops auto-invoicing them.
        DB::statement("
            UPDATE user_services us
            SET status = 'gratis', updated_at = NOW()
            FROM service_plan sp
            WHERE us.service_plan_id = sp.id
              AND sp.is_courtesy = true
              AND us.status = 'active'
        ");
    }

    public function down(): void
    {
        // Reclassify 'gratis' back to 'active' regardless of driver. Rows that
        // were backfilled for manual customers are intentionally kept: there
        // is no reliable marker to single them out and deleting billing-linked
        // rows would be destructive.
        DB::statement("UPDATE user_services SET status = 'active' WHERE status = 'gratis'");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE user_services DROP CONSTRAINT IF EXISTS user_services_status_check');
            DB::statement("ALTER TABLE user_services ADD CONSTRAINT user_services_status_check CHECK (status::text = ANY (ARRAY['active','suspended','cancelled','expired']::text[]))");
        }
    }
};
