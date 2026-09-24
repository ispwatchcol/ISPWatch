<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR F1 · Intervenciones técnicas del ticket (F1-08).
 *
 * QUÉ EXIGE EL CLIENTE, LITERALMENTE
 * `docs/cliente/CNO/V1_1/Solicitud_Maestra_ISPwash_CNO_V1_1.docx`, § 14:
 *
 *   «Un ticket puede tener múltiples intervenciones. Cada una debe registrar
 *    fecha/hora, tipo remoto o presencial, técnico, diagnóstico encontrado,
 *    acción, materiales, equipos retirados/instalados, evidencia, resultado y
 *    siguiente paso.»
 *
 * Y la tabla de la misma sección: «Intervención | Número, tipo, técnico
 * principal, acompañante, inicio, fin, hallazgo, acción, resultado y próximo
 * paso.»
 *
 * Esta migración cubre la intervención y su evidencia. Materiales y equipos son
 * el PR F3 y NO se tocan aquí: son los únicos que pueden divergir del inventario
 * físico y dependen de una decisión pendiente (D-14).
 *
 * SIN `deleted_at`, Y NO ES UN OLVIDO
 *
 * El resto del expediente usa `SoftDeletes` porque archivar un ticket entero es
 * una operación de negocio, reversible y auditada (PR C). Una intervención es
 * otra cosa: es el registro de que alguien fue, miró y actuó. Eso no se
 * deshace. Si se anotó mal se CORRIGE, y la corrección deja rastro.
 *
 * Un `deleted_at` aquí sería una puerta trasera: bastaría con marcarla para que
 * la visita desapareciera del expediente sin que el histórico lo cuente. El
 * § 15.10 lo prohíbe de forma explícita — «El cierre no debe borrar la causa
 * sospechada, las intervenciones ni los estados anteriores».
 *
 * De ahí que no exista endpoint de borrado, ni aquí ni en el enrutado. La
 * inmutabilidad se apoya en `finished_at`:
 *
 *   · `finished_at` NULL  → en curso, editable.
 *   · `finished_at` lleno → cerrada; para corregirla hay que REABRIRLA, lo que
 *     exige motivo y deja `intervention_reopened` en el historial.
 *
 * EL «NÚMERO» DE LA INTERVENCIÓN
 *
 * `sequence` es correlativo POR TICKET, no global: el técnico habla de «la
 * segunda visita de este ticket», no de «la intervención 4 817». El único par
 * (ticket, número) se garantiza con un índice único, no sólo calculándolo en la
 * aplicación: dos técnicos guardando a la vez calcularían el mismo número y sin
 * el índice ambos lo escribirían.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_intervention', function (Blueprint $table) {
            $table->id();

            // Multi-tenencia por fila, estampada explícita como en
            // `support_ticket_history`: filtrar por tenant no debe exigir un
            // join contra el ticket.
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('support_ticket_id');

            // El «Número» del § 14, correlativo dentro del ticket.
            $table->unsignedInteger('sequence');

            // «tipo remoto o presencial». Son los dos únicos valores que nombra
            // el documento; no se inventa un tercero (decisión S-2).
            $table->string('kind', 20);

            // Técnico principal y acompañante. `SET NULL` al borrar el usuario,
            // nunca `cascade`: dar de baja a un empleado no puede borrar la
            // visita que hizo. Es la misma regla que ya aplicó H-6 a notas y
            // adjuntos tras el incidente P-42.
            $table->unsignedBigInteger('technician_id')->nullable();
            $table->unsignedBigInteger('assistant_id')->nullable();

            // Nombre congelado al registrar. Sin esto, borrar al técnico dejaría
            // la visita sin autor y el expediente diría «—» donde antes decía
            // quién fue. Patrón `author_name` de H-6.
            $table->string('technician_name', 160)->nullable();
            $table->string('assistant_name', 160)->nullable();

            // «inicio, fin». `finished_at` NULL = en curso; es además el cerrojo
            // de edición, ver la nota de cabecera.
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            // «hallazgo, acción, resultado y próximo paso» — § 14, literal.
            //
            // Texto libre y no catálogo a propósito: el ticket YA tiene los
            // catálogos del Anexo A para causa, acción y resultado a nivel de
            // expediente (PR #1 y #2). Lo de aquí es la narración de una visita
            // concreta, que el documento pide en prosa. Duplicar el catálogo por
            // intervención crearía dos verdades sobre la misma pregunta.
            $table->text('finding')->nullable();
            $table->text('action_taken')->nullable();
            $table->text('outcome')->nullable();
            $table->text('next_step')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('cascade');

            // RESTRICT y no CASCADE: el ticket no se borra —está prohibido desde
            // el PR A— y si algún día alguien lo intentara, la base debe negarse
            // en vez de llevarse el expediente por delante.
            $table->foreign('support_ticket_id')->references('id')->on('support_ticket')->onDelete('restrict');

            $table->foreign('technician_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('assistant_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            // El correlativo por ticket, garantizado por la base.
            $table->unique(['support_ticket_id', 'sequence'], 'ticket_intervention_ticket_sequence');

            // Necesario para que la evidencia pueda declarar una clave foránea
            // COMPUESTA contra (id, support_ticket_id). Ver la migración
            // siguiente: es lo que impide colgar una evidencia de la
            // intervención de otro ticket.
            $table->unique(['id', 'support_ticket_id'], 'ticket_intervention_id_ticket');

            $table->index(['tenant_id', 'support_ticket_id'], 'ticket_intervention_tenant_ticket');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_intervention');
    }
};
