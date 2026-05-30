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
        Schema::table('sectorial', function (Blueprint $table) {
            if (!Schema::hasColumn('sectorial', 'antenna_type')) {
                $table->string('antenna_type', 100)
                    ->nullable()
                    ->after('coverage_radius_meters')
                    ->comment('Antenna model (e.g. Mimosa B5x, Mikrotik QRT); drives the default coverage radius drawn on the customer map');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sectorial', function (Blueprint $table) {
            if (Schema::hasColumn('sectorial', 'antenna_type')) {
                $table->dropColumn('antenna_type');
            }
        });
    }
};
