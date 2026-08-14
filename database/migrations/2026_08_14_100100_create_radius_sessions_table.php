<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espejo de las sesiones RADIUS vivas, alimentado por Accounting.
 *
 * PARA QUÉ SIRVE DE VERDAD
 * ------------------------
 * No es solo un informe. Es la señal de confirmación de corte que hoy no
 * existe: con address-lists hay que preguntarle al RouterBoard si la regla
 * quedó puesta (y de ahí sale billing:reconcile-suspensions). Con RADIUS la
 * prueba es local — si el cliente no tiene sesión abierta, el corte se aplicó.
 *
 * POR QUÉ acct_unique_id ES ÚNICO Y acct_session_id NO
 * ----------------------------------------------------
 * Acct-Session-Id lo genera el NAS y solo es único DENTRO de ese NAS; dos
 * routers distintos pueden emitir el mismo. FreeRADIUS calcula
 * Acct-Unique-Session-Id justamente para eso (hash de session-id + NAS + puerto),
 * y es la clave por la que se hace el upsert de Start/Interim/Stop. Sin este
 * único, un Interim reenviado duplicaría filas y el conteo de sesiones activas
 * mentiría.
 *
 * OCTETOS EN 64 BITS
 * ------------------
 * RADIUS manda Acct-Input-Octets en 32 bits y desborda cada 4 GB; el excedente
 * viaja aparte en Acct-Input-Gigawords. El servicio recompone
 * (gigawords << 32) + octets antes de escribir aquí, por eso la columna es
 * bigint y no integer.
 *
 * CRECIMIENTO
 * -----------
 * Tabla de alto volumen. Las sesiones cerradas se podan con un comando de
 * prune, igual que traffic_samples; las abiertas nunca se borran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radius_sessions', function (Blueprint $table) {
            $table->id();

            // tenant_id explícito, no derivado del router: una sesión puede
            // llegar de un NAS que todavía no resolvimos y aun así hay que
            // poder aislarla por inquilino en el panel.
            $table->unsignedBigInteger('tenant_id')->nullable()->index();

            $table->foreignId('router_id')->nullable()->constrained('router')->onDelete('set null');
            // Nullable a propósito: si llega contabilidad de un usuario que no
            // reconocemos, se guarda igual. Perder la evidencia es peor que
            // tener una fila huérfana, y username queda como pista.
            $table->foreignId('customer_id')->nullable()->constrained('users')->onDelete('cascade');

            $table->string('username', 128)->index();
            $table->string('acct_session_id', 64);
            $table->string('acct_unique_id', 64)->unique();

            $table->string('nas_ip_address', 45)->nullable();
            $table->string('framed_ip_address', 45)->nullable();
            $table->string('calling_station_id', 64)->nullable();
            $table->string('called_station_id', 64)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_interim_at')->nullable();
            $table->timestamp('stopped_at')->nullable();

            $table->unsignedBigInteger('input_octets')->default(0);
            $table->unsignedBigInteger('output_octets')->default(0);
            $table->unsignedInteger('session_time')->default(0);
            $table->string('terminate_cause', 64)->nullable();

            // Con qué perfil se le respondió: 'normal' o 'moroso'. Deja ver de
            // un vistazo si un cliente está navegando dentro del walled garden.
            $table->string('profile', 32)->nullable();

            $table->timestamps();

            // "¿tiene sesión abierta este cliente?" — la consulta del
            // reconciliador de cortes.
            $table->index(['customer_id', 'stopped_at']);
            $table->index(['router_id', 'stopped_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radius_sessions');
    }
};
