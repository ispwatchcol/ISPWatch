<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motivo NORMALIZADO por el que una reconexión quedó pendiente.
 *
 * No se crea tabla nueva: el ciclo corte/reconexión ya vive entero en
 * suspension_action_logs (acción, estado, intentos, backoff, error). Lo que
 * faltaba era el POR QUÉ en un vocabulario cerrado — hasta ahora sólo existía
 * `error_message`, texto libre del equipo, que no se puede filtrar, contar ni
 * pintar en pantalla (y que además arrastra IPs y detalles del RouterBoard).
 *
 * `reason` no sirve para esto: responde a otra pregunta (QUÉ originó la acción
 * — manual, corte por mora, reconciliación, pago) y se seguirá usando igual.
 * `outcome` responde CÓMO terminó.
 *
 * Nullable porque las filas históricas no tienen forma de saberlo y adivinarlo
 * sería inventar datos en una bitácora.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('suspension_action_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('suspension_action_logs', 'outcome')) {
                $table->string('outcome', 40)->nullable()->after('reason')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('suspension_action_logs', function (Blueprint $table) {
            if (Schema::hasColumn('suspension_action_logs', 'outcome')) {
                $table->dropIndex(['outcome']);
                $table->dropColumn('outcome');
            }
        });
    }
};
