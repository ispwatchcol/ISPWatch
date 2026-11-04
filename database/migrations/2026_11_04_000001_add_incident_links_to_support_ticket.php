<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PR #6 de CNO · Incidentes masivos, duplicados y reincidencias (§ 6 y § 16).
 *
 * QUÉ EXIGE EL CLIENTE
 *
 *   § 19.7 «El sistema detecta tickets abiertos del mismo servicio y permite
 *           vincular duplicados.»
 *   § 19.8 «Se pueden crear incidentes padres y relacionar tickets
 *           individuales.»
 *   § 6    «el operador solo debería elegir entre "Individual" y "Masivo"; los
 *           atributos "asociado", "reabierto" y "sin falla confirmada" deberían
 *           calcularse o asignarse durante el ciclo del ticket.»
 *
 * TRES COLUMNAS
 *
 *   · `modality`          — lo único que el operador ELIGE (§ 6): individual o
 *                           masivo. Un masivo es el incidente padre.
 *   · `parent_ticket_id`  — el incidente masivo al que se asoció este ticket.
 *                           Se llena al pasar a `asociado_incidente_masivo`.
 *   · `duplicate_of_id`   — el ticket original del que éste es duplicado. Se
 *                           llena al pasar a `duplicado`.
 *
 * Antes de esto los estados `duplicado` y `asociado_incidente_masivo` existían
 * (PR #4) pero no apuntaban a nada: se sabía que el ticket era un duplicado,
 * no DE CUÁL.
 *
 * Las dos claves foráneas son ON DELETE SET NULL: un ticket no se borra (se
 * archiva, PR C), pero si algún día se borra uno, sus hijos no deben
 * arrastrarse con él.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_ticket', function (Blueprint $table) {
            if (! Schema::hasColumn('support_ticket', 'modality')) {
                $table->string('modality', 20)->default('individual');
            }
            if (! Schema::hasColumn('support_ticket', 'parent_ticket_id')) {
                $table->unsignedBigInteger('parent_ticket_id')->nullable();
                $table->foreign('parent_ticket_id')->references('id')->on('support_ticket')->nullOnDelete();
                $table->index('parent_ticket_id');
            }
            if (! Schema::hasColumn('support_ticket', 'duplicate_of_id')) {
                $table->unsignedBigInteger('duplicate_of_id')->nullable();
                $table->foreign('duplicate_of_id')->references('id')->on('support_ticket')->nullOnDelete();
                $table->index('duplicate_of_id');
            }
        });

        // SQLite no aplica CHECK añadidos con ALTER; en PostgreSQL sí, y es
        // donde importa: un valor fuera de la lista contaría mal los incidentes.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE support_ticket DROP CONSTRAINT IF EXISTS support_ticket_modality_check');
            DB::statement("ALTER TABLE support_ticket ADD CONSTRAINT support_ticket_modality_check CHECK (modality::text = ANY (ARRAY['individual','masivo']::text[]))");

            // Un ticket no puede ser su propio padre ni su propio original.
            DB::statement('ALTER TABLE support_ticket DROP CONSTRAINT IF EXISTS support_ticket_links_not_self_check');
            DB::statement('ALTER TABLE support_ticket ADD CONSTRAINT support_ticket_links_not_self_check CHECK ((parent_ticket_id IS NULL OR parent_ticket_id <> id) AND (duplicate_of_id IS NULL OR duplicate_of_id <> id))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE support_ticket DROP CONSTRAINT IF EXISTS support_ticket_modality_check');
            DB::statement('ALTER TABLE support_ticket DROP CONSTRAINT IF EXISTS support_ticket_links_not_self_check');
        }

        Schema::table('support_ticket', function (Blueprint $table) {
            if (Schema::hasColumn('support_ticket', 'duplicate_of_id')) {
                $table->dropForeign(['duplicate_of_id']);
                $table->dropIndex(['duplicate_of_id']);
                $table->dropColumn('duplicate_of_id');
            }
            if (Schema::hasColumn('support_ticket', 'parent_ticket_id')) {
                $table->dropForeign(['parent_ticket_id']);
                $table->dropIndex(['parent_ticket_id']);
                $table->dropColumn('parent_ticket_id');
            }
            if (Schema::hasColumn('support_ticket', 'modality')) {
                $table->dropColumn('modality');
            }
        });
    }
};
