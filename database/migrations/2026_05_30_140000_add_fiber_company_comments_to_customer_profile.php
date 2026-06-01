<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos adicionales del cliente solicitados desde la vista de Clientes:
 *  - comments   : observaciones/comentario libre del operador.
 *  - is_company : el cliente es una empresa (el apellido deja de ser obligatorio).
 *  - is_fiber   : el servicio es por fibra (FTTH). Habilita la selección de OLT
 *                 y reutiliza el selector de "sectorial" como la caja (NAP).
 *  - olt_id     : OLT de fibra al que pertenece el cliente (self-FK a sectorial,
 *                 element_type='olt'). La caja sigue guardándose en sectorial_id.
 *
 * Portabilidad SQLite (tests): se guarda cada columna con hasColumn y la FK se
 * compila a vacío en sqlite (no-op), por lo que solo es FK real en Postgres.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_profile', function (Blueprint $table) {
            if (!Schema::hasColumn('customer_profile', 'is_company')) {
                $table->boolean('is_company')->default(false)->after('last_name')
                    ->comment('El cliente es una empresa (apellido opcional)');
            }
            if (!Schema::hasColumn('customer_profile', 'comments')) {
                $table->text('comments')->nullable()->after('estrato')
                    ->comment('Comentario/observaciones del cliente');
            }
            if (!Schema::hasColumn('customer_profile', 'olt_id')) {
                $table->unsignedBigInteger('olt_id')->nullable()->after('sectorial_id')
                    ->comment('OLT de fibra (sectorial element_type=olt) del cliente');
                $table->index('olt_id');
                $table->foreign('olt_id')->references('id')->on('sectorial')->onDelete('set null');
            }
            if (!Schema::hasColumn('customer_profile', 'is_fiber')) {
                $table->boolean('is_fiber')->default(false)->after('nap_port')
                    ->comment('El servicio del cliente es por fibra (FTTH)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_profile', function (Blueprint $table) {
            if (Schema::hasColumn('customer_profile', 'olt_id')) {
                // dropForeign es no-op en sqlite; en Postgres elimina la FK real.
                try {
                    $table->dropForeign(['olt_id']);
                } catch (\Throwable $e) {
                    // La FK puede no existir (sqlite); ignorar.
                }
                $table->dropColumn('olt_id');
            }
            foreach (['comments', 'is_company', 'is_fiber'] as $col) {
                if (Schema::hasColumn('customer_profile', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
