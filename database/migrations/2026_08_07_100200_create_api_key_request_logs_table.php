<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de cada petición atendida por la API pública de solo lectura.
 *
 * Sirve para tres cosas concretas: demostrarle al cliente qué consultó y cuándo
 * si discute una factura de consumo, detectar una llave filtrada (misma llave
 * desde IPs o a ritmos que no cuadran) y medir si un endpoint se está poniendo
 * lento antes de que el cliente se queje.
 *
 * Se registra TODO, incluidos los rechazos (401/403/405/429): un intento
 * bloqueado es justamente el evento que interesa auditar. El volumen está
 * acotado por el rate limiter (60/min por llave) y `api-keys:prune-logs` borra
 * lo más viejo de 90 días.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_key_request_logs', function (Blueprint $table) {
            $table->id();

            // Nullable: un token revocado o inexistente no resuelve cliente,
            // y ese intento es precisamente el que hay que dejar registrado.
            $table->unsignedBigInteger('api_client_id')->nullable();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();

            $table->string('method', 10);
            $table->string('path', 255);
            $table->string('ip', 45)->nullable();
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('duration_ms')->nullable();

            // Motivo del rechazo cuando no es 2xx (ip_not_allowed, revoked,
            // method_not_allowed…). Null en las peticiones exitosas.
            $table->string('denied_reason', 50)->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['api_client_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_key_request_logs');
    }
};
