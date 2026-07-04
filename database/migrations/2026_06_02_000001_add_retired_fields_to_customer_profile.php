<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_profile', function (Blueprint $table) {
            $table->string('last_ip', 45)->nullable()->after('ip_user');
            $table->timestamp('retired_at')->nullable()->after('last_ip');
            $table->string('retired_reason', 500)->nullable()->after('retired_at');
        });
    }

    public function down(): void
    {
        Schema::table('customer_profile', function (Blueprint $table) {
            $table->dropColumn(['last_ip', 'retired_at', 'retired_reason']);
        });
    }
};
