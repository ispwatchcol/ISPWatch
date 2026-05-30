<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // sqlite (tests) has no information_schema. The composite unique never
        // pre-exists in a fresh test DB, so just create it and skip the probes.
        // Postgres/MySQL (dev/prod) keep the original constraint-aware logic.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('sectorial', function (Blueprint $table) {
                $table->unique(['name', 'tenant_id']);
            });
            return;
        }

        Schema::table('sectorial', function (Blueprint $table) {
            // Drop the old single-column unique only if it exists (may be absent in some schemas)
            $exists = DB::select("
                SELECT 1 FROM information_schema.table_constraints
                WHERE constraint_name = 'sectorial_name_unique'
                  AND table_name = 'sectorial'
            ");

            if (!empty($exists)) {
                $table->dropUnique('sectorial_name_unique');
            }

            // Add composite unique only if it doesn't already exist
            $composite = DB::select("
                SELECT 1 FROM information_schema.table_constraints
                WHERE constraint_name = 'sectorial_name_tenant_id_unique'
                  AND table_name = 'sectorial'
            ");

            if (empty($composite)) {
                $table->unique(['name', 'tenant_id']);
            }
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('sectorial', function (Blueprint $table) {
                $table->dropUnique('sectorial_name_tenant_id_unique');
            });
            return;
        }

        Schema::table('sectorial', function (Blueprint $table) {
            $composite = DB::select("
                SELECT 1 FROM information_schema.table_constraints
                WHERE constraint_name = 'sectorial_name_tenant_id_unique'
                  AND table_name = 'sectorial'
            ");

            if (!empty($composite)) {
                $table->dropUnique('sectorial_name_tenant_id_unique');
            }

            $exists = DB::select("
                SELECT 1 FROM information_schema.table_constraints
                WHERE constraint_name = 'sectorial_name_unique'
                  AND table_name = 'sectorial'
            ");

            if (empty($exists)) {
                $table->unique('name');
            }
        });
    }
};
