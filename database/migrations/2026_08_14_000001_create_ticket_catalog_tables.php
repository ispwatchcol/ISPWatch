<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 1 · R1 (expandir) — catálogos versionados del ticket de soporte.
 *
 * Sustituye a medio plazo los tres enums de `support_ticket` (`status`,
 * `priority`, `category`) y agrega el vocabulario de diagnóstico que hoy no
 * existe en ninguna forma: síntoma, causa, solución y resultado.
 *
 * DOS REGLAS QUE SOSTIENEN TODO EL DISEÑO
 *
 * 1. `code` es INMUTABLE. Nunca se edita, nunca se reutiliza. Renombrar
 *    "high" a "alta_prioridad" no es un UPDATE: es una fila nueva más el
 *    retiro suave de la vieja. Los tickets históricos siguen apuntando por
 *    clave foránea a la fila original, que jamás se borra, así que siguen
 *    resolviendo y siguen diciendo "high" en la API pública.
 *
 * 2. `label` SÍ cambia y aplica retroactivamente. Corregir un texto visible
 *    debe verse también en los tickets viejos. Ahí está la línea: si cambió
 *    lo que la fila SIGNIFICA, es fila nueva; si cambió cómo se ESCRIBE, es
 *    el mismo código con `label` nuevo y `revision` + 1.
 *
 * De ahí que no exista `is_active`: sería una segunda fuente de verdad
 * compitiendo con `valid_until`. Vigente se define una sola vez —
 *
 *     valid_from <= now() AND (valid_until IS NULL OR valid_until > now())
 *
 * y el borrado queda prohibido por diseño: las claves foráneas del ticket se
 * declaran ON DELETE RESTRICT para que sea la base de datos, y no la
 * disciplina de quien esté de turno, la que impida perder el histórico.
 *
 * ALCANCE POR CATÁLOGO (aprobado en el diseño de la Fase 1)
 *
 *   Globales estrictos (sin `tenant_id`):
 *     · ticket_status    — la máquina de estados de la Fase 2 se define sobre él
 *     · ticket_priority  — va atada a SLA; por tenant haría incomparables los tiempos
 *     · ticket_category  — es el filtro de alcance del contrato con el integrador
 *     · ticket_result    — desenlace del ticket, métrica comparable entre ISPs
 *
 *   Base global + extensión por tenant (`tenant_id` nullable):
 *     · ticket_symptom, ticket_cause, ticket_solution
 *       NULL = fila de plataforma, visible para todos y con código estable
 *       en toda la plataforma. Un valor = vocabulario propio de ese ISP.
 *
 * `ticket_cause` sirve a la vez a `suspected_cause_id` y `confirmed_cause_id`:
 * el vocabulario diagnóstico es el mismo, lo que cambia es quién lo afirma.
 * Compartir catálogo es lo que permite medir si el diagnóstico automático
 * acertó (`suspected IS DISTINCT FROM confirmed`), que es la razón de ser de
 * la integración; con dos catálogos haría falta una tabla de equivalencias
 * mantenida a mano.
 */
return new class extends Migration
{
    /** Catálogos que admiten filas propias de cada ISP. */
    private const EXTENSIBLES = ['ticket_symptom', 'ticket_cause', 'ticket_solution'];

    /** Catálogos cuyo vocabulario es de plataforma y no se puede extender. */
    private const GLOBALES = ['ticket_status', 'ticket_priority', 'ticket_category', 'ticket_result'];

    public function up(): void
    {
        foreach (self::GLOBALES as $tabla) {
            Schema::create($tabla, function (Blueprint $table) {
                $this->columnasComunes($table);
                // Global estricto: el código identifica la fila sin más matices.
                $table->unique('code');
            });
        }

        foreach (self::EXTENSIBLES as $tabla) {
            Schema::create($tabla, function (Blueprint $table) {
                $table->id();
                // NULL = fila de plataforma. Se pone primero para que los
                // índices parciales de más abajo la puedan discriminar.
                $table->unsignedBigInteger('tenant_id')->nullable();
                $this->columnasComunes($table, conId: false);

                $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('cascade');
                $table->index('tenant_id');
            });
        }

        $this->columnasPropias();
        $this->indicesParciales();

        Schema::create('ticket_catalog_version', function (Blueprint $table) {
            // Una fila por catálogo. Sube en cada alta, retiro o reetiquetado,
            // y es lo que un integrador consulta para saber si su copia
            // cacheada sigue vigente sin descargar el catálogo entero.
            $table->string('catalog', 40)->primary();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    /**
     * Núcleo común a los siete catálogos.
     *
     * `code` a 40 y `label` a 120 no son cifras al azar: el código es un
     * identificador snake_case que viaja en el contrato de la API y conviene
     * que sea corto; la etiqueta es texto visible en un desplegable.
     */
    private function columnasComunes(Blueprint $table, bool $conId = true): void
    {
        if ($conId) {
            $table->id();
        }

        $table->string('code', 40);
        $table->string('label', 120);
        $table->text('description')->nullable();
        $table->smallInteger('weight')->default(0);
        $table->timestamp('valid_from')->useCurrent();
        // NULL = vigente. Retirar una fila es ponerle fecha, nunca borrarla.
        $table->timestamp('valid_until')->nullable();
        // Sube al editar `label` o `description`. Permite a un integrador
        // detectar un reetiquetado sin comparar cadenas.
        $table->unsignedInteger('revision')->default(1);
        $table->timestamps();
    }

    /** Columnas que sólo tienen sentido en un catálogo concreto. */
    private function columnasPropias(): void
    {
        Schema::table('ticket_status', function (Blueprint $table) {
            // Estado con el que nace un ticket. Exactamente uno en true; lo
            // garantiza un índice parcial más abajo.
            $table->boolean('is_initial')->default(false);
            // `resolved` y `closed` son AMBOS terminales (decisión de Fase 1):
            // por eso reabrir es una transición explícita y no un efecto
            // colateral, y por eso se modela en la Fase 2 y no aquí.
            $table->boolean('is_terminal')->default(false);
            // Sellado declarativo en vez de un `if` a mano en el controlador.
            $table->boolean('stamps_resolved_at')->default(false);
            $table->boolean('stamps_closed_at')->default(false);
        });

        Schema::table('ticket_priority', function (Blueprint $table) {
            // Inertes hasta la fase de SLA. Se declaran ya porque el cliente
            // los pide de forma explícita en el checklist (E15) y publicarlos
            // vacíos en el contrato es más honesto que omitirlos.
            $table->unsignedInteger('sla_response_hours')->nullable();
            $table->unsignedInteger('sla_resolution_hours')->nullable();
        });

        Schema::table('ticket_category', function (Blueprint $table) {
            // Traduce a dato el alcance acordado con el integrador (soporte
            // técnico de servicios existentes) en vez de dejarlo en una
            // convención verbal que nadie puede consultar.
            $table->boolean('is_integration_visible')->default(false);
        });

        Schema::table('ticket_symptom', function (Blueprint $table) {
            // Los síntomas de `technical` no son los de `billing`.
            $table->unsignedBigInteger('category_id')->nullable();
            $table->foreign('category_id')->references('id')->on('ticket_category')->onDelete('restrict');
        });

        Schema::table('ticket_cause', function (Blueprint $table) {
            // Agrupador para reportes: red, abonado, energia, externo…
            $table->string('group_code', 40)->nullable();
        });
    }

    /**
     * Índices que el constructor de esquemas de Laravel no sabe expresar.
     *
     * El detalle que obliga a bajar a SQL: en PostgreSQL un UNIQUE(tenant_id,
     * code) NO impide duplicados entre filas globales, porque NULL nunca es
     * igual a NULL. Sin esto se podrían crear dos filas de plataforma con el
     * mismo código y la unicidad sería decorativa. Se resuelve con dos índices
     * parciales disjuntos, que ambos motores soportan.
     *
     * Lo que NO se puede expresar en un índice: impedir que un tenant use un
     * código que ya existe como global. Esa validación queda en la aplicación
     * y está anotada como deuda consciente.
     */
    private function indicesParciales(): void
    {
        foreach (self::EXTENSIBLES as $tabla) {
            DB::statement("CREATE UNIQUE INDEX {$tabla}_code_global ON {$tabla} (code) WHERE tenant_id IS NULL");
            DB::statement("CREATE UNIQUE INDEX {$tabla}_code_tenant ON {$tabla} (tenant_id, code) WHERE tenant_id IS NOT NULL");
        }

        // Un solo estado inicial. El predicado difiere por motor: PostgreSQL
        // exige una expresión booleana y SQLite guarda los booleanos como 0/1.
        $verdadero = DB::getDriverName() === 'pgsql' ? 'true' : '1';
        DB::statement("CREATE UNIQUE INDEX ticket_status_unico_inicial ON ticket_status (is_initial) WHERE is_initial = {$verdadero}");
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_catalog_version');

        // `ticket_symptom` antes que `ticket_category`: la referencia.
        foreach (['ticket_symptom', 'ticket_cause', 'ticket_solution'] as $tabla) {
            Schema::dropIfExists($tabla);
        }

        foreach (self::GLOBALES as $tabla) {
            Schema::dropIfExists($tabla);
        }
    }
};
