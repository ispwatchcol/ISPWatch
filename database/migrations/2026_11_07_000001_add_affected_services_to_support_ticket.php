<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F1-16 de CNO · Servicios afectados y minutos-cliente (§ 16 y § 17).
 *
 * QUÉ EXIGE EL CLIENTE
 *
 *   § 16 «Servicios afectados: registrar cantidad confirmada o estimada y
 *         separar contactos recibidos de incidentes reales.»
 *   § 16 «Minutos-cliente: calcular servicios afectados × duración de la
 *         afectación.»
 *
 * DOS COLUMNAS, SÓLO PARA EL INCIDENTE MASIVO
 *
 *   · `affected_services`       — cuántos servicios dejó sin servicio la falla.
 *                                 No es lo mismo que los tickets asociados: ésos
 *                                 son los CONTACTOS recibidos. Un AP caído con
 *                                 120 clientes puede generar 15 llamadas.
 *   · `affected_services_basis` — `estimado` o `confirmado`. Al abrir el
 *                                 incidente casi siempre es una estimación
 *                                 (clientes del AP); se confirma después con la
 *                                 lectura del equipo. El tablero tiene que
 *                                 poder distinguir una cifra de la otra.
 *
 * Los minutos-cliente NO se guardan: son servicios × (`resolved_at` −
 * `created_at`) y se calculan al leer. Guardarlos obligaría a recalcularlos en
 * cada reapertura o corrección de la cifra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_ticket', function (Blueprint $table) {
            if (! Schema::hasColumn('support_ticket', 'affected_services')) {
                $table->unsignedInteger('affected_services')->nullable();
            }
            if (! Schema::hasColumn('support_ticket', 'affected_services_basis')) {
                $table->string('affected_services_basis', 20)->nullable();
            }
        });

        // SQLite no aplica CHECK añadidos con ALTER; en PostgreSQL sí. La cifra
        // y su naturaleza van juntas: una cantidad sin decir si es estimada o
        // confirmada no le sirve al tablero, y una naturaleza sin cantidad no
        // significa nada.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE support_ticket DROP CONSTRAINT IF EXISTS support_ticket_affected_services_check');
            DB::statement("ALTER TABLE support_ticket ADD CONSTRAINT support_ticket_affected_services_check CHECK (
                (affected_services IS NULL AND affected_services_basis IS NULL)
                OR (affected_services >= 0 AND affected_services_basis::text = ANY (ARRAY['estimado','confirmado']::text[]))
            )");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE support_ticket DROP CONSTRAINT IF EXISTS support_ticket_affected_services_check');
        }

        Schema::table('support_ticket', function (Blueprint $table) {
            if (Schema::hasColumn('support_ticket', 'affected_services_basis')) {
                $table->dropColumn('affected_services_basis');
            }
            if (Schema::hasColumn('support_ticket', 'affected_services')) {
                $table->dropColumn('affected_services');
            }
        });
    }
};
