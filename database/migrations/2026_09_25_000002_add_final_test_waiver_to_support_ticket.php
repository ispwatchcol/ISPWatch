<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR F2 · La justificación de por qué no hubo medición final (§ 13 y § 15.5).
 *
 * QUÉ EXIGE EL CLIENTE
 *
 *   § 15, regla 5: «Exigir prueba final o justificación de por qué no fue
 *                   posible.»
 *
 *   § 13: «Cuando no sea posible obtener la medición final, el usuario deberá
 *          seleccionar una razón y escribir la justificación.»
 *
 * Nótese la conjunción: razón **y** justificación. Son dos campos, no uno. La
 * razón es de lista cerrada —«seleccionar»— y la justificación es texto libre
 * obligatorio, porque una razón sin explicación no dice por qué no se pudo medir
 * ESTE ticket.
 *
 * POR QUÉ VIVEN EN `support_ticket` Y NO EN `ticket_measurement`
 *
 * Porque describen la AUSENCIA de una medición. Una fila en la tabla de
 * mediciones que dijera «aquí no hay medición» sería una contradicción, y
 * además rompería la consulta que sostiene la regla de cierre: «¿existe alguna
 * fila con `phase = final`?» pasaría a tener que distinguir entre filas reales y
 * filas-marcador.
 *
 * Son, además, un atributo del expediente: el ticket se cerró sin medir y
 * consta por qué. Lo mismo que `no_charge_reason`, que vive aquí por la misma
 * razón.
 *
 * NULLABLE LAS DOS
 *
 * Lo normal es cerrar CON medición final, y entonces las dos quedan vacías. Sólo
 * se llenan en el caso excepcional. La regla que las exige vive en el
 * controlador de cierre, no en un `NOT NULL` que rompería todos los tickets ya
 * cerrados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_ticket', function (Blueprint $table) {
            // Código de la lista cerrada de `TicketMeasurements`. Se guarda el
            // CÓDIGO y no la etiqueta, por lo mismo que el resto del módulo: la
            // etiqueta puede reescribirse, el código es el dato.
            $table->string('final_test_waiver_reason', 40)->nullable()->after('no_charge_reason');

            // La justificación en texto. Obligatoria junto a la razón.
            $table->text('final_test_waiver_note')->nullable()->after('final_test_waiver_reason');
        });
    }

    public function down(): void
    {
        Schema::table('support_ticket', function (Blueprint $table) {
            $table->dropColumn(['final_test_waiver_reason', 'final_test_waiver_note']);
        });
    }
};
