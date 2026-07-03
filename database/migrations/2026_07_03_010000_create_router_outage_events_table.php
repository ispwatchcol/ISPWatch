<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only log of "falla masiva" (mass outage) events per core/router.
 *
 * Each operator action records one row: type='outage' when a core goes down,
 * type='restored' when it recovers. Converza reads this table in READ-ONLY via
 * incremental id-cursor polling (same pattern it uses for payments/activations)
 * and broadcasts the matching WhatsApp template to every ACTIVE customer whose
 * customer_profile.router_id = router_id. ISPWatch never calls Converza.
 *
 * Never UPDATE/DELETE rows here — the monotonic id IS the cursor. affected_count
 * is a snapshot of connected customers at trigger time (for the UI/bitácora).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('router_outage_events')) {
            return;
        }

        Schema::create('router_outage_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('router_id');
            // 'outage' = core en falla, 'restored' = core restablecido.
            $table->string('type', 20);
            $table->unsignedInteger('affected_count')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            // Cursor de polling de Converza: MAX(id) por tenant / por router.
            $table->index(['tenant_id', 'id']);
            $table->index(['router_id', 'id']);

            $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('cascade');
            $table->foreign('router_id')->references('id')->on('router')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_outage_events');
    }
};
