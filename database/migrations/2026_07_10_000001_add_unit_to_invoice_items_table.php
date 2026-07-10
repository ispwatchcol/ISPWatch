<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unit of measure for a line item's quantity (e.g. "metros", "horas",
     * "kg"). Nullable — a null/empty unit means plain count ("unidad(es)").
     * Used by ticket/service charges where a quantity is not always a count.
     */
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('unit', 30)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('unit');
        });
    }
};
