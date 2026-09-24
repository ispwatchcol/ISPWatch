<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR F3 · Deshacer una línea del ticket deja de ser un DELETE.
 *
 * QUÉ ESTABA MAL
 *
 * La primera versión de este módulo revertía el inventario y acto seguido
 * borraba la fila de `ticket_equipment`. El kardex quedaba entero —el
 * movimiento original y su compensación— pero la hoja del ticket perdía la
 * línea, y con ella la única prueba dentro del expediente de que aquel aparato
 * llegó a moverse. Quien auditara el ticket veía una visita sin equipos y no
 * tenía forma de saber que hubo uno, y menos aún quién lo quitó ni por qué.
 *
 * Es exactamente lo que el § 77 acababa de prohibir para las intervenciones
 * —«una visita que se puede borrar no es evidencia»— y no hay razón para que
 * un aparato que cambió de manos tenga menos garantías que el relato de la
 * visita. Peor: aquí hay un bien físico de por medio.
 *
 * QUÉ SE HACE EN SU LUGAR
 *
 * La línea se marca como revertida y se queda. Cuatro columnas:
 *
 *   · `reversed_at`    — cuándo. NULL = la línea sigue vigente.
 *   · `reversed_by`    — quién. `SET NULL` al borrar el usuario, nunca cascade:
 *                        dar de baja a un empleado no puede borrar el rastro de
 *                        lo que hizo. Misma regla que H-6 aplicó a notas y
 *                        adjuntos tras el incidente P-42.
 *   · `reversed_by_name` — nombre congelado, por lo mismo: sin esto, borrar al
 *                        usuario dejaría la reversa sin autor.
 *   · `reversal_reason` — por qué. OBLIGATORIO en el request, igual que el
 *                        motivo de reapertura de una intervención: una
 *                        corrección sin motivo no se puede auditar, sólo
 *                        constatar.
 *
 * NO SE USA SoftDeletes, y es la misma decisión del § 77. Un `deleted_at` aquí
 * escondería la línea de toda consulta por omisión, que es justo lo contrario
 * de lo que se busca: la línea revertida tiene que SEGUIR VIÉNDOSE en la hoja,
 * tachada y con su motivo al lado. `reversed_at` marca sin ocultar.
 *
 * Lo que sí deja de contar es el dinero y la cantidad: los totales de la visita
 * excluyen las líneas revertidas. Se ven, pero no suman.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_equipment', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('created_by');
            $table->unsignedBigInteger('reversed_by')->nullable()->after('reversed_at');
            $table->string('reversed_by_name', 160)->nullable()->after('reversed_by');
            $table->text('reversal_reason')->nullable()->after('reversed_by_name');

            $table->foreign('reversed_by')->references('id')->on('users')->nullOnDelete();

            // La hoja del ticket pide una y otra vez «las líneas vigentes de
            // este ticket», que es el filtro más caliente de la pantalla.
            $table->index(['ticket_id', 'reversed_at'], 'ticket_equipment_ticket_reversed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_equipment', function (Blueprint $table) {
            // El índice y la foránea antes que las columnas: SQLite no elimina
            // los dependientes al dropear una columna y la reversión falla con
            // «error in index … after drop column». Ya mordió en la R1.
            $table->dropIndex('ticket_equipment_ticket_reversed_idx');
            $table->dropForeign(['reversed_by']);
            $table->dropColumn(['reversed_at', 'reversed_by', 'reversed_by_name', 'reversal_reason']);
        });
    }
};
