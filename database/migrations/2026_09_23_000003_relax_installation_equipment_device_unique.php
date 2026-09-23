<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `installation_equipment.device_id` era ÚNICO, y eso deja de ser cierto en el
 * momento en que un equipo puede volver del cliente.
 *
 * La restricción original decía «un equipo físico no puede estar instalado en
 * dos casas a la vez» y la implementaba como «un equipo no puede aparecer en
 * dos hojas nunca». Mientras la única forma de devolver algo fue borrar la
 * línea de la hoja, las dos frases coincidían. Con el retiro desde un ticket ya
 * no: el equipo se retira de casa del cliente —y la hoja de la instalación
 * vieja se queda como historia, que es lo correcto— y al reinstalarlo en otro
 * cliente el INSERT chocaba contra el unique. El equipo quedaba inservible para
 * el resto de su vida útil sin que nadie entendiera por qué.
 *
 * El invariante de verdad no se pierde: vive en `inventory_device.status` +
 * `customer_id`, que es UNA fila por aparato, y el ledger se niega a entregar un
 * equipo que ya esté `installed`. Aquí queda un índice normal, que es lo que
 * esta columna necesitaba desde el principio: buscar rápido, no prohibir.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tolerante a propósito: el unique se llama igual en todos los motores,
        // pero una base restaurada a mano puede no tenerlo y eso no es motivo
        // para dejar la migración a medias.
        try {
            Schema::table('installation_equipment', function (Blueprint $table) {
                $table->dropUnique(['device_id']);
            });
        } catch (\Throwable $e) {
            // Ya no estaba: no hay nada que relajar.
        }

        try {
            Schema::table('installation_equipment', function (Blueprint $table) {
                $table->index('device_id');
            });
        } catch (\Throwable $e) {
            // El índice ya existe con otro nombre.
        }
    }

    public function down(): void
    {
        try {
            Schema::table('installation_equipment', function (Blueprint $table) {
                $table->dropIndex(['device_id']);
            });
        } catch (\Throwable $e) {
            //
        }

        // Sólo se puede reponer el unique si los datos siguen cumpliéndolo. Si
        // ya hay un equipo reinstalado, revertir rompería la tabla: se deja el
        // índice normal y se avisa en el log en vez de fallar el rollback.
        try {
            Schema::table('installation_equipment', function (Blueprint $table) {
                $table->unique('device_id');
            });
        } catch (\Throwable $e) {
            \Log::warning('No se pudo reponer el unique de installation_equipment.device_id: ' . $e->getMessage());
        }
    }
};
