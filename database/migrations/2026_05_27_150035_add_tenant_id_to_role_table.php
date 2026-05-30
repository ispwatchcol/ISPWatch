<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // sqlite (tests) has no information_schema — use the portable Schema
        // helper. Postgres/MySQL (dev/prod) keep the original probe.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            if (!Schema::hasColumn('role', 'tenant_id')) {
                Schema::table('role', function (Blueprint $table) {
                    $table->foreignId('tenant_id')->nullable()->after('id')->constrained('tenant');
                });
            }
            return;
        }

        Schema::table('role', function (Blueprint $table) {
            $exists = DB::select("
                SELECT 1 FROM information_schema.columns
                WHERE table_name = 'role' AND column_name = 'tenant_id'
            ");

            if (empty($exists)) {
                $table->foreignId('tenant_id')->nullable()->after('id')->constrained('tenant');
            }
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            if (Schema::hasColumn('role', 'tenant_id')) {
                Schema::table('role', function (Blueprint $table) {
                    $table->dropColumn('tenant_id');
                });
            }
            return;
        }

        Schema::table('role', function (Blueprint $table) {
            $exists = DB::select("
                SELECT 1 FROM information_schema.columns
                WHERE table_name = 'role' AND column_name = 'tenant_id'
            ");

            if (!empty($exists)) {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            }
        });
    }
};
