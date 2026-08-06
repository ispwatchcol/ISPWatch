<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add prospect_id (nullable)
        if (!Schema::hasColumn('customer_installations', 'prospect_id')) {
            Schema::table('customer_installations', function (Blueprint $table) {
                $table->unsignedBigInteger('prospect_id')->nullable()->after('customer_id');
                $table->index('prospect_id');
            });
        }

        // Make customer_id nullable — a row may be linked to a prospect instead.
        // PostgreSQL accepts ALTER COLUMN ... DROP NOT NULL. Use a raw statement
        // because Schema Builder's change() requires doctrine/dbal.
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE customer_installations ALTER COLUMN customer_id DROP NOT NULL');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE customer_installations MODIFY customer_id BIGINT UNSIGNED NULL');
        } elseif ($driver === 'sqlite') {
            // change() ya no necesita doctrine/dbal (Laravel 11+), así que la
            // base de pruebas puede representar lo mismo que producción: sin
            // esto customer_id seguía siendo NOT NULL en sqlite y no se podía
            // crear una orden de PROSPECTO (sin cliente) en los tests.
            Schema::table('customer_installations', function (Blueprint $table) {
                $table->unsignedBigInteger('customer_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_installations', 'prospect_id')) {
            Schema::table('customer_installations', function (Blueprint $table) {
                $table->dropIndex(['prospect_id']);
                $table->dropColumn('prospect_id');
            });
        }

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE customer_installations ALTER COLUMN customer_id SET NOT NULL');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE customer_installations MODIFY customer_id BIGINT UNSIGNED NOT NULL');
        }
    }
};
