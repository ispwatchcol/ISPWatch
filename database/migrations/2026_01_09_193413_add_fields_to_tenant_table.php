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
            $table->string('email_tenant')->nullable()->after('domain');
            $table->string('tel')->nullable()->after('email_tenant');
            $table->text('address')->nullable()->after('tel');
            $table->string('timezone')->default('America/Bogota')->after('address');
            $table->string('currency')->default('COP')->after('timezone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->dropColumn(['email_tenant', 'tel', 'address', 'timezone', 'currency']);
        });
    }
};
