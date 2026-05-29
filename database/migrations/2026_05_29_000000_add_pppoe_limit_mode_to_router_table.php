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
            // PPPoE rate-limit strategy when `pppoe` control is enabled:
            //   - 'dynamic': MikroTik enforces the rate-limit via the PPP profile/plan.
            //   - 'queue'  : additionally create a Simple Queue rule for the client.
            $table->string('pppoe_limit_mode', 20)
                ->default('dynamic')
                ->after('pppoe')
                ->comment('PPPoE limit strategy: dynamic | queue');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('router', function (Blueprint $table) {
            $table->dropColumn('pppoe_limit_mode');
        });
    }
};
