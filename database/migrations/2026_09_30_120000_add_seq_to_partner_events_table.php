<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Número de publicación del feed partner (KAN-112).
 *
 * POR QUÉ EL `id` DEJÓ DE SERVIR COMO CURSOR
 * -------------------------------------------
 * El `id` sale de la secuencia al INSERTAR, y el evento se inserta dentro de
 * la transacción del cambio que lo origina. Si A toma el id 100, B toma el 101
 * y B confirma primero, un integrador que lee en ese instante recibe el 101,
 * avanza su cursor y nunca ve el 100. Con la importación masiva —una sola
 * transacción de minutos— la ventana deja de ser teórica.
 *
 * `seq` lo asigna PartnerEventSequencer DESPUÉS del commit, en serie y sólo a
 * filas ya confirmadas: el orden en que un `seq` se vuelve visible coincide con
 * su orden numérico, que es lo que un cursor necesita.
 *
 * COMPATIBILIDAD
 * --------------
 * Los eventos existentes ya están confirmados, así que se publican con
 * `seq = id`. Los cursores y revisiones que un integrador tenga guardados hoy
 * siguen significando lo mismo, y lo nuevo sale por encima de max(id).
 *
 * Los índices por `id` se reemplazan por los mismos por `seq`: el feed y la
 * revisión ya no consultan por `id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_events', function (Blueprint $table) {
            $table->unsignedBigInteger('seq')->nullable()->after('id');
        });

        DB::table('partner_events')->whereNull('seq')->update(['seq' => DB::raw('id')]);

        Schema::table('partner_events', function (Blueprint $table) {
            $table->unique('seq');
            $table->index(['tenant_id', 'seq']);
            $table->index(['tenant_id', 'customer_id', 'seq']);

            $table->dropIndex(['tenant_id', 'id']);
            $table->dropIndex(['tenant_id', 'customer_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('partner_events', function (Blueprint $table) {
            $table->index(['tenant_id', 'id']);
            $table->index(['tenant_id', 'customer_id', 'id']);

            $table->dropIndex(['tenant_id', 'customer_id', 'seq']);
            $table->dropIndex(['tenant_id', 'seq']);
            $table->dropUnique(['seq']);
        });

        Schema::table('partner_events', function (Blueprint $table) {
            $table->dropColumn('seq');
        });
    }
};
