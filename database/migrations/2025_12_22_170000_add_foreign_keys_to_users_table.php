<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $table->unsignedBigInteger('role_id')->nullable()->after('tenant_id');
            $table->unsignedBigInteger('service_id')->nullable()->after('role_id');
            $table->unsignedBigInteger('sectorial_id')->nullable()->after('service_id');
            $table->string('tel')->nullable()->after('email');

            $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('set null');
            $table->foreign('role_id')->references('id')->on('role')->onDelete('set null');
            $table->foreign('service_id')->references('id')->on('service_plan')->onDelete('set null');
            $table->foreign('sectorial_id')->references('id')->on('sectorial')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropForeign(['role_id']);
            $table->dropForeign(['service_id']);
            $table->dropForeign(['sectorial_id']);
            $table->dropColumn(['tenant_id', 'role_id', 'service_id', 'sectorial_id', 'tel']);
        });
    }
};
