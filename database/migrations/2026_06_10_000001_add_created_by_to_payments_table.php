<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Track WHICH staff user registered a payment.
     *
     * Until now `payments` only stored the customer who paid (customer_id);
     * there was no record of the operator/collector who captured the receipt.
     * Plain nullable unsignedBigInteger (no FK) mirrors the existing `created_by`
     * pattern in prospects/customer_installations and keeps the migration
     * portable on SQLite (which can't add FKs via ALTER) for the test suite.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('status')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'created_by')) {
                $table->dropColumn('created_by');
            }
        });
    }
};
