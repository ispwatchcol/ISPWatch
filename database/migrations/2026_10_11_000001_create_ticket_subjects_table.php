<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asuntos frecuentes del ticket: el catálogo con el que se radica.
 *
 *   ticket_subjects             — lista PROPIA de cada ISP («Sin internet»,
 *                                 «Internet lento», «Traslado»…)
 *   support_ticket.subject_id   — qué asunto del catálogo se eligió, o NULL si
 *                                 se escribió a mano («Otro…») o es un ticket
 *                                 anterior a este cambio
 *
 * EL PROBLEMA. El asunto era texto libre, así que «SIN INTERNET», «sin
 * internet», «no tiene internet» y «Cliente sin servicio» eran cuatro asuntos
 * distintos. Filtrar el listado por asunto era imposible y contar cuántos
 * tickets de cada tipo entran en el mes, también.
 *
 * POR QUÉ UNA TABLA DEL TENANT Y NO OTRA FILA EN `ticket_category`
 *
 * La categoría es de plataforma (Técnica / Facturación / Servicios / General),
 * la consume la API de socios y no la edita nadie. El asunto es vocabulario
 * operativo de cada ISP: lo que uno llama «Caída de nodo» otro lo llama
 * «Masivo». Mezclarlos ataría el contrato de integración al gusto de cada
 * cliente.
 *
 * POR QUÉ SE CONSERVA `support_ticket.subject` (el texto)
 *
 *  · Es lo que lee la API de socios y lo que buscan las pantallas.
 *  · Los tickets viejos no tienen `subject_id` y no se van a inventar uno.
 *  · «Otro…» sigue existiendo: el asunto raro de una vez no justifica una fila
 *    en el catálogo. Esos tickets se agrupan como «Otro» al filtrar.
 *
 * Al elegir del catálogo, el texto se COPIA del nombre del asunto. Si después
 * se renombra el asunto, los tickets viejos conservan el texto que tenían ese
 * día (el filtro va por id, así que siguen agrupados).
 *
 * SET NULL al borrar el asunto, aunque el controlador sólo deja borrar los que
 * no usa ningún ticket: la red de seguridad no debe poder dejar un ticket sin
 * su texto, y no lo hace — el texto vive en el propio ticket.
 *
 * Sin `->after()`: PostgreSQL no reordena columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ticket_subjects')) {
            Schema::create('ticket_subjects', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('name', 120);
                $table->unsignedInteger('sort_order')->default(0);
                // Retirar en vez de borrar: un asunto que ya usaron tickets no
                // se puede borrar, pero sí dejar de ofrecer.
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('cascade');
                $table->unique(['tenant_id', 'name']);
                $table->index(['tenant_id', 'is_active', 'sort_order']);
            });
        }

        if (!Schema::hasColumn('support_ticket', 'subject_id')) {
            Schema::table('support_ticket', function (Blueprint $table) {
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->foreign('subject_id')->references('id')->on('ticket_subjects')->nullOnDelete();
                $table->index('subject_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('support_ticket', 'subject_id')) {
            Schema::table('support_ticket', function (Blueprint $table) {
                $table->dropForeign(['subject_id']);
                $table->dropIndex(['subject_id']);
                $table->dropColumn('subject_id');
            });
        }

        Schema::dropIfExists('ticket_subjects');
    }
};
