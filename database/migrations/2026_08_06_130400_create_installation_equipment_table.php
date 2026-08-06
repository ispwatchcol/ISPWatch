<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué se usó en cada instalación: la LDF, el router, el plato y los 4 RJ45.
 *
 * Antes esto era UN campo dentro del JSON de la hoja (sheet.inventory_device_id),
 * así que una instalación sólo podía declarar un equipo. En la práctica una
 * visita lleva antena + router + materiales, y todo lo que no cabía en ese campo
 * terminaba escrito a mano en "Materiales utilizados", fuera de todo control.
 *
 * Cada fila es una línea de la instalación:
 *   device_id lleno  → un equipo serializado concreto (quantity siempre 1)
 *   device_id NULL   → un consumible del modelo stock_id, con quantity libre
 *
 * device_id es único: un equipo físico no puede estar instalado en dos casas a
 * la vez. La restricción vive en la base y no sólo en el servicio porque es el
 * invariante que hace confiable todo el módulo.
 *
 * unit_price congela el precio del catálogo al momento de la instalación: si
 * mañana sube el precio de la LDF, la instalación de ayer no debe cambiar de
 * costo. NULL = no se le cobró al cliente.
 *
 * source_type/source_id recuerdan de qué custodio salió la línea. Sin eso,
 * quitar un equipo de la hoja no sabría a quién devolvérselo y habría que
 * adivinarlo leyendo el kardex hacia atrás — con dos líneas del mismo modelo
 * en la misma visita, adivinar es equivocarse.
 *
 * Borrar la instalación borra sus líneas (CASCADE), pero NO el kardex: los
 * movimientos de inventory_movements quedan como historia independiente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installation_equipment', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('installation_id');
            $table->unsignedBigInteger('stock_id')->nullable();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->string('source_type', 20)->nullable();   // branch | user
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('cascade');
            $table->foreign('installation_id')->references('id')->on('customer_installations')->onDelete('cascade');
            $table->foreign('stock_id')->references('id')->on('inventory_stock')->nullOnDelete();
            $table->foreign('device_id')->references('id')->on('inventory_device')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->unique('device_id');
            $table->index(['tenant_id', 'installation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installation_equipment');
    }
};
