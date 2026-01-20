<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('suspension_action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->nullable()->constrained('router')->onDelete('set null');
            $table->foreignId('customer_id')->constrained('users')->onDelete('cascade');
            $table->string('ip', 45)->nullable();
            $table->enum('action', ['SUSPEND', 'UNSUSPEND', 'INSTALL_POLICY'])->index();
            $table->enum('status', ['success', 'failed', 'pending'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index(['router_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suspension_action_logs');
    }
};
