<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clave de idempotencia de cada línea de equipo o material.
 *
 * El saldo ya no se puede dejar en negativo (lockForUpdate en el ledger), pero
 * eso no protege del REENVÍO: el técnico pulsa «Agregar 120 m», la señal se
 * cae antes de que llegue la respuesta, lo vuelve a pulsar, y el servidor —que
 * sí recibió la primera— descuenta otros 120 m. Los dos consumos son válidos
 * para el ledger; el doble descuento es real.
 *
 * La pantalla genera una clave por intento y la reenvía igual mientras el
 * intento no termine. El servidor guarda la clave en la línea y, si vuelve a
 * llegar, devuelve la línea que ya existe en vez de crear otra.
 *
 * La garantía la da el índice único y no una consulta previa: dos peticiones
 * simultáneas con la misma clave pasan las dos la consulta, pero sólo una
 * puede insertar; la otra revierte toda su transacción —también el descuento
 * del saldo— y responde con la línea de la primera.
 *
 * Nulable: las líneas viejas y los clientes de la API que no la mandan siguen
 * funcionando como antes. Un índice único admite varios NULL en PostgreSQL y
 * en SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['installation_equipment', 'ticket_equipment'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                $table->string('client_request_id', 64)->nullable();
                $table->unique(['tenant_id', 'client_request_id'], "{$tabla}_client_request_unique");
            });
        }
    }

    public function down(): void
    {
        foreach (['installation_equipment', 'ticket_equipment'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                $table->dropUnique("{$tabla}_client_request_unique");
                $table->dropColumn('client_request_id');
            });
        }
    }
};
