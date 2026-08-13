<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Libro de movimientos del saldo a favor.
 *
 * Hasta ahora el saldo a favor vivía SOLO como un escalar en
 * customer_profile.credit_balance: se sumaba al recibir un pago en exceso y se
 * restaba al aplicarlo a una factura, sin dejar asiento de ninguna de las dos
 * cosas. Eso tenía tres consecuencias que costaron plata:
 *
 *  1. Una factura podía quedar `paid` sin una sola fila en payment_allocations,
 *     porque el crédito la saldó por fuera del libro. En producción llegó a
 *     haber 66 pagos sin asignación por $4.6M: el libro no cuadraba con la caja.
 *  2. Nadie podía explicarle al cliente por qué su factura de $60.000 aparecía
 *     en $36.000 en el mostrador.
 *  3. Al revertir un pago, el código restaba el excedente completo del saldo sin
 *     saber si ese excedente YA había sido consumido por una factura posterior.
 *     El max(0, ...) tapaba el hueco y desaparecía saldo de otros pagos.
 *
 * Esta tabla es el espejo positivo de invoice_carryovers, que ya resolvió lo
 * mismo para el lado negativo (los faltantes) y por la misma razón: para poder
 * revertir con precisión hay que guardar movimientos, no un acumulado.
 *
 * credit_balance queda como caché denormalizada para pintar listados sin una
 * consulta extra por fila —igual que carried_in/carried_out en invoices—, pero
 * la verdad vive aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_credits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('customer_id');

            // earned   → un pago dejó excedente y entró al saldo (+)
            // applied  → el saldo pagó una factura (-)
            // adjusted → ajuste manual de un operador (+/-)
            // reversed → se anuló/corrigió el pago que había generado saldo (-)
            $table->string('type', 20);

            // Pago que originó el excedente. Permite revertir con precisión:
            // al anular un pago solo se devuelven SUS movimientos earned que
            // todavía no fueron consumidos por ninguna factura.
            $table->unsignedBigInteger('from_payment_id')->nullable();
            // Factura que consumió el saldo (solo en type=applied).
            $table->unsignedBigInteger('to_invoice_id')->nullable();

            // Positivo suma al saldo, negativo lo consume. La suma de amount de
            // un cliente debe ser igual a su credit_balance; si no, hay un bug.
            $table->decimal('amount', 12, 2);
            // Saldo resultante después de este movimiento: permite auditar el
            // extracto sin recalcular toda la historia en cada consulta.
            $table->decimal('balance_after', 12, 2);

            // Cuánto de este movimiento `earned` ya fue consumido por facturas.
            // Es lo que hace posible la reversión precisa (ver punto 3 arriba).
            $table->decimal('consumed', 12, 2)->default(0);

            $table->string('reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            // web | api | console | import | scheduler
            $table->string('source', 20)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('cascade');
            $table->foreign('customer_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('from_payment_id')->references('id')->on('payments')->nullOnDelete();
            $table->foreign('to_invoice_id')->references('id')->on('invoices')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            // El lookup caliente: extracto de un cliente y búsqueda de los
            // `earned` con saldo sin consumir al revertir un pago.
            $table->index(['customer_id', 'type']);
            $table->index(['tenant_id', 'created_at']);
            $table->index('from_payment_id');
            $table->index('to_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credits');
    }
};
