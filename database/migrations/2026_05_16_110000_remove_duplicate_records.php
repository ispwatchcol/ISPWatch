<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Remove duplicate service plans - keep the first one created
        DB::statement('
            DELETE FROM service_plan
            WHERE id NOT IN (
                SELECT MIN(id)
                FROM service_plan
                GROUP BY name, tenant_id
            )
        ');

        // Remove duplicate routers - keep the first one created
        DB::statement('
            DELETE FROM router
            WHERE id NOT IN (
                SELECT MIN(id)
                FROM router
                GROUP BY name, tenant_id
            )
        ');

        // Remove duplicate sectoriales - keep the first one created
        DB::statement('
            DELETE FROM sectorial
            WHERE id NOT IN (
                SELECT MIN(id)
                FROM sectorial
                GROUP BY name
            )
        ');

        // Remove duplicate customer profiles - keep the first one created
        DB::statement('
            DELETE FROM customer_profile
            WHERE user_id NOT IN (
                SELECT MIN(user_id)
                FROM customer_profile
                GROUP BY name, last_name
            )
        ');
    }

    public function down(): void
    {
        // This migration cannot be safely rolled back as the deleted data is lost
    }
};
