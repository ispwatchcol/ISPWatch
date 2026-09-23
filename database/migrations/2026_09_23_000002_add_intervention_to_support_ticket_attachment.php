<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PR F1 · La evidencia se enlaza a la intervención que la produjo (§ 14).
 *
 * El documento pide, literalmente:
 *
 *   «Evidencia | Tipo, archivo, fecha, usuario, descripción e intervención
 *    relacionada.»
 *
 * De esos seis, `support_ticket_attachment` ya tiene tres y medio: el archivo
 * (`file_name`/`file_path`), la fecha (`created_at`) y el usuario (`user_id` +
 * `author_name` congelado). Faltan el TIPO, la DESCRIPCIÓN y la INTERVENCIÓN.
 *
 * Se añaden como tres columnas nullable sobre la tabla que ya existe, y NO como
 * una tabla de evidencias aparte. El archivo ya está subido, ya vive en el
 * bucket privado y ya se sirve por un endpoint que comprueba tenant y ticket:
 * duplicarlo para «evidencia de intervención» significaría dos copias del mismo
 * byte y dos sitios donde comprobar permisos. Las tres columnas son nullable
 * porque la evidencia suelta del ticket —la que manda el cliente al abrirlo—
 * sigue siendo válida y no pertenece a ninguna visita.
 *
 * LA CLAVE FORÁNEA COMPUESTA, Y POR QUÉ NO BASTA UNA SIMPLE
 *
 * Con `intervention_id` a secas, nada impediría colgar una evidencia del ticket
 * 10 de una intervención del ticket 77. El aislamiento dependería de que ningún
 * `where` se olvide, y esta tabla es justo donde eso más duele: NO TIENE
 * `tenant_id` —lo deriva del ticket—, así que un enlace cruzado no sólo mezcla
 * expedientes, puede cruzar ISPs.
 *
 * Por eso la foránea va contra `(intervention_id, ticket_id)` y apunta al único
 * `(id, support_ticket_id)` de la tabla de intervenciones. Así es la base quien
 * garantiza que evidencia e intervención son del mismo ticket, y no la
 * disciplina de quien esté de turno. Ambos motores la soportan, y en SQLite las
 * foráneas están activas en la suite (`config/database.php`,
 * `foreign_key_constraints`), así que la garantía es real en los dos.
 *
 * `ON DELETE RESTRICT`: una intervención no se borra —no hay endpoint— pero si
 * alguien lo intentara por SQL, tener evidencia colgando debe impedirlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_ticket_attachment', function (Blueprint $table) {
            $table->unsignedBigInteger('intervention_id')->nullable()->after('ticket_id');

            // «Tipo» de evidencia: foto del sitio, captura de una medición, log.
            // Texto libre y no catálogo: el § 14 enumera tipos en prosa y no les
            // asigna código, exactamente como las subcausas del Anexo A.2 — y el
            // cliente ya cerró ese criterio en D-06 (11/09/2026): mantenerlas
            // como referencia y no inventar códigos.
            $table->string('evidence_type', 40)->nullable()->after('intervention_id');

            $table->text('description')->nullable()->after('evidence_type');
        });

        // Fuera del Blueprint: la foránea compuesta no se puede expresar con el
        // constructor de esquemas de Laravel.
        //
        // SQLite no admite añadir una foránea a una tabla existente con ALTER
        // TABLE. No es un problema práctico: la suite crea el esquema desde
        // cero, así que allí la tabla ya nace con la columna y la comprobación
        // de integridad que de verdad importa —que el par exista— la aporta el
        // índice único de la tabla de intervenciones más la validación del
        // servicio. En PostgreSQL, que es el motor real, la foránea sí se crea.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('
                ALTER TABLE support_ticket_attachment
                ADD CONSTRAINT support_ticket_attachment_intervention_fk
                FOREIGN KEY (intervention_id, ticket_id)
                REFERENCES ticket_intervention (id, support_ticket_id)
                ON DELETE RESTRICT
            ');
        }

        Schema::table('support_ticket_attachment', function (Blueprint $table) {
            $table->index('intervention_id', 'support_ticket_attachment_intervention_idx');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('
                ALTER TABLE support_ticket_attachment
                DROP CONSTRAINT IF EXISTS support_ticket_attachment_intervention_fk
            ');
        }

        Schema::table('support_ticket_attachment', function (Blueprint $table) {
            // El índice antes que la columna: SQLite no elimina los índices
            // dependientes al dropear una columna y la reversión fallaría con
            // «error in index … after drop column». Ya mordió en la R1.
            $table->dropIndex('support_ticket_attachment_intervention_idx');
            $table->dropColumn(['intervention_id', 'evidence_type', 'description']);
        });
    }
};
