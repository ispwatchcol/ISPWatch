<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_profile', function (Blueprint $table) {
            $table->decimal('credit_balance', 12, 2)->default(0)->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('customer_profile', function (Blueprint $table) {
            $table->dropColumn('credit_balance');
        });
    }
};
