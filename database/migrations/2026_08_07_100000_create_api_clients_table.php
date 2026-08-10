<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consumidores externos de la API de solo lectura.
 *
 * Un `api_client` NO es un usuario: no tiene contraseña, no inicia sesión y no
 * aparece en Personal. Es la identidad a la que se cuelgan los tokens Sanctum
 * de la API pública (`/api/v1/partner/*`).
 *
 * Por qué una tabla propia y no una fila en `users`: un usuario-máquina en
 * `users` heredaría el camino de login, saldría en los listados de Personal y,
 * si alguien le asignara por error el rol Administrador (role_id = 1), el
 * bypass de CheckPermission le abriría toda la aplicación. Una tabla aparte
 * hace que ese error sea imposible de cometer — un ApiClient no tiene rol que
 * asignar y el middleware de la app lo rechaza de plano.
 *
 * El aislamiento entre tenants se apoya en `tenant_id`: el guard de Sanctum
 * resuelve el token a este modelo y el global scope de BelongsToTenant lee
 * `auth()->user()->tenant_id` exactamente igual que con un usuario humano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name');
            $table->string('contact_email')->nullable();
            $table->text('description')->nullable();

            // Interruptor de emergencia: apagar el cliente corta TODAS sus
            // llaves de golpe sin tener que revocarlas una por una.
            $table->boolean('is_active')->default(true);

            // Usuario del tenant operador (tenant 1) que dio de alta al cliente.
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_clients');
    }
};
