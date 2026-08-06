<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * De qué asignación salió este ítem de factura.
 *
 * Una sola columna nullable que evita una tabla entera de control y da tres
 * cosas a la vez: trazabilidad ("¿en qué facturas se cobró este servicio?"),
 * el reporte de ingresos por servicio, y —la más importante— la idempotencia
 * del cobro mensual.
 *
 * La idempotencia se DERIVA de estos ítems en vez de guardarse en la asignación
 * como un "último periodo cobrado". Si fuera un contador y un administrador
 * borrara la factura del mes, el contador quedaría adelantado y ese periodo no
 * se cobraría nunca. Derivándolo, borrar la factura libera el periodo solo.
 *
 * Queda nullable porque la enorme mayoría de los ítems no viene de aquí: el
 * plan mensual, la instalación, el arrastre y los cargos puntuales siguen sin
 * asignación detrás.
 *
 * NOTA sobre SQLite (los tests corren en :memory:): SQLite no admite agregar
 * una FOREIGN KEY a una tabla que ya existe, así que la restricción sólo se
 * crea en PostgreSQL. La columna sí existe en ambos, que es lo que el código y
 * los tests necesitan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_additional_service_id')->nullable();
            $table->index('customer_additional_service_id');
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('invoice_items', function (Blueprint $table) {
                // nullOnDelete y no cascade: si alguien borra la asignación, el
                // ítem se queda. Lo que se le cobró al cliente es descripción y
                // monto —texto y números en la misma fila—, así que la factura
                // histórica sigue explicándose sola sin la asignación.
                $table->foreign('customer_additional_service_id')
                    ->references('id')->on('customer_additional_services')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->dropForeign(['customer_additional_service_id']);
            });
        }

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropIndex(['customer_additional_service_id']);
            $table->dropColumn('customer_additional_service_id');
        });
    }
};
