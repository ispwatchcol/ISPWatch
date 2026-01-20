<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Add next_invoice_number to tenants table for concurrency-safe sequential numbering
        Schema::table('tenant', function (Blueprint $table) {
            $table->unsignedInteger('next_invoice_number')->default(1)->after('address');
        });

        // Add unique constraint for tenant_id + number on invoices
        Schema::table('invoices', function (Blueprint $table) {
            $table->unique(['tenant_id', 'number'], 'unique_tenant_invoice_number');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('unique_tenant_invoice_number');
        });

        Schema::table('tenant', function (Blueprint $table) {
            $table->dropColumn('next_invoice_number');
        });
    }
};
