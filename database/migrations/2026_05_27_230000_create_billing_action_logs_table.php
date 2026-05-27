<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('billing_action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenant')->onDelete('cascade');
            $table->foreignId('router_id')->nullable()->constrained('router')->onDelete('set null');
            $table->foreignId('customer_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->onDelete('set null');

            $table->string('action', 64)->default('generate_monthly_invoice')->index();
            $table->date('period_start');
            $table->date('period_end');

            // success | failed | exhausted
            $table->string('status', 16)->default('failed')->index();
            $table->unsignedTinyInteger('attempts')->default(1);
            $table->text('last_error')->nullable();
            $table->timestamp('next_retry_at')->nullable()->index();

            $table->timestamps();

            // Idempotency: one log per customer per period per action
            $table->unique(['tenant_id', 'customer_id', 'period_start', 'action'], 'bal_unique_per_period');
            $table->index(['tenant_id', 'status', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_action_logs');
    }
};
