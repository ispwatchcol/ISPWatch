<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PR A · La base de datos impide borrar un ticket que tenga auditoría.
 *
 * QUÉ CAMBIA Y POR QUÉ
 *
 * `support_ticket_history.support_ticket_id` se creó en el PR #3 con
 * `ON DELETE CASCADE`. Con el endpoint `DELETE /api/support/{id}` vivo —y tras
 * un permiso de LECTURA— eso significaba que cualquiera que pudiera ver un
 * ticket podía borrar de un clic el ticket **y su auditoría entera**. La tabla
 * existe precisamente para que eso no pueda pasar.
 *
 * Pasa a `ON DELETE RESTRICT`: el motor rechaza borrar un ticket mientras tenga
 * un solo evento de historial.
 *
 * POR QUÉ TAMBIÉN ESTO, SI YA SE BLOQUEÓ EN LA APLICACIÓN
 *
 * El controlador ya devuelve 403 y el modelo lanza en `deleting`. Pero ninguna
 * de las dos cubre `SupportTicket::where(...)->delete()`, que no pasa por
 * Eloquent, ni un `DELETE` escrito a mano en una consola de base de datos.
 *
 * Es el mismo criterio que la R1 dejó escrito para los catálogos del ticket:
 * «el borrado queda prohibido por diseño: las claves foráneas se declaran
 * ON DELETE RESTRICT para que sea la base de datos, y no la disciplina de quien
 * esté de turno, la que impida perder el histórico».
 *
 * CONSECUENCIA QUE SE ACEPTA A CONCIENCIA
 *
 * Un borrado legítimo —por ejemplo una supresión de datos personales exigida por
 * ley— dejará de ser posible con un `DELETE` a secas: habrá que borrar primero
 * el historial, de forma explícita y deliberada. Eso es la finalidad, no un
 * efecto colateral.
 *
 * LO QUE ESTA MIGRACIÓN NO TOCA
 *
 * `support_ticket_history.tenant_id` sigue en `CASCADE`: dar de baja a un ISP se
 * lleva su auditoría. Probablemente sea lo correcto para una baja de cliente,
 * pero es una decisión distinta y se documenta sin cambiarla
 * (`docs/cliente/CNO/DISENO_PERMISOS_Y_ARCHIVADO.md`).
 *
 * NINGÚN DATO SE TOCA. Sólo cambia la regla de integridad.
 */
return new class extends Migration
{
    private const TABLA      = 'support_ticket_history';
    private const CONSTRAINT = 'support_ticket_history_support_ticket_id_foreign';

    public function up(): void
    {
        $this->cambiarRegla('restrict');
    }

    /**
     * Vuelve a `CASCADE`, que es como lo dejó el PR #3.
     *
     * Revertir NO pierde datos, pero sí devuelve el sistema al estado en que
     * borrar un ticket se llevaba su auditoría por delante. Se implementa porque
     * una migración debe ser reversible, no porque revertirla sea buena idea.
     */
    public function down(): void
    {
        $this->cambiarRegla('cascade');
    }

    /**
     * PostgreSQL sabe redefinir una constraint; SQLite no sabe ni soltarla.
     *
     * En SQLite las claves foráneas viven dentro del `CREATE TABLE`, así que
     * cambiarlas obliga a reconstruir la tabla entera. Es el procedimiento que
     * la propia documentación de SQLite recomienda, y hay que hacerlo con las
     * foráneas DESACTIVADAS o el `DROP` de la tabla vieja fallaría por las
     * filas que apuntan a ella.
     */
    private function cambiarRegla(string $accion): void
    {
        if (!Schema::hasTable(self::TABLA)) {
            // Sólo puede ocurrir si alguien revierte el PR #3 sin revertir ésta.
            // Abortar sería peor: dejaría el despliegue muerto por una tabla que
            // ya no existe.
            return;
        }

        match (DB::getDriverName()) {
            'pgsql'  => $this->enPostgres($accion),
            'sqlite' => $this->enSqlite($accion),
            default  => throw new RuntimeException(
                'Motor no contemplado para el cambio de clave foránea: ' . DB::getDriverName()
            ),
        };
    }

    private function enPostgres(string $accion): void
    {
        $tabla  = self::TABLA;
        $nombre = self::CONSTRAINT;
        $regla  = strtoupper($accion);

        // `IF EXISTS` para que la migración sea idempotente aunque la constraint
        // se hubiera creado con otro nombre o ya se hubiera soltado a mano.
        DB::statement("ALTER TABLE {$tabla} DROP CONSTRAINT IF EXISTS {$nombre}");

        DB::statement(
            "ALTER TABLE {$tabla} ADD CONSTRAINT {$nombre} "
            . "FOREIGN KEY (support_ticket_id) REFERENCES support_ticket(id) ON DELETE {$regla}"
        );
    }

    private function enSqlite(string $accion): void
    {
        $tabla = self::TABLA;

        // Sin esto, el `DROP TABLE` de la tabla original falla: las filas que se
        // acaban de copiar apuntan a ella. Se restauran al terminar, pase lo que
        // pase, para no dejar la conexión sin integridad referencial.
        DB::statement('PRAGMA foreign_keys = OFF');

        try {
            DB::transaction(function () use ($tabla, $accion) {
                Schema::create("{$tabla}_nueva", function (Blueprint $table) use ($accion) {
                    $table->id();
                    $table->unsignedBigInteger('tenant_id')->nullable();
                    $table->unsignedBigInteger('support_ticket_id');
                    $table->unsignedBigInteger('actor_user_id')->nullable();
                    $table->string('event_type', 40);
                    $table->string('field', 60)->nullable();
                    $table->text('old_value')->nullable();
                    $table->text('new_value')->nullable();
                    $table->json('metadata')->nullable();
                    $table->string('source', 20)->default('web');
                    $table->timestamps();

                    $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('cascade');
                    $table->foreign('support_ticket_id')->references('id')->on('support_ticket')->onDelete($accion);
                    $table->foreign('actor_user_id')->references('id')->on('users')->onDelete('set null');
                });

                // Copia explícita columna a columna: un `SELECT *` se rompería en
                // silencio si el orden de las columnas difiriera.
                DB::statement(
                    "INSERT INTO {$tabla}_nueva
                        (id, tenant_id, support_ticket_id, actor_user_id, event_type,
                         field, old_value, new_value, metadata, source, created_at, updated_at)
                     SELECT id, tenant_id, support_ticket_id, actor_user_id, event_type,
                            field, old_value, new_value, metadata, source, created_at, updated_at
                     FROM {$tabla}"
                );

                Schema::drop($tabla);
                Schema::rename("{$tabla}_nueva", $tabla);

                // Los índices no sobreviven al renombrado: se recrean con los
                // mismos que declaró el PR #3.
                Schema::table($tabla, function (Blueprint $table) {
                    $table->index(['support_ticket_id', 'id']);
                    $table->index('tenant_id');
                    $table->index('event_type');
                });
            });
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
};
