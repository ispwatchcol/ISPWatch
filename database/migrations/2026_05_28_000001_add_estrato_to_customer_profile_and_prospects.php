<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('customer_profile', 'estrato')) {
            Schema::table('customer_profile', function (Blueprint $table) {
                $table->unsignedTinyInteger('estrato')->nullable()->after('installation_date');
            });
        }

        if (Schema::hasTable('prospects') && !Schema::hasColumn('prospects', 'estrato')) {
            Schema::table('prospects', function (Blueprint $table) {
                $table->unsignedTinyInteger('estrato')->nullable()->after('state');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_profile', 'estrato')) {
            Schema::table('customer_profile', function (Blueprint $table) {
                $table->dropColumn('estrato');
            });
        }

        if (Schema::hasTable('prospects') && Schema::hasColumn('prospects', 'estrato')) {
            Schema::table('prospects', function (Blueprint $table) {
                $table->dropColumn('estrato');
            });
        }
    }
};
