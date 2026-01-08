<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('support_ticket', function (Blueprint $table) {
            $table->enum('category', ['technical', 'billing', 'services', 'general'])
                ->default('general')
                ->after('priority');
        });
    }

    public function down(): void
    {
        Schema::table('support_ticket', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
