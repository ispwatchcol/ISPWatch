<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * H-6 · Dar de baja a un cliente vaciaba el expediente de sus tickets.
 *
 * QUÉ PASABA
 *
 * `support_ticket_message.user_id` y `support_ticket_attachment.user_id` se
 * declararon en 2025 con `ON DELETE CASCADE` sobre `users`, y
 * `CustomerDeletionService` termina con `$user->delete()`. Resultado: dar de
 * baja a un cliente **borraba físicamente todas las notas de bitácora y todos
 * los adjuntos** de sus tickets.
 *
 * El ticket sobrevivía —su `user_id` es `SET NULL` desde 2024— pero quedaba
 * vaciado por dentro: un expediente sin evidencia ni bitácora, con el historial
 * del PR #3 señalando filas que ya no existen.
 *
 * Y los archivos del bucket se quedaban huérfanos: `collectFilePaths()` sólo
 * recoge documentos de cliente y firmas de instalación, nunca adjuntos de
 * ticket. Desaparecía la fila que decía dónde estaba el archivo, no el archivo.
 *
 * QUÉ CAMBIA
 *
 *   1. Las dos columnas pasan a NULLABLE. `SET NULL` no puede aplicarse sobre
 *      una columna `NOT NULL`: el motor rechazaría la propia constraint.
 *   2. Las dos claves foráneas pasan a `ON DELETE SET NULL`, alineadas con
 *      `support_ticket.user_id` y con `support_ticket_history.actor_user_id`,
 *      que ya lo hacían así.
 *   3. Se añade `author_name`, un nombre visible congelado.
 *
 * POR QUÉ HACE FALTA `author_name`
 *
 * Con `SET NULL` la nota sobrevive pero pierde a su autor, y la bitácora
 * quedaría llena de «—». El nombre se congela EN EL MOMENTO de escribir, que es
 * además lo correcto para un expediente: refleja quién firmaba entonces, no
 * cómo se llama hoy. Es el mismo criterio del historial del PR #3, que congela
 * la etiqueta del catálogo en `metadata`.
 *
 * SÓLO EL NOMBRE. Ni correo, ni teléfono, ni documento: el expediente necesita
 * saber quién escribió, no reconstruir la ficha de una persona que pidió su
 * baja. Guardar más sería crear una copia que sobrevive al borrado que el
 * cliente solicitó.
 *
 * LO QUE ESTA MIGRACIÓN NO TOCA — a propósito
 *
 *   · `support_ticket_history.support_ticket_id` (RESTRICT, PR A) ni su
 *     `tenant_id` (CASCADE, decisión documentada pendiente).
 *   · `invoices.customer_id`, que sigue en `CASCADE`: dar de baja a un cliente
 *     borra sus facturas, incluidos los cargos de ticket. Es un problema real y
 *     distinto —toca contabilidad— y se documenta sin cambiarlo.
 *   · Las otras ocho tablas que cascadean desde `users`; ninguna es parte del
 *     expediente del ticket.
 */
return new class extends Migration
{
    /** Tabla => si además lleva `updated_at`, que `support_ticket_attachment` no tiene. */
    private const TABLAS = [
        'support_ticket_message'    => true,
        'support_ticket_attachment' => false,
    ];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla => $conUpdatedAt) {
            if (!Schema::hasColumn($tabla, 'author_name')) {
                Schema::table($tabla, function (Blueprint $table) {
                    // 120 caracteres: `users.user_name` + `user_lastname` caben
                    // de sobra y deja margen para nombres largos.
                    $table->string('author_name', 120)->nullable()->after('user_id');
                });
            }

            $this->rellenarNombres($tabla);
            $this->aRegla($tabla, 'set null', $conUpdatedAt);
        }
    }

    /**
     * Vuelve a `CASCADE` y quita `author_name`.
     *
     * Revertir devuelve el sistema al estado en que dar de baja a un cliente
     * vacía sus tickets. Se implementa porque una migración debe ser reversible,
     * no porque revertirla sea buena idea.
     *
     * OJO: las filas que ya tengan `user_id` NULL —porque se borró un usuario
     * después de esta migración— no pueden volver a `NOT NULL`. Por eso el
     * `down()` deja la columna nullable y sólo restaura la regla de borrado: es
     * lo máximo reversible sin inventar un usuario para esas filas.
     */
    public function down(): void
    {
        foreach (self::TABLAS as $tabla => $conUpdatedAt) {
            $this->aRegla($tabla, 'cascade', $conUpdatedAt);

            if (Schema::hasColumn($tabla, 'author_name')) {
                Schema::table($tabla, fn (Blueprint $table) => $table->dropColumn('author_name'));
            }
        }
    }

    /**
     * Congela el nombre visible de los autores que todavía existen.
     *
     * Se hace en PHP y no con un `UPDATE ... FROM` porque esa sintaxis difiere
     * entre PostgreSQL y SQLite, y la suite corre en los dos. El volumen es de
     * notas y adjuntos de tickets: recorrerlo por lotes es sobrado.
     */
    private function rellenarNombres(string $tabla): void
    {
        DB::table($tabla)
            ->whereNull('author_name')
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->chunkById(500, function ($filas) use ($tabla) {
                $usuarios = DB::table('users')
                    ->whereIn('id', $filas->pluck('user_id')->unique()->all())
                    ->get(['id', 'user_name', 'user_lastname', 'email'])
                    ->keyBy('id');

                foreach ($filas as $fila) {
                    $usuario = $usuarios->get($fila->user_id);

                    if (!$usuario) {
                        continue;
                    }

                    $nombre = trim(($usuario->user_name ?? '') . ' ' . ($usuario->user_lastname ?? ''));
                    $nombre = $nombre !== '' ? $nombre : (string) $usuario->email;

                    if ($nombre === '') {
                        continue;
                    }

                    DB::table($tabla)->where('id', $fila->id)
                        ->update(['author_name' => mb_substr($nombre, 0, 120)]);
                }
            });
    }

    private function aRegla(string $tabla, string $accion, bool $conUpdatedAt): void
    {
        match (DB::getDriverName()) {
            'pgsql'  => $this->enPostgres($tabla, $accion),
            'sqlite' => $this->enSqlite($tabla, $accion, $conUpdatedAt),
            default  => throw new RuntimeException(
                'Motor no contemplado para el cambio de clave foránea: ' . DB::getDriverName()
            ),
        };
    }

    private function enPostgres(string $tabla, string $accion): void
    {
        $regla = strtoupper($accion);

        // La columna primero: `SET NULL` sobre una `NOT NULL` sería una
        // constraint imposible de satisfacer y PostgreSQL la rechaza.
        DB::statement("ALTER TABLE {$tabla} ALTER COLUMN user_id DROP NOT NULL");

        // Se sueltan TODAS las claves foráneas que salgan de `user_id`, sin
        // suponer cómo se llaman.
        //
        // Suponer el nombre de Laravel (`{tabla}_{columna}_foreign`) parecía
        // seguro —es el que tiene producción, comprobado— pero deja de serlo en
        // cuanto una tabla se haya creado con SQL a mano: PostgreSQL nombra
        // entonces `{tabla}_{columna}_fkey`, el `DROP ... IF EXISTS` no
        // encuentra nada y la tabla acaba con DOS foráneas sobre la misma
        // columna. La de CASCADE gana y el borrado se lleva la fila igual, sin
        // que nada falle a la vista. Ocurrió montando el entorno de prueba de
        // esta misma migración.
        foreach ($this->clavesForaneasDeUserId($tabla) as $nombre) {
            DB::statement("ALTER TABLE {$tabla} DROP CONSTRAINT IF EXISTS {$nombre}");
        }

        DB::statement(
            "ALTER TABLE {$tabla} ADD CONSTRAINT {$tabla}_user_id_foreign "
            . "FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE {$regla}"
        );
    }

    /**
     * Nombres de todas las FK que parten de `user_id` en esa tabla.
     *
     * @return array<int, string>
     */
    private function clavesForaneasDeUserId(string $tabla): array
    {
        $filas = DB::select(
            "SELECT c.conname
               FROM pg_constraint c
               JOIN pg_class t ON t.oid = c.conrelid
               JOIN unnest(c.conkey) k ON true
               JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k
              WHERE t.relname = ? AND c.contype = 'f' AND a.attname = 'user_id'",
            [$tabla]
        );

        return array_map(fn ($f) => $f->conname, $filas);
    }

    /**
     * SQLite no sabe soltar ni redefinir una clave foránea: viven dentro del
     * `CREATE TABLE`. Hay que reconstruir la tabla, copiar las filas y recrear
     * los índices, con las foráneas desactivadas o el `DROP` de la vieja falla
     * por las filas que acaban de copiarse.
     *
     * Es el mismo procedimiento que usó el PR A con `support_ticket_history`.
     */
    private function enSqlite(string $tabla, string $accion, bool $conUpdatedAt): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        try {
            DB::transaction(function () use ($tabla, $accion, $conUpdatedAt) {
                Schema::create("{$tabla}_nueva", function (Blueprint $table) use ($tabla, $accion, $conUpdatedAt) {
                    $table->id();
                    $table->unsignedBigInteger('ticket_id');
                    $table->unsignedBigInteger('user_id')->nullable();
                    $table->string('author_name', 120)->nullable();

                    if ($tabla === 'support_ticket_message') {
                        $table->text('message');
                        $table->boolean('is_internal')->default(false);
                    } else {
                        $table->string('file_name');
                        $table->string('file_path', 500);
                        $table->integer('file_size');
                        $table->string('mime_type', 100);
                    }

                    if ($conUpdatedAt) {
                        $table->timestamps();
                    } else {
                        // `support_ticket_attachment` nunca tuvo `updated_at`.
                        $table->timestamp('created_at')->useCurrent();
                    }

                    $table->foreign('ticket_id')->references('id')->on('support_ticket')->onDelete('cascade');
                    $table->foreign('user_id')->references('id')->on('users')->onDelete($accion);
                });

                $columnas = $tabla === 'support_ticket_message'
                    ? 'id, ticket_id, user_id, author_name, message, is_internal, created_at, updated_at'
                    : 'id, ticket_id, user_id, author_name, file_name, file_path, file_size, mime_type, created_at';

                // Copia explícita: un `SELECT *` se rompería en silencio si el
                // orden de las columnas difiriera entre las dos tablas.
                DB::statement("INSERT INTO {$tabla}_nueva ({$columnas}) SELECT {$columnas} FROM {$tabla}");

                Schema::drop($tabla);
                Schema::rename("{$tabla}_nueva", $tabla);
            });
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
};
