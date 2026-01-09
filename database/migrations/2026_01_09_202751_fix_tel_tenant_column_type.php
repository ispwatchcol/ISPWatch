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
            // Change tel_tenant from smallint to string
            $table->string('tel_tenant', 50)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            // Revert back (though this might cause data loss if non-numeric values exist)
            $table->smallInteger('tel_tenant')->nullable()->change();
        });
    }
};
