<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_installations', function (Blueprint $table) {
            if (!Schema::hasColumn('customer_installations', 'technician_id')) {
                $table->unsignedBigInteger('technician_id')->nullable()->after('technician');
            }
            if (!Schema::hasColumn('customer_installations', 'sheet')) {
                $table->json('sheet')->nullable()->after('notes');
            }
            if (!Schema::hasColumn('customer_installations', 'customer_signature_path')) {
                $table->string('customer_signature_path', 500)->nullable()->after('sheet');
            }
            if (!Schema::hasColumn('customer_installations', 'technician_signature_path')) {
                $table->string('technician_signature_path', 500)->nullable()->after('customer_signature_path');
            }
            if (!Schema::hasColumn('customer_installations', 'signed_at')) {
                $table->timestamp('signed_at')->nullable()->after('technician_signature_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_installations', function (Blueprint $table) {
            foreach (['technician_id', 'sheet', 'customer_signature_path', 'technician_signature_path', 'signed_at'] as $col) {
                if (Schema::hasColumn('customer_installations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
