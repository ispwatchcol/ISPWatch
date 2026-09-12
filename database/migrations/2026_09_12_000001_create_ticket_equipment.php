<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equipos cargados en un ticket de soporte (KAN-92).
 *
 * **Por qué una tabla espejo y no generalizar `installation_equipment`.** Esa
 * tabla tiene `unique('device_id')`: un equipo serializado puede estar en una
 * sola instalación, y esa garantía hay que conservarla. Volverla polimórfica
 * para que sirva a los dos casos obligaba a tocar el índice de un módulo que hoy
 * funciona y está auditado. Duplicar la forma cuesta menos que esa migración;
 * generalizar se hará cuando aparezca un tercer caso que lo justifique.
 *
 * `inventory_movements.ticket_id` es lo que hace rastreable la entrega: sin esa
 * columna el kardex diría «se lo llevó el cliente X» sin poder decir POR QUÉ, y
 * un equipo entregado en una visita de soporte quedaría indistinguible de uno
 * entregado en una instalación.
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
            $table->decimal('quantity', 12, 2)->default(1);
            // Congela el precio del momento, igual que installation_equipment:
            // si el catálogo cambia después, lo entregado no se reescribe.
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->string('source_type', 20)->nullable();   // branch | user
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('cascade');
            $table->foreign('ticket_id')->references('id')->on('support_ticket')->onDelete('cascade');
            $table->foreign('stock_id')->references('id')->on('inventory_stock')->nullOnDelete();
            $table->foreign('device_id')->references('id')->on('inventory_device')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            // Mismo criterio que installation_equipment: un equipo serializado
            // está cargado en un solo ticket a la vez. En Postgres los NULL no
            // chocan, así que las líneas de material (device_id NULL) conviven.
            $table->unique('device_id');
            $table->index(['tenant_id', 'ticket_id']);
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('ticket_id')->nullable()->after('installation_id');

            $table->foreign('ticket_id')->references('id')->on('support_ticket')->nullOnDelete();
            $table->index('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign(['ticket_id']);
            $table->dropIndex(['ticket_id']);
            $table->dropColumn('ticket_id');
        });

        Schema::dropIfExists('ticket_equipment');
    }
};
