<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('customer_profile', 'installation_date')) {
            Schema::table('customer_profile', function (Blueprint $table) {
                $table->date('installation_date')->nullable()->after('precinto');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_profile', 'installation_date')) {
            Schema::table('customer_profile', function (Blueprint $table) {
                $table->dropColumn('installation_date');
            });
        }
    }
};
