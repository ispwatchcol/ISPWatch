<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un modelo del catálogo pasa a declarar CÓMO se cuenta.
 *
 * Hasta ahora todo el inventario era "una fila por aparato con serial", que es
 * correcto para una LDF o un router y absurdo para un RJ45: nadie va a registrar
 * 500 filas de conector. Sin esta distinción los consumibles simplemente no
 * existían en el sistema y se escribían a mano en el campo de texto "Materiales
 * utilizados", donde no se pueden descontar ni auditar.
 *
 *  - is_serialized = true  → cada unidad es una fila de inventory_device con su
 *    serial/MAC, y se mueve individualmente.
 *  - is_serialized = false → no hay filas por unidad; se lleva un SALDO por
 *    custodio en inventory_balances (ver la migración de esa tabla).
 *
 * El default es true porque es lo que ya había: todo lo existente son equipos
 * serializados y debe seguir comportándose igual.
 *
 * unit es sólo etiqueta ("unidad", "metro", "rollo") para que la pantalla diga
 * "30 metros" y no "30 unidades"; no participa en ningún cálculo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_stock', function (Blueprint $table) {
            $table->boolean('is_serialized')->default(true);
            $table->string('unit', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_stock', function (Blueprint $table) {
            $table->dropColumn(['is_serialized', 'unit']);
        });
    }
};
