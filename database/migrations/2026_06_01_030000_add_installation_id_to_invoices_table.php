<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'installation_id')) {
                $table->unsignedBigInteger('installation_id')->nullable();
                $table->foreign('installation_id')
                    ->references('id')
                    ->on('customer_installations')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'installation_id')) {
                $table->dropForeign(['installation_id']);
                $table->dropColumn('installation_id');
            }
        });
    }
};
