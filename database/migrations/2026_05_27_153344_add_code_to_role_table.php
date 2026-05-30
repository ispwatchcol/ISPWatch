<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('role', 'code')) {
            Schema::table('role', function (Blueprint $table) {
                $table->string('code', 50)->nullable()->after('name');
            });
        }

        // Populate standard codes based on role name (all tenants, only where null).
        // sqlite (tests) lacks REGEXP_REPLACE — fall back to LOWER(name) there;
        // Postgres/MySQL keep the original slugifying expression.
        $elseExpr = DB::connection()->getDriverName() === 'sqlite'
            ? 'LOWER(name)'
            : "LOWER(REGEXP_REPLACE(name, '[^a-zA-Z0-9]', '_', 'g'))";

        DB::statement("
            UPDATE role SET code = CASE
                WHEN LOWER(name) LIKE '%administrador%' THEN 'admin'
                WHEN LOWER(name) LIKE '%staff%'         THEN 'staff'
                WHEN LOWER(name) LIKE '%cliente%'       THEN 'client'
                WHEN LOWER(name) LIKE '%contabilidad%'  THEN 'accounting'
                WHEN LOWER(name) LIKE '%tecnico%'
                  OR LOWER(name) LIKE '%técnico%'       THEN 'technician'
                ELSE {$elseExpr}
            END
            WHERE code IS NULL
        ");

    }

    public function down(): void
    {
        if (Schema::hasColumn('role', 'code')) {
            Schema::table('role', function (Blueprint $table) {
                $table->dropColumn('code');
            });
        }
    }
};
