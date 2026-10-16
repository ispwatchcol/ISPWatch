<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * KAN-124 (P-95): el pago que crea la cartera lleva la marca de su orden.
 *
 * EL PROBLEMA. `InstallationBillingService::syncPayment()` tomaba como «su»
 * pago la PRIMERA asignación de la factura de instalación. Si la factura se
 * abonaba desde Facturación y luego la cartera se guardaba con «Valor recibido»
 * en 0, la rama de quitar el pago borraba ese abono ajeno; con un valor
 * distinto de 0, le reescribía el monto. El dinero que entró por caja dejaba de
 * figurar.
 *
 * EL DISEÑO. `payments.installation_id` dice qué pago es de la cartera. La
 * cartera sólo crea, edita o borra ese; cualquier otra asignación de la factura
 * es de Facturación y se respeta. `SET NULL` al borrar la orden: el pago es
 * dinero recibido y sobrevive a su orden.
 *
 * EL RELLENO. Los pagos viejos no tienen marca. Si la cartera no encontrara el
 * suyo, el siguiente guardado crearía un SEGUNDO pago por el mismo dinero. Por
 * eso se marca, por cada factura de instalación cuya orden tiene valor
 * recibido > 0, la asignación cuyo pago vale exactamente ese valor (es la que
 * el código viejo mantenía igualada); si ninguna coincide, la primera, que es
 * la misma que tomaba el código viejo. Las órdenes con valor recibido 0 no
 * tienen pago de cartera y no se marcan.
 *
 * Rollback: `down()` quita la columna. Ningún monto se modifica, así que
 * revertir sólo pierde las marcas, y volver a migrar las recalcula.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('installation_id')
                ->nullable()
                ->constrained('customer_installations')
                ->nullOnDelete();

            // PostgreSQL no indexa solo las foráneas, y la cartera busca su
            // pago por esta columna en cada guardado.
            $table->index('installation_id');
        });

        $marcados = $this->backfill();

        Log::info("KAN-124: {$marcados} pagos de cartera marcados con su orden de instalación.");
    }

    /**
     * Marca el pago de cartera de cada orden con valor recibido > 0. Sólo
     * escribe en pagos sin marca, así que correrlo de nuevo no cambia nada.
     */
    public function backfill(): int
    {
        $marcados = 0;

        DB::table('invoices')
            ->join('customer_installations', 'customer_installations.id', '=', 'invoices.installation_id')
            ->whereNotNull('invoices.installation_id')
            ->where('customer_installations.payment_received', '>', 0)
            ->select([
                'invoices.id as invoice_id',
                'customer_installations.id as installation_id',
                'customer_installations.payment_received',
            ])
            ->orderBy('invoices.id')
            ->chunk(500, function ($filas) use (&$marcados) {
                foreach ($filas as $fila) {
                    // La orden ya tiene su pago marcado: cualquier otro de la
                    // factura es de Facturación.
                    if (DB::table('payments')->where('installation_id', $fila->installation_id)->exists()) {
                        continue;
                    }

                    $candidatos = DB::table('payment_allocations')
                        ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
                        ->where('payment_allocations.invoice_id', $fila->invoice_id)
                        ->whereNull('payments.installation_id')
                        ->orderBy('payment_allocations.id')
                        ->get(['payments.id', 'payments.amount']);

                    if ($candidatos->isEmpty()) {
                        continue;
                    }

                    $recibido = round((float) $fila->payment_received, 2);
                    $elegido  = $candidatos->first(fn ($p) => round((float) $p->amount, 2) === $recibido)
                        ?? $candidatos->first();

                    $marcados += DB::table('payments')
                        ->where('id', $elegido->id)
                        ->whereNull('installation_id')
                        ->update(['installation_id' => $fila->installation_id]);
                }
            });

        return $marcados;
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['installation_id']);
            $table->dropIndex(['installation_id']);
            $table->dropColumn('installation_id');
        });
    }
};
