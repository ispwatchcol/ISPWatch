<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Failover de cortes: dota a suspension_action_logs de semántica de
 * reintento/backoff (igual que billing_action_logs) para que el
 * reconciliador pueda re-cortar en la RB a los clientes suspendidos en
 * la DB cuyo corte no quedó confirmado, sin martillar un router caído.
 *
 * El estado "agotado" es derivado (status='failed' && attempts>=MAX),
 * por eso NO se toca el enum `status` (evita alterar enums en Postgres).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('suspension_action_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('suspension_action_logs', 'attempts')) {
                $table->unsignedTinyInteger('attempts')->default(1)->after('status');
            }
            if (!Schema::hasColumn('suspension_action_logs', 'next_retry_at')) {
                $table->timestamp('next_retry_at')->nullable()->after('error_message')->index();
            }
            if (!Schema::hasColumn('suspension_action_logs', 'reason')) {
                $table->string('reason', 40)->nullable()->after('action');
            }
        });
    }

    public function down(): void
    {
        Schema::table('suspension_action_logs', function (Blueprint $table) {
            foreach (['attempts', 'next_retry_at', 'reason'] as $col) {
                if (Schema::hasColumn('suspension_action_logs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
