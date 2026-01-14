<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('customer_profile', 'status')) {
            Schema::table('customer_profile', function (Blueprint $table) {
                $table->boolean('status')->default(true)->after('router_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_profile', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
