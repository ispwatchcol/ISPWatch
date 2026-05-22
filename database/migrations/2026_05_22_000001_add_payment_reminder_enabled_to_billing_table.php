<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('billing', function (Blueprint $table) {
            $table->boolean('payment_reminder_enabled')
                ->default(true)
                ->after('payment_reminder')
                ->comment('Whether to send the day-of-month payment reminder configured in payment_reminder');
        });
    }

    public function down(): void
    {
        Schema::table('billing', function (Blueprint $table) {
            $table->dropColumn('payment_reminder_enabled');
        });
    }
};
