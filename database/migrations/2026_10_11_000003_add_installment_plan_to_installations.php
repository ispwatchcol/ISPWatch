<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Acuerdo de pago» de la instalación: dividir el saldo en cuotas.
 *
 * EL PROBLEMA. La casilla `payment_agreement` existía desde el 2026-06-01 y no
 * hacía NADA: se guardaba y punto. La factura de instalación nacía vencida el
 * mismo día por el total menos lo recibido, así que al cliente con acuerdo de
 * pago se le contaba esa factura como vencida desde el día siguiente — y con
 * `overdue_invoices = 1` se le cortaba por una deuda que se había pactado
 * pagar en cuotas.
 *
 * EL DISEÑO. Las cuotas son ARRASTRES (`invoice_carryovers`) numerados:
 *
 *   customer_installations
 *     installments_mode            'monthly'  → cada factura MENSUAL cobra la
 *                                               siguiente cuota como un ítem más
 *                                  'separate' → cada cuota es su propia factura
 *                                               de instalación, una por mes
 *     installments_count           en cuántas cuotas (1–24)
 *     installments_first_due_date  vencimiento de la 1.ª (sólo 'separate'; las
 *                                  demás, mes a mes desde ahí)
 *
 *   invoice_carryovers
 *     installment_number / installment_count   «cuota 2 de 3». NULL en los
 *                                  arrastres de abono parcial, que no cambian.
 *
 * La factura de instalación saca lo financiado por `carried_out` y queda
 * saldada; cada cuota vive en una fila de arrastre que apunta a ella. Es el
 * mismo camino por el que ya sale el faltante de un abono parcial, así que la
 * ecuación de la factura (C1), la coherencia del arrastre (C13), anular,
 * borrar y «marcar como no pagada» funcionan sin una regla nueva.
 *
 * El modo lo elige el asesor en cada orden (decisión del 2026-10-11): una sola
 * factura al mes, o una factura aparte por cuota.
 *
 * Todo nullable: las órdenes existentes no tienen plan y siguen igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_installations', function (Blueprint $table) {
            if (!Schema::hasColumn('customer_installations', 'installments_mode')) {
                $table->string('installments_mode', 20)->nullable();
            }
            if (!Schema::hasColumn('customer_installations', 'installments_count')) {
                $table->unsignedSmallInteger('installments_count')->nullable();
            }
            if (!Schema::hasColumn('customer_installations', 'installments_first_due_date')) {
                $table->date('installments_first_due_date')->nullable();
            }
        });

        Schema::table('invoice_carryovers', function (Blueprint $table) {
            if (!Schema::hasColumn('invoice_carryovers', 'installment_number')) {
                $table->unsignedSmallInteger('installment_number')->nullable();
            }
            if (!Schema::hasColumn('invoice_carryovers', 'installment_count')) {
                $table->unsignedSmallInteger('installment_count')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoice_carryovers', function (Blueprint $table) {
            $cols = array_values(array_filter(
                ['installment_number', 'installment_count'],
                fn (string $c) => Schema::hasColumn('invoice_carryovers', $c)
            ));
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('customer_installations', function (Blueprint $table) {
            $cols = array_values(array_filter(
                ['installments_mode', 'installments_count', 'installments_first_due_date'],
                fn (string $c) => Schema::hasColumn('customer_installations', $c)
            ));
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
