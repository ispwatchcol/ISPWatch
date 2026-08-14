<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de autenticaciones RADIUS (aceptadas y rechazadas).
 *
 * POR QUÉ EXISTE
 * --------------
 * Contesta "¿por qué no conecta este cliente?" sin que soporte tenga que
 * entrar al RouterBoard. Hoy esa pregunta obliga a abrir Winbox y mirar el log
 * del equipo; con RADIUS la respuesta está en la BD y con el motivo explícito
 * (no existe / suspendido / excluido de facturación / credencial incorrecta).
 *
 * SE REGISTRAN LOS ACCEPT TAMBIÉN, NO SOLO LOS RECHAZOS
 * ------------------------------------------------------
 * Un accept con perfil 'moroso' es exactamente lo que confirma que un corte se
 * aplicó, así que esta tabla es la segunda señal del reconciliador junto a
 * radius_sessions. Filtrar solo rechazos nos dejaría ciegos justo ahí.
 *
 * CRECIMIENTO
 * -----------
 * Alto volumen: una fila por intento de conexión, y un PPPoE que flapea genera
 * decenas por hora. Retención corta con prune por created_at, como
 * traffic_samples. El índice de created_at está puesto para que ese borrado no
 * escanee la tabla entera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radius_auth_logs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('router_id')->nullable()->constrained('router')->onDelete('set null');
            $table->foreignId('customer_id')->nullable()->constrained('users')->onDelete('cascade');

            // El User-Name tal como lo mandó el NAS. Se guarda aunque no haya
            // cliente asociado: un usuario inexistente es justamente el caso
            // que soporte necesita ver.
            $table->string('username', 128)->index();

            $table->boolean('accepted');
            // Motivo legible y estable (no un mensaje libre): se muestra en el
            // panel y se filtra por él.
            $table->string('reason', 64)->nullable();
            $table->string('profile', 32)->nullable();

            $table->string('nas_ip_address', 45)->nullable();
            $table->string('calling_station_id', 64)->nullable();

            $table->timestamps();

            $table->index('created_at');
            $table->index(['username', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radius_auth_logs');
    }
};
