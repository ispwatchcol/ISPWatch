<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El kardex ya sabía decir «salió por la instalación #40». Ahora también tiene
 * que saber decir «salió por el ticket #312».
 *
 * Sin esta columna un equipo entregado en una visita de soporte aparecería en
 * el histórico como una salida hacia el cliente sin causa, y la pregunta que
 * este módulo vino a responder —«¿por qué este router dejó la bodega?»— se
 * quedaría a medias justo en el caso más frecuente.
 *
 * nullOnDelete igual que `installation_id`: si algún día se borra el ticket de
 * verdad, la línea del kardex sigue siendo cierta y no se va con él.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('support_ticket_id')->nullable()->after('installation_id');
            $table->foreign('support_ticket_id')->references('id')->on('support_ticket')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign(['support_ticket_id']);
            $table->dropColumn('support_ticket_id');
        });
    }
};
