<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cola de órdenes CoA / Disconnect hacia el NAS.
 *
 * POR QUÉ HACE FALTA UNA COLA Y NO UN ENVÍO DIRECTO
 * --------------------------------------------------
 * El paquete CoA es UDP y va de ISPWatch HACIA el router, o sea en sentido
 * contrario al resto del tráfico RADIUS. Los MikroTik viven detrás del overlay
 * VPN del CORE (WireGuard 172.18.<tenant>.0/24, o L2TP en los v6) y el droplet
 * de la API no está dentro de ese overlay: no puede alcanzarlos.
 *
 * Por eso el emisor real es un agente que corre en el host del FreeRADIUS, que
 * sí es par del overlay. El agente consulta las órdenes pendientes, ejecuta
 * radclient contra el NAS y confirma el resultado. Esta tabla es el contrato
 * entre ambos.
 *
 * ES EL MISMO PATRÓN QUE suspension_action_logs
 * ----------------------------------------------
 * Cola + reintentos con backoff + confirmación explícita. Se eligió a
 * propósito: ese mecanismo ya se ganó su confianza con los cortes por
 * firewall, y no hay razón para inventar uno nuevo que el equipo tenga que
 * aprender por separado.
 *
 * CORTAR Y RECONECTAR USAN LA MISMA COLA
 * ---------------------------------------
 * La reconexión al pagar no es un camino especial: es otra orden de
 * DISCONNECT. Al caerse la sesión el cliente reautentica y /authorize le
 * devuelve el perfil normal. Que sea la misma operación en ambos sentidos es
 * lo que la hace idempotente — repetirla no rompe nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radius_coa_commands', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            // Sin router no hay a dónde mandar el paquete: aquí el FK sí es
            // obligatorio y cascada, a diferencia de las tablas de bitácora.
            $table->foreignId('router_id')->constrained('router')->onDelete('cascade');
            $table->foreignId('customer_id')->nullable()->constrained('users')->onDelete('cascade');

            $table->string('username', 128)->index();

            // DISCONNECT tira la sesión y obliga a reautenticar (el perfil sale
            // de ISPWatch en ese momento). COA reescribe atributos en caliente y
            // queda disponible para casos puntuales, pero no es el camino por
            // defecto: deja el estado viviendo en la sesión del router.
            $table->enum('action', ['DISCONNECT', 'COA'])->default('DISCONNECT');

            // Por qué se emitió: overdue / reactivated / plan_changed / manual.
            $table->string('reason', 64)->nullable();

            $table->enum('status', ['pending', 'sent', 'confirmed', 'failed'])->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            // Backoff: el agente solo toma órdenes cuya next_attempt_at ya pasó.
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();

            // La consulta del agente en cada poll.
            $table->index(['status', 'next_attempt_at']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radius_coa_commands');
    }
};
