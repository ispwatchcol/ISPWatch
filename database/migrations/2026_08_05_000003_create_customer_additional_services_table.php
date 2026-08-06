<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asignación de un servicio adicional a un cliente.
 *
 * No es un pivote puro: tiene atributos e historia propios (desde cuándo, a qué
 * precio, quién lo activó), así que lleva id y modelo.
 *
 * customer_id apunta a users.id —no a customer_profile.id— porque es la misma
 * llave que usa invoices.customer_id: así el cobro mensual no tiene que traducir
 * entre dos identificadores del mismo cliente.
 *
 * Tres columnas cargan una decisión de diseño:
 *
 *  - price NULLABLE: null = "usa el precio del catálogo" y por tanto sigue sus
 *    cambios; con valor = precio congelado para este cliente. Sin esta distinción
 *    subir el precio de lista o se lo cambia a todos de golpe o no se lo cambia
 *    a nadie nunca.
 *
 *  - quantity: "dos routers extra" es un caso real y es más limpio que duplicar
 *    la asignación.
 *
 *  - assigned_at, aparte de created_at: la fecha y hora en que el servicio se
 *    activó para este cliente. Al dar de baja y reactivar, created_at deja de
 *    decir la verdad; assigned_at se puede refrescar sin perder la fila.
 *
 * ends_at permite programar la baja sin borrar; is_active es el interruptor
 * inmediato. Las asignaciones NO se borran cuando se dan de baja: la factura
 * vieja tiene que poder seguir explicando qué se le cobró al cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_additional_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('additional_service_id');
            $table->decimal('price', 15, 2)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('assigned_at')->nullable();
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('cascade');
            $table->foreign('customer_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('additional_service_id')->references('id')->on('additional_services')->onDelete('cascade');
            $table->foreign('assigned_by')->references('id')->on('users')->nullOnDelete();

            // La consulta del ciclo mensual: los adicionales activos de un
            // cliente. Se ejecuta una vez por cliente facturable en cada
            // corrida, así que conviene que no toque la tabla entera.
            $table->index(['customer_id', 'is_active']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_additional_services');
    }
};
