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
            $table->boolean('notificar_wpp')->default(false)->after('status')->comment('WhatsApp payment reminder enabled');
            $table->text('comments')->nullable()->after('notificar_wpp')->comment('Billing notes and comments');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billing', function (Blueprint $table) {
            $table->dropColumn(['notificar_wpp', 'comments']);
        });
    }
};
