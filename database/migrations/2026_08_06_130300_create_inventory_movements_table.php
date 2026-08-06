<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kardex: la historia de cada equipo y de cada consumible. Append-only.
 *
 * inventory_device dice dónde está el equipo AHORA. Esta tabla dice cómo llegó
 * ahí: entró por compra, se le entregó a Juan, Juan lo instaló en casa del
 * cliente 412, volvió por garantía. Sin ella la pregunta "¿quién tenía esta LDF
 * antes de perderse?" no tiene respuesta, que es literalmente el motivo por el
 * que se pidió la funcionalidad.
 *
 * Nunca se actualiza ni se borra una fila: un movimiento equivocado se corrige
 * con el movimiento contrario, igual que en contabilidad. Por eso sólo hay
 * created_at (no timestamps completos).
 *
 * Origen y destino son polimórficos porque los extremos no son del mismo tipo:
 *
 *   branch   → una sucursal/bodega        supplier → el proveedor (entrada)
 *   user     → un empleado o técnico      customer → el cliente (instalación)
 *   scrap    → baja (dañado o perdido)
 *
 * device_serial guarda una copia del serial en el momento del movimiento. Es
 * deliberadamente redundante con inventory_device: si el equipo se borra del
 * inventario, la traza tiene que seguir diciendo qué se movió. La FK es SET NULL
 * por lo mismo — el histórico no se va con el equipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('stock_id')->nullable();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->string('device_serial')->nullable();
            $table->string('type', 20);                      // entrada|traspaso|instalacion|devolucion|baja
            $table->decimal('quantity', 12, 2)->default(1);
            $table->string('from_type', 20)->nullable();
            $table->unsignedBigInteger('from_id')->nullable();
            $table->string('to_type', 20)->nullable();
            $table->unsignedBigInteger('to_id')->nullable();
            $table->unsignedBigInteger('installation_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('cascade');
            $table->foreign('stock_id')->references('id')->on('inventory_stock')->nullOnDelete();
            $table->foreign('device_id')->references('id')->on('inventory_device')->nullOnDelete();
            $table->foreign('installation_id')->references('id')->on('customer_installations')->nullOnDelete();
            $table->foreign('customer_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            // "Historial de este equipo" y "qué movió Juan este mes".
            $table->index(['tenant_id', 'device_id']);
            $table->index(['tenant_id', 'stock_id']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
