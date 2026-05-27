<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
