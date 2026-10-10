<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué equipos y materiales se movieron en una visita de SOPORTE.
 *
 * Hasta ahora el inventario sólo sabía salir por una orden de instalación:
 * `installation_equipment` cuelga de `customer_installations` y el único método
 * del ledger que deja un equipo en casa del cliente exigía una instalación. En
 * la operación real la mitad de los equipos se entregan en un ticket —se quemó
 * el router, se cambia la ONU por garantía— y ese camino no existía: el técnico
 * cobraba el equipo escribiendo la descripción a mano en «Cargo Asociado» y el
 * aparato seguía figurando disponible en bodega para siempre.
 *
 * Tabla propia y no una columna más en `installation_equipment` por una razón
 * que no es de estilo: aquí el movimiento tiene DOS sentidos. Una instalación
 * sólo entrega; una visita de soporte casi siempre cambia un equipo por otro,
 * y el que se retira también tiene que dejar rastro. Eso es `direction`:
 *
 *   out → salió del inventario y quedó en casa del cliente
 *   in  → volvió de casa del cliente al inventario (bodega o mochila del técnico)
 *
 * `source_type`/`source_id` es el custodio interno del OTRO extremo: de dónde
 * salió cuando `direction = out`, a dónde volvió cuando `direction = in`. Sin
 * eso, deshacer una línea no sabría a quién devolverle la existencia.
 *
 * En un retiro admite además `scrap`, que no es un custodio sino la BAJA: el
 * equipo volvió quemado y no vuelve a circular. Distinguirlo del retiro normal
 * no es un matiz — un aparato muerto devuelto a bodega cuenta como disponible y
 * alguien lo va a prometer en la siguiente instalación.
 *
 * `unit_price` congela el precio del catálogo al momento de la visita, igual que
 * en la instalación: si mañana sube el router, el ticket de ayer no cambia de
 * costo. En las líneas `in` va NULL — un retiro no se cobra.
 *
 * NO hay unique sobre `device_id`: un mismo equipo puede entrar y salir varias
 * veces a lo largo de su vida y cada paso es una fila. El invariante de verdad
 * —un equipo físico no está en dos casas a la vez— lo sostiene
 * `inventory_device.status`, que es una sola fila por aparato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_equipment', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('stock_id')->nullable();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->string('direction', 3)->default('out');   // out | in
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->string('source_type', 20)->nullable();    // branch | user | scrap
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('cascade');
            $table->foreign('ticket_id')->references('id')->on('support_ticket')->onDelete('cascade');
            $table->foreign('stock_id')->references('id')->on('inventory_stock')->nullOnDelete();
            $table->foreign('device_id')->references('id')->on('inventory_device')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'ticket_id']);
            $table->index('device_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_equipment');
    }
};
