<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arrastre de saldo por pago parcial.
 *
 * Cuando un cliente abona menos del saldo de una factura, la factura se cierra
 * como PAGADA y el faltante queda registrado aquí como deuda pendiente, que la
 * siguiente factura mensual absorbe como un ítem más. Es el espejo negativo del
 * credit_balance del cliente (que guarda el excedente).
 *
 * Se usa una tabla de movimientos y no un simple saldo escalar porque hay que
 * poder revertir: si se borra el pago, se corrige el monto o se marca la factura
 * como no pagada, el arrastre que AÚN no se cobró vuelve a la factura original;
 * el que ya viajó a otra factura se queda donde está y no se cobra dos veces.
 *
 * carried_in / carried_out en `invoices` son denormalización para pintar los
 * listados sin una consulta extra por fila; la verdad vive en esta tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_carryovers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('customer_id');
            // Factura que quedó pagada dejando el faltante.
            $table->unsignedBigInteger('from_invoice_id')->nullable();
            // Factura que finalmente cobró ese faltante (NULL mientras esté pendiente).
            $table->unsignedBigInteger('to_invoice_id')->nullable();
            // Pago parcial que originó el arrastre (para revertirlo con precisión).
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('pending'); // pending | applied
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('cascade');
            $table->foreign('customer_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('from_invoice_id')->references('id')->on('invoices')->nullOnDelete();
            $table->foreign('to_invoice_id')->references('id')->on('invoices')->nullOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->nullOnDelete();

            // El lookup caliente: "¿cuánto arrastra este cliente?" en cada factura nueva.
            $table->index(['customer_id', 'status']);
            $table->index(['tenant_id', 'status']);
            $table->index('from_invoice_id');
            $table->index('to_invoice_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            // Saldo de facturas anteriores que ESTA factura está cobrando.
            $table->decimal('carried_in', 12, 2)->default(0)->after('balance_due');
            // Saldo que esta factura trasladó a la siguiente al cerrarse con abono.
            $table->decimal('carried_out', 12, 2)->default(0)->after('carried_in');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['carried_in', 'carried_out']);
        });

        Schema::dropIfExists('invoice_carryovers');
    }
};
