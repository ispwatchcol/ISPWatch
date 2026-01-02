<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email_tenant')->nullable()->after('email');
            $table->string('user_name')->nullable()->after('name');
            $table->string('user_lastname')->nullable()->after('user_name');
            $table->boolean('status')->default(true)->after('user_lastname');
            $table->timestamp('last_access')->nullable()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email_tenant', 'user_name', 'user_lastname', 'last_access']);
        });
    }
};
