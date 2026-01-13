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
            if (!Schema::hasColumn('router', 'wan_interface')) {
                $table->string('wan_interface')->nullable()->after('lan_interface');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('router', function (Blueprint $table) {
            $table->dropColumn('wan_interface');
        });
    }
};
