<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sectorial', function (Blueprint $table) {
            $table->dropUnique('sectorial_name_unique');
            $table->unique(['name', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::table('sectorial', function (Blueprint $table) {
            $table->dropUnique('sectorial_name_tenant_id_unique');
            $table->unique('name');
        });
    }
};
