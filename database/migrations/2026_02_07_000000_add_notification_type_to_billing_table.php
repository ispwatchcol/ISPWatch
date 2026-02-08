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
        Schema::table('billing', function (Blueprint $table) {
            $table->enum('notification_type', ['email', 'whatsapp', 'both'])
                ->default('email')
                ->after('notificar_wpp')
                ->comment('Notification method for invoice creation: email, whatsapp, or both');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billing', function (Blueprint $table) {
            $table->dropColumn('notification_type');
        });
    }
};
