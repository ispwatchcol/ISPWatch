<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas registradas DESPUÉS de la firma de la orden.
 *
 * Una orden firmada bloqueaba sus líneas para siempre. En campo pasa lo
 * contrario de lo que el bloqueo suponía: el técnico hace firmar al cliente y
 * no alcanzó a cargar los amarres o el router (no tenía el saldo a su nombre,
 * se le cayó la señal, se le olvidó). El equipo ya está en la casa del cliente
 * y el inventario sigue diciendo que está en la bodega.
 *
 * Quien administra inventario puede ahora registrar lo que faltó, con un
 * motivo obligatorio. La línea se marca para que nadie la confunda con lo que
 * el cliente firmó: el acta firmada no cambia y no la incluye.
 *
 *   after_signature → la línea se registró con la orden ya firmada.
 *   late_reason     → por qué (lo escribe quien la registra; queda también en el kardex).
 *
 * Default false: todas las líneas existentes se registraron antes de firmar,
 * porque hasta hoy no había otra forma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installation_equipment', function (Blueprint $table) {
            $table->boolean('after_signature')->default(false);
            $table->string('late_reason', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('installation_equipment', function (Blueprint $table) {
            $table->dropColumn(['after_signature', 'late_reason']);
        });
    }
};
