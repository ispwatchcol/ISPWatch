<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_profile', function (Blueprint $table) {
            $table->dropUnique('customer_profile_name_last_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customer_profile', function (Blueprint $table) {
            $table->unique(['name', 'last_name']);
        });
    }
};
