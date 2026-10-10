<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR F2 · Mediciones técnicas estructuradas del ticket (F1-09).
 *
 * QUÉ EXIGE EL CLIENTE, LITERALMENTE
 * `docs/cliente/CNO/V1_1/Solicitud_Maestra_ISPwash_CNO_V1_1.docx`:
 *
 *   § 12: «Cada medición debe guardar tipo de prueba, resultado, unidad,
 *          fecha/hora, origen y fase. Los resultados no deben quedar únicamente
 *          en observaciones.»
 *
 *   § 13: «El ticket debe permitir comparar el estado técnico antes y después de
 *          la intervención.»
 *
 * Las seis columnas del § 12 están aquí una a una. La comparación del § 13 no
 * necesita esquema propio: sale de agrupar por `phase`.
 *
 * POR QUÉ `test_type` ES TEXTO LIBRE
 *
 * El § 12 enumera las mediciones en prosa y por tecnología —«RSSI; SNR; CCQ;
 * ruido; Tx/Rx…»— SIN asignarles código, exactamente como el Anexo A.2 hace con
 * las subcausas. El cliente cerró ese criterio el 11/09/2026 (**D-06**):
 * mantenerlas como referencia y no crear códigos individuales. Inventarlos aquí
 * sería fabricar contrato, y los códigos de catálogo son inmutables una vez
 * sembrados. Las listas del documento viajan como sugerencias en
 * `App\Support\TicketMeasurements`, no como valores seleccionables.
 *
 * POR QUÉ `value` ES TEXTO Y NO UN NÚMERO
 *
 * Los ejemplos del § 13 mezclan los dos tipos en la misma frase: «PPPoE
 * conectado; RSSI –76 dBm; CCQ 54 %; latencia 104 ms». Forzar `decimal`
 * obligaría a partir la medición en dos tablas o a perder «PPPoE conectado»,
 * que es una medición tan válida como las otras. El requerimiento pide
 * «resultado», no «valor numérico».
 *
 * SIN `deleted_at`, POR LO MISMO QUE LAS INTERVENCIONES
 *
 * Una medición es la constancia de lo que se leyó en un momento. Borrarla —aun
 * blandamente— dejaría el expediente diciendo que nunca se midió, y el § 15.5
 * hace del par medición/justificación un requisito de cierre: poder hacerlo
 * desaparecer vaciaría esa regla. No hay endpoint de borrado y el modelo bloquea
 * `deleting`.
 *
 * Corregir una medición mal anotada sí se puede mientras el ticket no esté
 * cerrado, y cada corrección deja evento en el historial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_measurement', function (Blueprint $table) {
            $table->id();

            // Estampado explícito, como en `support_ticket_history` y
            // `ticket_intervention`: filtrar por tenant no debe exigir un join.
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('support_ticket_id');

            // De qué visita salió la medición, si salió de una. NULL es normal y
            // válido: el diagnóstico remoto inicial se toma antes de que exista
            // ninguna intervención.
            $table->unsignedBigInteger('intervention_id')->nullable();

            // Las seis del § 12.
            $table->string('test_type', 80);
            $table->string('value', 255);
            $table->string('unit', 30)->nullable();
            $table->timestamp('measured_at');
            $table->string('source', 60);
            $table->string('phase', 20);

            // Quién la tomó, con el nombre congelado. Dar de baja al técnico no
            // puede dejar la medición sin autor — patrón `author_name` de H-6,
            // ya aplicado a notas, adjuntos e intervenciones.
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('recorded_by_name', 160)->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('cascade');

            // RESTRICT y no CASCADE: el ticket no se borra —está prohibido desde
            // el PR A— y si alguien lo intentara, la base debe negarse antes que
            // llevarse las mediciones por delante.
            $table->foreign('support_ticket_id')->references('id')->on('support_ticket')->onDelete('restrict');

            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();

            // CLAVE FORÁNEA COMPUESTA contra el único (id, support_ticket_id) de
            // `ticket_intervention`. Sin ella, una medición del ticket 10 podría
            // colgar de una intervención del ticket 77 y el aislamiento
            // dependería de que ningún `where` se olvide.
            //
            // A diferencia de la del PR F1 sobre `support_ticket_attachment`,
            // ésta SÍ funciona en los dos motores: allí había que añadirla con
            // ALTER TABLE a una tabla existente, cosa que SQLite no admite;
            // aquí la tabla nace con ella.
            $table->foreign(['intervention_id', 'support_ticket_id'], 'ticket_measurement_intervention_fk')
                ->references(['id', 'support_ticket_id'])
                ->on('ticket_intervention')
                ->onDelete('restrict');

            $table->index(['tenant_id', 'support_ticket_id'], 'ticket_measurement_tenant_ticket');

            // El índice que sostiene la regla de cierre y la comparación del
            // § 13: «¿tiene este ticket alguna medición final?» es la consulta
            // que se hace en cada propuesta y en cada cierre.
            $table->index(['support_ticket_id', 'phase'], 'ticket_measurement_ticket_phase');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_measurement');
    }
};
