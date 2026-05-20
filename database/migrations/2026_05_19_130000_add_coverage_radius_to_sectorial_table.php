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
            $table->unsignedInteger('coverage_radius_meters')
                ->nullable()
                ->after('coordinates')
                ->comment('Approximate wireless coverage radius (meters) drawn around the sectorial on the customer map');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sectorial', function (Blueprint $table) {
            $table->dropColumn('coverage_radius_meters');
        });
    }
};
