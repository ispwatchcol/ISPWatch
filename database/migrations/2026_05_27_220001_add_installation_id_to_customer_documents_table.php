<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('customer_documents', 'installation_id')) {
            Schema::table('customer_documents', function (Blueprint $table) {
                $table->unsignedBigInteger('installation_id')->nullable()->after('customer_id');
                $table->index('installation_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_documents', 'installation_id')) {
            Schema::table('customer_documents', function (Blueprint $table) {
                $table->dropIndex(['installation_id']);
                $table->dropColumn('installation_id');
            });
        }
    }
};
