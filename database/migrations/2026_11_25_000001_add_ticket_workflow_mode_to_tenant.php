<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modo del flujo de tickets por ISP: `formal` o `simple` (bitácora § 133).
 *
 * Nace en **formal** para todos, a propósito: es exactamente el comportamiento
 * de hoy, así que desplegar esto no le cambia nada a ningún ISP. El simple lo
 * enciende el administrador de cada ISP desde Configuración → Soporte.
 *
 * Al revés sería peor: encender el simple por defecto relajaría en silencio
 * las reglas de cierre de un ISP que las exige por contrato (CNO, que es de
 * donde salieron).
 *
 * Una columna de texto y no un booleano: si mañana hay un tercer modo, no
 * hace falta otra migración ni reinterpretar `true`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tenant', 'ticket_workflow_mode')) {
            return;
        }

        Schema::table('tenant', function (Blueprint $table) {
            $table->string('ticket_workflow_mode', 10)->default('formal');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('tenant', 'ticket_workflow_mode')) {
            return;
        }

        Schema::table('tenant', function (Blueprint $table) {
            $table->dropColumn('ticket_workflow_mode');
        });
    }
};
