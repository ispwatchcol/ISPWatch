<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log append-only de cambios comerciales, para que un integrador externo
 * sincronice sin webhooks.
 *
 * POR QUÉ UN FEED Y NO WEBHOOKS
 * ------------------------------
 * Un webhook obliga a ISPWatch a hacer peticiones HTTP SALIENTES hacia URLs que
 * controla el integrador. En una plataforma multi-inquilino eso trae cuatro
 * problemas de golpe: riesgo de SSRF, presión de cola cuando el destino está
 * caído, garantías de entrega/orden/deduplicación que pasan a ser nuestras, y
 * un secreto de firma por destino que hay que rotar.
 *
 * El peor de los cuatro es el segundo: el endpoint caído de UN integrador
 * llenaría la cola y degradaría a los demás inquilinos. Un feed que el
 * integrador consulta no tiene ninguno de esos efectos — la contrapresión queda
 * de su lado, es reproducible desde cualquier punto y no hace llamadas
 * salientes.
 *
 * Hay precedente probado en producción: router_outage_events, que Converza
 * consume por cursor de id.
 *
 * EL `id` ES EL CURSOR Y TAMBIÉN LA REVISIÓN
 * -------------------------------------------
 * No hay una columna `revision` por recurso. La revisión de un cliente o de un
 * servicio es el `id` de su último evento, o sea una secuencia global y
 * monotónica. Se eligió así por dos razones:
 *
 *   1. Un contador por fila no sirve como cursor: dos recursos distintos
 *      tendrían la misma revisión y no habría orden entre ellos.
 *   2. Dos mecanismos separados (contador + log) pueden divergir. Uno solo, no.
 *
 * Un timestamp tampoco alcanza: dos escrituras dentro del mismo segundo son
 * indistinguibles para un cursor, y ahí se pierden cambios en silencio.
 *
 * SIN LLAVES FORÁNEAS, A PROPÓSITO
 * ---------------------------------
 * customer_id y service_id NO tienen FK. Si un cliente se elimina, sus eventos
 * deben sobrevivir: el integrador necesita justamente enterarse de esa baja, y
 * un ON DELETE CASCADE le borraría la evidencia antes de que la lea.
 *
 * DUPLICADOS: SE ACEPTAN
 * ----------------------
 * El emisor puede registrar dos eventos equivalentes para un mismo cambio
 * (p. ej. el plan se toca en customer_profile y en user_services dentro de la
 * misma operación). Es deliberado: preferimos un evento de más que uno de
 * menos. El evento es delgado —avisa qué cambió, no transporta el estado— así
 * que el consumidor re-consulta y un duplicado le cuesta una petición. Perder
 * un evento, en cambio, deja al integrador desincronizado sin saberlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_events', function (Blueprint $table) {
            // El id ES el contrato: cursor del feed y revisión del recurso.
            $table->id();

            $table->unsignedBigInteger('tenant_id');

            $table->string('event_type', 40);

            // Siempre presente: todo evento se puede correlacionar con un
            // titular, aunque el sujeto sea el servicio.
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('service_id')->nullable();

            // Qué cambió, sin transportar el estado completo. El consumidor
            // re-consulta el recurso; esto es solo para diagnóstico y para que
            // pueda decidir si le interesa.
            $table->json('changes')->nullable();

            $table->timestamp('occurred_at');

            // La consulta del feed: "dame lo del tenant X después del id N".
            $table->index(['tenant_id', 'id']);
            // Revisión de un recurso: MAX(id) por cliente, resuelto por índice
            // en vez de agregando la tabla entera.
            $table->index(['tenant_id', 'customer_id', 'id']);
            // Poda por antigüedad.
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_events');
    }
};
