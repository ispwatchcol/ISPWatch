<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('invoice_type')->default('monthly')->after('service_id');
            $table->unsignedBigInteger('ticket_id')->nullable()->after('invoice_type');
            $table->foreign('ticket_id')->references('id')->on('support_ticket')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['ticket_id']);
            $table->dropColumn(['invoice_type', 'ticket_id']);
        });
    }
};
