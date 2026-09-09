<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P-43 · Dar de baja a un cliente ya no destruye su histórico de facturación.
 *
 * QUÉ PASABA
 *
 * `invoices.customer_id`, `payments.customer_id`, `invoice_carryovers.customer_id`,
 * `customer_credits.customer_id` y `customer_additional_services.customer_id` estaban
 * en `ON DELETE CASCADE` sobre `users`, y `CustomerDeletionService` termina con
 * `$user->delete()`.
 *
 * Resultado: dar de baja a un cliente **borraba físicamente sus facturas y sus
 * pagos**. Y con ellos, por cascada de segundo nivel, sus `invoice_items` y sus
 * `payment_allocations` — es decir, el detalle de qué se cobró y qué pago saldó qué
 * factura. También los cargos generados desde un ticket (`invoices.ticket_id`): el
 * ticket sobrevive con su expediente completo desde H-6, pero el cargo que lo
 * justificaba desaparecía.
 *
 * Es el reverso exacto de lo que cerró el PR A: allí se impidió que borrar un ticket
 * dejara la factura huérfana; aquí la factura no quedaba huérfana — dejaba de existir.
 *
 * LA DECISIÓN DE NEGOCIO, que es la que faltaba
 *
 * El histórico de facturación **se conserva**, con nombre y documento del cliente.
 * En Colombia los papeles de comercio se conservan diez años (Código de Comercio,
 * arts. 28 y 60) y un cierre contable no puede depender de que nadie haya dado de
 * baja a un abonado. Guardar nombre y cédula aquí no crea una copia nueva de datos
 * personales: son exactamente los dos campos que la factura emitida ya lleva
 * impresos, y sin ellos el histórico conservado sería un montón de cifras que no se
 * pueden atribuir a nadie en una revisión fiscal.
 *
 * Es un criterio distinto al de H-6, que congeló SÓLO el nombre en las notas de
 * ticket. Allí el expediente necesita saber quién escribió; aquí la contabilidad
 * necesita saber a quién se le facturó, y eso exige el documento.
 *
 * QUÉ CAMBIA
 *
 *   1. Las cinco columnas pasan a NULLABLE. `SET NULL` no puede aplicarse sobre una
 *      columna `NOT NULL`: el motor rechazaría la propia constraint.
 *   2. Las cinco claves foráneas pasan a `ON DELETE SET NULL`.
 *   3. `invoices` y `payments` ganan `customer_name` y `customer_document`, un
 *      snapshot congelado del titular.
 *
 * POR QUÉ EL SNAPSHOT VA SÓLO EN `invoices` Y `payments`
 *
 * Son las dos tablas que se leen solas en un informe. Un `invoice_carryover` cuelga
 * de una factura (`from_invoice_id` / `to_invoice_id`), un `customer_credit` de su
 * origen y un `payment_allocation` de ambos: identificarlos es seguir el vínculo, no
 * duplicar el dato. Repetir nombre y cédula en cinco sitios sería multiplicar por
 * cinco la copia de datos personales sin ganar un solo informe nuevo.
 *
 * POR QUÉ LAS CINCO Y NO LAS TRES DEL DOCUMENTO
 *
 * `docs/MEJORAS_RECOMENDADAS.md` § 7 · P-43 nombra tres tablas. Al inventariarlas
 * para esta migración aparecieron dos más con la misma cascada y la misma naturaleza
 * contable: `customer_credits` (saldo a favor: dinero que el cliente entregó) y
 * `customer_additional_services` (los servicios contratados que justifican los
 * cargos). Arreglar tres y dejar dos habría dado por cerrada una deuda que seguiría
 * perdiendo dinero por otro lado.
 *
 * LO QUE ESTA MIGRACIÓN NO TOCA — a propósito
 *
 *   · `invoice_items.invoice_id` y `payment_allocations.{payment,invoice}_id`, que
 *     siguen en CASCADE. Es lo correcto: un ítem sin factura o una asignación sin
 *     pago no significan nada. Al sobrevivir la factura y el pago, sobreviven ellos.
 *   · `customer_documents`, `user_services`, `customer_installations` y las bitácoras
 *     de acción, que siguen cascadeando. No son contabilidad, y el servicio de
 *     borrado ya se ocupa de sus archivos en S3.
 *   · `customer_profile` y el propio `users`: el cliente se borra de verdad. Lo que
 *     sobrevive es el asiento contable, no la ficha del abonado.
 *
 * VERIFICACIÓN EN EL MOTOR REAL. Las pruebas corren en SQLite, que sí aplica claves
 * foráneas (`foreign_key_constraints` está en `true`), así que el comportamiento se
 * comprueba de verdad. El cambio de constraint en PostgreSQL lo verifica el job
 * «PHPUnit (PostgreSQL, motor real)» de CI.
 */
return new class extends Migration
{
    /** Tabla => columna que apunta a `users`. */
    private const TABLAS = [
        'invoices'                     => 'customer_id',
        'payments'                     => 'customer_id',
        'invoice_carryovers'           => 'customer_id',
        'customer_credits'             => 'customer_id',
        'customer_additional_services' => 'customer_id',
    ];

    /** Las que además congelan al titular. */
    private const CON_SNAPSHOT = ['invoices', 'payments'];

    public function up(): void
    {
        foreach (self::CON_SNAPSHOT as $tabla) {
            $this->agregarColumnasSnapshot($tabla);
            $this->rellenarSnapshot($tabla);
        }

        foreach (self::TABLAS as $tabla => $columna) {
            $this->aRegla($tabla, $columna, 'set null');
        }
    }

    /**
     * Vuelve a `CASCADE` y quita el snapshot.
     *
     * Revertir devuelve el sistema al estado en que dar de baja a un cliente destruye
     * su contabilidad. Se implementa porque una migración debe ser reversible, no
     * porque revertirla sea buena idea.
     *
     * OJO: las columnas se quedan NULLABLE. Las filas que ya hayan perdido a su
     * titular —porque se borró un cliente después de esta migración— no pueden volver
     * a `NOT NULL`, y restaurarlo obligaría a inventarles un usuario o a borrarlas,
     * que es exactamente el daño que esta migración vino a impedir. Es el mismo
     * criterio que dejó escrito H-6.
     */
    public function down(): void
    {
        foreach (self::TABLAS as $tabla => $columna) {
            $this->aRegla($tabla, $columna, 'cascade');
        }

        foreach (self::CON_SNAPSHOT as $tabla) {
            if (!Schema::hasTable($tabla)) {
                continue;
            }

            foreach (['customer_name', 'customer_document'] as $columna) {
                if (Schema::hasColumn($tabla, $columna)) {
                    Schema::table($tabla, fn (Blueprint $t) => $t->dropColumn($columna));
                }
            }
        }
    }

    private function agregarColumnasSnapshot(string $tabla): void
    {
        if (!Schema::hasTable($tabla)) {
            return;
        }

        Schema::table($tabla, function (Blueprint $t) use ($tabla) {
            // 160: `users.user_name` + `user_lastname` caben de sobra, y es el mismo
            // margen que usa el nombre impreso en la factura.
            if (!Schema::hasColumn($tabla, 'customer_name')) {
                $t->string('customer_name', 160)->nullable()->after('customer_id');
            }

            // 40: una cédula colombiana son diez dígitos, pero un NIT con dígito de
            // verificación, un pasaporte o una cédula de extranjería son más largos.
            if (!Schema::hasColumn($tabla, 'customer_document')) {
                $t->string('customer_document', 40)->nullable()->after('customer_name');
            }
        });
    }

    /**
     * Congela al titular de las filas que ya existen.
     *
     * El nombre sale de `users` y el documento de `customer_profile`, que es
     * exactamente de donde los toma `PlaceholderResolver::forInvoice()` para
     * imprimirlos: el snapshot tiene que decir lo mismo que dice el papel.
     *
     * Se hace en PHP y no con un `UPDATE ... FROM` porque esa sintaxis difiere entre
     * PostgreSQL y SQLite, y la suite corre en los dos.
     */
    private function rellenarSnapshot(string $tabla): void
    {
        if (!Schema::hasTable($tabla) || !Schema::hasColumn($tabla, 'customer_name')) {
            return;
        }

        $conPerfil = Schema::hasTable('customer_profile');

        DB::table($tabla)
            ->whereNull('customer_name')
            ->whereNotNull('customer_id')
            ->orderBy('id')
            ->chunkById(500, function ($filas) use ($tabla, $conPerfil) {
                $ids = $filas->pluck('customer_id')->unique()->all();

                $usuarios = DB::table('users')
                    ->whereIn('id', $ids)
                    ->get(['id', 'user_name', 'user_lastname', 'email'])
                    ->keyBy('id');

                $perfiles = $conPerfil
                    ? DB::table('customer_profile')
                        ->whereIn('user_id', $ids)
                        ->get(['user_id', 'name', 'last_name', 'cedula'])
                        ->keyBy('user_id')
                    : collect();

                foreach ($filas as $fila) {
                    $usuario = $usuarios->get($fila->customer_id);
                    $perfil  = $perfiles->get($fila->customer_id);

                    $nombre = trim(($usuario->user_name ?? '') . ' ' . ($usuario->user_lastname ?? ''));

                    if ($nombre === '') {
                        $nombre = trim(($perfil->name ?? '') . ' ' . ($perfil->last_name ?? ''));
                    }

                    if ($nombre === '') {
                        $nombre = (string) ($usuario->email ?? '');
                    }

                    $documento = trim((string) ($perfil->cedula ?? ''));

                    if ($nombre === '' && $documento === '') {
                        continue;
                    }

                    DB::table($tabla)->where('id', $fila->id)->update([
                        'customer_name'     => $nombre !== '' ? mb_substr($nombre, 0, 160) : null,
                        'customer_document' => $documento !== '' ? mb_substr($documento, 0, 40) : null,
                    ]);
                }
            });
    }

    private function aRegla(string $tabla, string $columna, string $accion): void
    {
        if (!Schema::hasTable($tabla) || !Schema::hasColumn($tabla, $columna)) {
            // Sólo puede ocurrir si se revierte la migración que creó la tabla sin
            // revertir ésta. Abortar dejaría el despliegue muerto por una tabla que
            // ya no existe.
            return;
        }

        match (DB::getDriverName()) {
            'pgsql'  => $this->enPostgres($tabla, $columna, $accion),
            'sqlite' => $this->enSqlite($tabla, $columna, $accion),
            default  => throw new RuntimeException(
                'Motor no contemplado para el cambio de clave foránea: ' . DB::getDriverName()
            ),
        };
    }

    private function enPostgres(string $tabla, string $columna, string $accion): void
    {
        $regla = strtoupper($accion);

        // La columna primero: `SET NULL` sobre una `NOT NULL` sería una constraint
        // imposible de satisfacer y PostgreSQL la rechaza.
        DB::statement("ALTER TABLE {$tabla} ALTER COLUMN {$columna} DROP NOT NULL");

        // Se sueltan TODAS las claves foráneas que salgan de esa columna, sin suponer
        // cómo se llaman. Suponer el nombre de Laravel (`{tabla}_{columna}_foreign`)
        // deja de ser seguro en cuanto una tabla se haya creado con SQL a mano:
        // PostgreSQL nombra entonces `{tabla}_{columna}_fkey`, el `DROP ... IF EXISTS`
        // no encuentra nada y la tabla acaba con DOS foráneas sobre la misma columna.
        // La de CASCADE gana y el borrado se lleva la fila igual, sin que nada falle a
        // la vista. Le pasó a H-6 montando su propio entorno de prueba.
        foreach ($this->clavesForaneas($tabla, $columna) as $nombre) {
            DB::statement("ALTER TABLE {$tabla} DROP CONSTRAINT IF EXISTS \"{$nombre}\"");
        }

        DB::statement(
            "ALTER TABLE {$tabla} ADD CONSTRAINT {$tabla}_{$columna}_foreign "
            . "FOREIGN KEY ({$columna}) REFERENCES users(id) ON DELETE {$regla}"
        );
    }

    /**
     * Nombres de todas las claves foráneas que parten de esa columna en esa tabla.
     *
     * @return array<int, string>
     */
    private function clavesForaneas(string $tabla, string $columna): array
    {
        $filas = DB::select(
            "SELECT c.conname
               FROM pg_constraint c
               JOIN pg_class t ON t.oid = c.conrelid
               JOIN unnest(c.conkey) k ON true
               JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k
              WHERE t.relname = ? AND c.contype = 'f' AND a.attname = ?",
            [$tabla, $columna]
        );

        return array_map(fn ($f) => $f->conname, $filas);
    }

    /**
     * SQLite no sabe redefinir una clave foránea: viven dentro del `CREATE TABLE`.
     *
     * H-6 y el PR A reconstruían la tabla a mano, columna por columna. Aquí no se
     * puede: `invoices` tiene veintitantas columnas y `payments` una decena, y
     * transcribirlas significaría mantener una segunda copia del esquema que se
     * desincroniza en cuanto alguien añada una columna.
     *
     * Laravel 12 hace esa reconstrucción solo —introspecciona la tabla, la recrea con
     * la definición nueva y copia las filas— para `change`, `dropForeign` y `foreign`
     * sobre SQLite. Es el mismo procedimiento, sin la copia del esquema a mano.
     */
    private function enSqlite(string $tabla, string $columna, string $accion): void
    {
        Schema::table($tabla, function (Blueprint $t) use ($columna, $accion) {
            // Laravel 12 no preserva los atributos que no se repiten en un `change()`,
            // así que hay que volver a declarar el tipo. Las cinco columnas son
            // `foreignId`, es decir `unsignedBigInteger`.
            $t->unsignedBigInteger($columna)->nullable()->change();

            $t->dropForeign([$columna]);

            $foranea = $t->foreign($columna)->references('id')->on('users');

            $accion === 'set null' ? $foranea->nullOnDelete() : $foranea->cascadeOnDelete();
        });
    }
};
