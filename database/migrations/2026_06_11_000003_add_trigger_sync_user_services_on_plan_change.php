<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Keeps user_services.service_plan_id in sync with customer_profile.service_id
 * at the DB level, so raw migrations and bulk updates don't leave them diverged.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sync_user_services_plan()
            RETURNS TRIGGER LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.service_id IS DISTINCT FROM OLD.service_id AND NEW.service_id IS NOT NULL THEN
                    UPDATE user_services
                    SET    service_plan_id = NEW.service_id
                    WHERE  user_id = NEW.user_id
                      AND  status IN ('active', 'gratis');
                END IF;
                RETURN NEW;
            END;
            $$;

            DROP TRIGGER IF EXISTS trg_sync_user_services_plan ON customer_profile;

            CREATE TRIGGER trg_sync_user_services_plan
            AFTER UPDATE OF service_id ON customer_profile
            FOR EACH ROW
            EXECUTE FUNCTION sync_user_services_plan();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_sync_user_services_plan ON customer_profile;
            DROP FUNCTION IF EXISTS sync_user_services_plan();
        SQL);
    }
};
