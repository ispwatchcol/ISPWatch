<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anular una factura sin destruirla.
 *
 * QUÉ AÑADE
 *
 *   voided_at    timestamp NULL    — cuándo se anuló
 *   voided_by    bigint NULL       — quién, FK users ON DELETE SET NULL
 *   void_reason  varchar(500) NULL — por qué, obligatorio al anular
 *
 * POR QUÉ COLUMNAS Y NO SÓLO `audit_logs`
 *
 * El evento de auditoría se escribe igualmente y es la fuente legal. Pero la
 * pantalla de facturas tiene que poder decir «anulada el 3 de septiembre por
 * Ana Ríos: cobro duplicado» sin ir a buscarlo a otra tabla que además está
 * detrás de `view_audit_log`. Quien mira una factura anulada necesita saber por
 * qué lo está; si el dato vive sólo en la bitácora, en la práctica no existe.
 *
 * Es el mismo patrón que el PR C dejó en `support_ticket` con `archived_by` y
 * `archived_reason`: el motivo en la fila para leerlo, el evento en la auditoría
 * para que nadie lo pueda tocar.
 *
 * `voided_by` VA EN `ON DELETE SET NULL`, no `CASCADE`: dar de baja al
 * contador que anuló una factura no puede llevarse la factura por delante. Es
 * la misma decisión que P-43 tomó para el histórico de facturación y H-6 para
 * las notas de ticket.
 *
 * NO SE AÑADE UN ESTADO NUEVO. `void` y `cancelled` ya existen en el CHECK de
 * `invoices.status` desde la migración del módulo (2026-01-13) y ya están
 * excluidos del dinero en todas las consultas —resúmenes, cobranza,
 * recordatorios, cortes, dashboard—. Lo que faltaba no era el estado: era una
 * puerta para llegar a él con motivo, permiso y rastro.
 *
 * NINGÚN DATO EXISTENTE CAMBIA. Todas las facturas quedan con las tres columnas
 * en NULL, que es exactamente «no anulada».
 */
return new class extends Migration
{
    private const TABLA = 'invoices';

    public function up(): void
    {
        Schema::table(self::TABLA, function (Blueprint $table) {
            if (!Schema::hasColumn(self::TABLA, 'voided_at')) {
                $table->timestamp('voided_at')->nullable();
            }

            if (!Schema::hasColumn(self::TABLA, 'voided_by')) {
                // `unsignedBigInteger` suelto y no `foreignId()->constrained()`:
                // la clave foránea se añade abajo sólo en PostgreSQL. SQLite no
                // sabe agregar una foránea a una tabla existente sin
                // reconstruirla entera, y `invoices` tiene demasiadas relaciones
                // colgando para reconstruirla por una columna de auditoría.
                $table->unsignedBigInteger('voided_by')->nullable();
            }

            if (!Schema::hasColumn(self::TABLA, 'void_reason')) {
                $table->string('void_reason', 500)->nullable();
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->foraneaEnPostgres();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ' . self::TABLA . ' DROP CONSTRAINT IF EXISTS invoices_voided_by_foreign');
        }

        Schema::table(self::TABLA, function (Blueprint $table) {
            foreach (['void_reason', 'voided_by', 'voided_at'] as $columna) {
                if (Schema::hasColumn(self::TABLA, $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });

        // ADVERTENCIA: revertir NO desanula nada. Las facturas anuladas siguen
        // en `void` y siguen fuera del dinero; lo que se pierde es el motivo y
        // el autor visibles en la fila. El evento de `audit_logs` los conserva.
    }

    /**
     * `IF EXISTS` antes de crear para que la migración sea idempotente: si un
     * despliegue a medias la dejó puesta, repetirla no debe fallar.
     */
    private function foraneaEnPostgres(): void
    {
        DB::statement('ALTER TABLE ' . self::TABLA . ' DROP CONSTRAINT IF EXISTS invoices_voided_by_foreign');

        DB::statement(
            'ALTER TABLE ' . self::TABLA . ' ADD CONSTRAINT invoices_voided_by_foreign '
            . 'FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL'
        );
    }
};
