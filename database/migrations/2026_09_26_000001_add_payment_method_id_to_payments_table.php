<?php

use App\Services\PaymentMethodLinker;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * KAN-109: el pago referencia su forma de pago por id, no sólo por nombre.
 *
 * `payments.method` NO se toca ni aquí ni en ningún otro lado: sigue siendo el
 * nombre con el que se registró cada pago. Lo que se agrega es la referencia
 * estable al catálogo, que es lo que sobrevive a un renombrado.
 *
 * `SET NULL` al borrar la forma de pago: el pago no puede desaparecer ni
 * bloquearse porque alguien limpió el catálogo, y al perder el enlace vuelve a
 * mostrarse con su texto original.
 *
 * El relleno enlaza sólo coincidencias únicas y exactas (ver
 * PaymentMethodLinker). Es una sentencia por forma de pago enlazada, no por
 * pago, para que quepa de sobra en la ventana del despliegue (BITÁCORA § 60).
 *
 * Rollback: `down()` quita la columna. Como el texto de cada pago nunca se
 * modificó, revertir no pierde nada más que los enlaces, y volver a migrar los
 * recalcula.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('payment_method_id')
                ->nullable()
                ->constrained('payment_methods')
                ->nullOnDelete();

            // PostgreSQL no indexa solo las foráneas, y el listado de recaudos
            // filtra por esta columna.
            $table->index('payment_method_id');
        });

        $linker = new PaymentMethodLinker();
        $plan   = $linker->plan();
        $linked = $linker->apply($plan);

        $pending = $plan->where('result', '!=', PaymentMethodLinker::MATCH)->sum('payments');

        Log::info("KAN-109: {$linked} pagos enlazados a su forma de pago; {$pending} quedan con su texto histórico. Detalle: php artisan payments:link-methods");
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['payment_method_id']);
            $table->dropIndex(['payment_method_id']);
            $table->dropColumn('payment_method_id');
        });
    }
};
