<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('customer_profile', 'precinto')) {
            Schema::table('customer_profile', function (Blueprint $table) {
                $table->string('precinto', 100)->nullable()->after('address');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_profile', 'precinto')) {
            Schema::table('customer_profile', function (Blueprint $table) {
                $table->dropColumn('precinto');
            });
        }
    }
};
