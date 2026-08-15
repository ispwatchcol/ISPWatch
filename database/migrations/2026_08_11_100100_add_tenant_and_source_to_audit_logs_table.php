<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * audit_logs existía desde 2026-05 pero solo la escribían tres sitios a mano
 * (borrar factura, suspender/activar cliente) y le faltaban dos cosas para
 * servir como bitácora real de un sistema multi-tenant:
 *
 *  - tenant_id: sin él es imposible mostrarle a un operador de Tocaima lo que
 *    pasó en Tocaima. Es nullable porque los registros ya existentes no lo
 *    tienen y no siempre se puede inferir.
 *  - source: quién escribió el registro (web, api, console, import, scheduler).
 *    Es lo que distingue "un operador cambió el precio" de "lo cambió una carga
 *    masiva", que fue exactamente la ambigüedad del episodio 56.000 → 60.000.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $table->string('source', 20)->nullable()->after('user_agent');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            // Listado principal del visor: "qué pasó en esta sede, lo más reciente primero".
            $table->index(['tenant_id', 'created_at']);
            // Historial de un registro concreto: "todo lo que le pasó a este plan".
            $table->index(['model_type', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'created_at']);
            $table->dropIndex(['model_type', 'model_id']);
            $table->dropColumn(['tenant_id', 'source']);
        });
    }
};
