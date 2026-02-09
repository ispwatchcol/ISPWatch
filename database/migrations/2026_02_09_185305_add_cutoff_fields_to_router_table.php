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
        Schema::table('router', function (Blueprint $table) {
            // Automatic suspension settings
            $table->date('next_cutoff_date')->nullable()->after('falla_general');
            $table->time('cutoff_hour')->nullable()->after('next_cutoff_date');
            $table->boolean('auto_suspend_enabled')->default(false)->after('cutoff_hour');
            $table->unsignedTinyInteger('suspend_tolerance')->default(2)->after('auto_suspend_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('router', function (Blueprint $table) {
            $table->dropColumn([
                'next_cutoff_date',
                'cutoff_hour',
                'auto_suspend_enabled',
                'suspend_tolerance',
            ]);
        });
    }
};
