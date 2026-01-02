<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('billing', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_type')->nullable();
            $table->date('create_invoice')->nullable();
            $table->date('payment_day')->nullable();
            $table->date('payment_reminder')->nullable();
            $table->date('cut_day')->nullable();
            $table->integer('overdue_invoices')->default(0);
            $table->decimal('amount', 10, 2)->nullable();
            $table->enum('status', ['pending', 'paid', 'overdue', 'cancelled'])->default('pending');
            $table->timestamps();

            $table->foreign('id_type')->references('id')->on('type_billing')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing');
    }
};
