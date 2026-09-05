<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR #3 · Historial inalterable del ticket (F1-17).
 *
 * QUÉ EXIGE EL CLIENTE, LITERALMENTE
 * `docs/cliente/CNO/V1_1/Solicitud_Maestra_ISPwash_CNO_V1_1.docx`, sección 18:
 *
 *   «Cada cambio debe conservar fecha/hora, usuario o aplicación, estado
 *    anterior/nuevo, campo modificado y valores anteriores/nuevos. La auditoría
 *    no debe ser editable desde la operación ordinaria.»
 *
 * Y en los principios de la Solicitud 1: «Historial inalterable — Los cambios de
 * estado, asignaciones, diagnósticos y cierres deben conservar auditoría».
 *
 * Eso es una fila POR CAMBIO DE CAMPO, no un volcado del ticket. De ahí la
 * forma: `field` + `old_value` + `new_value`, que es exactamente el vocabulario
 * del requerimiento.
 *
 * POR QUÉ UNA TABLA NUEVA Y NO `audit_logs`
 *
 * `audit_logs` existe y funciona, pero resuelve otro problema:
 *
 *   · Guarda `old_values`/`new_values` como JSON del modelo entero, no una fila
 *     por campo. Preguntar «¿quién cambió la causa confirmada y cuándo?» obliga
 *     a recorrer JSON en la aplicación, y en PostgreSQL a operadores JSON que
 *     SQLite no tiene — la suite corre en los dos.
 *   · Está detrás del permiso `view_audit_log`, pensado para administración. El
 *     historial del ticket lo tiene que ver quien atiende el ticket, que sólo
 *     tiene `view_support`. Aflojar `audit_logs` para esto abriría de paso la
 *     bitácora de facturación y de clientes.
 *   · Su clave es `model_type` + `model_id`, sin `support_ticket_id` propio: no
 *     hay clave foránea que garantice que el evento apunta a un ticket real.
 *
 * Se sigue en cambio el patrón de `sectorial_history`, que ya resolvió esto
 * mismo para infraestructura y que el módulo de tickets ya usa al vincular un
 * sectorial.
 *
 * INALTERABILIDAD
 *
 * Se aplica en el MODELO (`SupportTicketHistory` bloquea `updating` y
 * `deleting`) y en el enrutado (no existe endpoint de edición ni de borrado).
 * No se pone un trigger de PostgreSQL a propósito: la suite corre también sobre
 * SQLite, y una garantía que sólo existe en un motor da una falsa sensación de
 * cobertura — es el mismo razonamiento que llevó a sincronizar catálogos en el
 * modelo y no con un trigger en la R2.
 *
 * `updated_at` existe por convención de la tabla, pero por construcción nunca
 * difiere de `created_at`: si difirieran, alguien habría editado el histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_history', function (Blueprint $table) {
            $table->id();

            // Multi-tenencia por fila, como el resto del sistema. Se estampa
            // explícito en vez de deducirlo del ticket al leer: el filtrado por
            // tenant tiene que poder hacerse sin join.
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('support_ticket_id');

            // NULL = lo hizo el sistema, no una persona. No se pone un usuario
            // de relleno: «no hubo actor humano» es un dato, no un hueco.
            //
            // `set null` al borrar el usuario, nunca `cascade`: dar de baja a un
            // empleado no puede borrar la auditoría de lo que hizo.
            $table->unsignedBigInteger('actor_user_id')->nullable();

            $table->string('event_type', 40);

            // Campo afectado, cuando el evento es un cambio de campo. NULL en
            // eventos que no lo son (alta del ticket, nota, adjunto).
            $table->string('field', 60)->nullable();

            // CÓDIGO estable, no id interno: `open`, `S02`, `RF`, `AC07`. Un id
            // no significa nada fuera de esta instalación y dejaría el histórico
            // ilegible si el catálogo se resembrara. Para `staff` va el id del
            // usuario, que sí es la identidad; el nombre viaja en `metadata`.
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();

            // Contexto no estructurado: etiquetas del momento, nombre del
            // archivo, id de la nota. NUNCA rutas de almacenamiento, tokens,
            // credenciales ni datos personales que no estén ya en el ticket.
            $table->json('metadata')->nullable();

            // `web`, `api`, `import`… o `system` cuando no hubo actor humano.
            $table->string('source', 20)->default('web');

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('cascade');
            $table->foreign('support_ticket_id')->references('id')->on('support_ticket')->onDelete('cascade');
            $table->foreign('actor_user_id')->references('id')->on('users')->onDelete('set null');

            // La consulta real es siempre «el historial de ESTE ticket, lo más
            // reciente primero». Un índice sólo por `support_ticket_id` obliga a
            // ordenar después; compuesto con `id` sale ya ordenado, y `id` es
            // mejor desempate que `created_at` porque dos eventos del mismo
            // guardado comparten timestamp al milisegundo.
            $table->index(['support_ticket_id', 'id']);
            $table->index('tenant_id');
            $table->index('event_type');
        });
    }

    /**
     * Se puede revertir sin perder nada irrecuperable: la tabla es nueva y
     * ningún dato del ticket depende de ella. Lo que se pierde es la traza
     * acumulada desde el despliegue, que es justamente lo que esta tabla viene
     * a crear — no existía antes.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_ticket_history');
    }
};
