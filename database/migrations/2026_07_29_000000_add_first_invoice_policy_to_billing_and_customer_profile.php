<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Primera factura" — qué hacer con un cliente cuyo servicio arranca a mitad
 * del periodo (instalación el día 20 de un mes que ya se facturó el día 1).
 *
 * Antes: la generación mensual sólo miraba "hoy >= día de creación", así que
 * cualquier cliente cargado después del día de facturación recibía una factura
 * del MES COMPLETO en la siguiente corrida horaria. El operador tenía que
 * editarla a mano (caso real: factura 00000793, plan 52.500 corregido a 10.000
 * con la nota "PRORRATEO INSTALACION").
 *
 *   billing.first_invoice_policy      → política por defecto del router
 *   customer_profile.first_invoice_mode → override por cliente (null = hereda)
 *
 * Valores: none (no cobrar este mes, empieza el próximo ciclo — por defecto),
 *          prorated (cobrar sólo los días restantes), full (mes completo).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('billing', 'first_invoice_policy')) {
            Schema::table('billing', function (Blueprint $table) {
                $table->string('first_invoice_policy', 16)
                    ->default('none')
                    ->after('billing_mode');
            });
        }

        if (!Schema::hasColumn('customer_profile', 'first_invoice_mode')) {
            Schema::table('customer_profile', function (Blueprint $table) {
                $table->string('first_invoice_mode', 16)
                    ->nullable()
                    ->after('exclude_from_billing');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('billing', 'first_invoice_policy')) {
            Schema::table('billing', function (Blueprint $table) {
                $table->dropColumn('first_invoice_policy');
            });
        }

        if (Schema::hasColumn('customer_profile', 'first_invoice_mode')) {
            Schema::table('customer_profile', function (Blueprint $table) {
                $table->dropColumn('first_invoice_mode');
            });
        }
    }
};
