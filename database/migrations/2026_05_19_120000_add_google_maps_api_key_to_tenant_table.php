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
        Schema::table('tenant', function (Blueprint $table) {
            $table->string('google_maps_api_key', 255)
                ->nullable()
                ->after('country')
                ->comment('Per-tenant Google Maps JavaScript API key used to render the customer map');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->dropColumn('google_maps_api_key');
        });
    }
};
