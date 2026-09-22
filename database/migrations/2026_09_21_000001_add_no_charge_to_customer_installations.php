<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La visita que NO se le cobra al cliente.
 *
 *   no_charge         boolean NOT NULL default false — esta orden no factura
 *   no_charge_reason  varchar(255) NULL              — garantía, daño por rayo…
 *
 * EL CASO REAL. Al cliente se le quema el router y el técnico va y se lo
 * cambia. El equipo SALE de la bodega —eso no cambia, y sigue siendo un gasto
 * de la empresa, contabilizado al entrar el equipo al inventario— pero el
 * cliente no paga nada. Hasta ahora eso dependía de que quien llenara la
 * cartera se acordara de no escribir un valor, y de que nadie más lo escribiera
 * después: no había ni una marca que dijera «esta visita es gratis».
 *
 * POR QUÉ UN BOOLEANO Y NO UN «TIPO DE ORDEN»
 *
 * Un catálogo de tipos (instalación / mantenimiento / garantía / traslado)
 * parece más fino, pero mezcla dos preguntas que no van juntas: QUÉ se fue a
 * hacer y SI se cobra. Hay mantenimientos que sí se cobran —el cliente rompió
 * el equipo— y traslados regalados por retención. Atar el cobro al tipo
 * obligaría a desdoblar el catálogo en cuanto apareciera la primera excepción,
 * que aparece siempre. El tipo de orden, si algún día se pide, es una columna
 * aparte y no entra en conflicto con ésta.
 *
 * `default false` y NOT NULL: las órdenes existentes son todas cobrables, que
 * es lo que eran ayer. Un `null` aquí significaría «no se sabe si se cobra», y
 * eso no es un estado que el negocio admita.
 *
 * El motivo es opcional A PROPÓSITO. Exigirlo convertiría el botón en un
 * formulario, y el técnico que está en el poste con el celular en la mano
 * acabaría escribiendo «.» — un campo obligatorio que se rellena con basura
 * informa menos que uno vacío, porque además miente.
 */
return new class extends Migration
{
    private const TABLA = 'customer_installations';

    public function up(): void
    {
        Schema::table(self::TABLA, function (Blueprint $table) {
            // Sin `->after()`: PostgreSQL no reordena columnas y el constructor
            // lo ignora, así que escribirlo sólo engaña a quien lo lea.
            if (!Schema::hasColumn(self::TABLA, 'no_charge')) {
                $table->boolean('no_charge')->default(false);
            }

            if (!Schema::hasColumn(self::TABLA, 'no_charge_reason')) {
                $table->string('no_charge_reason', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table(self::TABLA, function (Blueprint $table) {
            $existentes = array_values(array_filter(
                ['no_charge', 'no_charge_reason'],
                fn (string $col) => Schema::hasColumn(self::TABLA, $col)
            ));

            if ($existentes !== []) {
                $table->dropColumn($existentes);
            }
        });
    }
};
