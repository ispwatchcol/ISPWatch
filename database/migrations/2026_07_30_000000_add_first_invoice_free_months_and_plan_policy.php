<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generaliza la "primera factura" en dos ejes independientes:
 *
 *   1. QUÉ se cobra el mes en que el cliente se instala  → first_invoice_mode
 *      (none | prorated | full — ya existía en billing y customer_profile)
 *   2. CUÁNTOS meses siguientes van de cortesía          → first_invoice_free_months
 *
 * El caso que lo motiva: un plan cuya instalación incluye el mes siguiente.
 * Cliente instalado el 16 de julio → paga el prorrateo del 16 al 31 de julio,
 * AGOSTO va en cero (ya lo cubrió lo que pagó de instalación) y septiembre
 * vuelve al cobro normal. Con free_months = 2, 3… se arman promociones más
 * largas sin tocar código.
 *
 * Ambos ejes se resuelven en cascada CLIENTE → PLAN → ROUTER: el plan es el
 * nivel nuevo, porque la promoción suele ser una característica del producto
 * que se vende ("plan Hogar 100M: instalación con mes de regalo") y no algo
 * que el operador deba recordar marcar cliente por cliente.
 *
 *   service_plan.first_invoice_mode          → null = hereda del router
 *   service_plan.first_invoice_free_months   → null = hereda del router
 *   customer_profile.first_invoice_free_months → null = hereda del plan
 *   billing.first_invoice_free_months        → default del router (0)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('billing', 'first_invoice_free_months')) {
            Schema::table('billing', function (Blueprint $table) {
                $table->unsignedSmallInteger('first_invoice_free_months')
                    ->default(0)
                    ->after('first_invoice_policy');
            });
        }

        if (!Schema::hasColumn('customer_profile', 'first_invoice_free_months')) {
            Schema::table('customer_profile', function (Blueprint $table) {
                $table->unsignedSmallInteger('first_invoice_free_months')
                    ->nullable()
                    ->after('first_invoice_mode');
            });
        }

        if (!Schema::hasColumn('service_plan', 'first_invoice_mode')) {
            Schema::table('service_plan', function (Blueprint $table) {
                $table->string('first_invoice_mode', 16)->nullable();
            });
        }

        if (!Schema::hasColumn('service_plan', 'first_invoice_free_months')) {
            Schema::table('service_plan', function (Blueprint $table) {
                $table->unsignedSmallInteger('first_invoice_free_months')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('billing', 'first_invoice_free_months')) {
            Schema::table('billing', function (Blueprint $table) {
                $table->dropColumn('first_invoice_free_months');
            });
        }

        if (Schema::hasColumn('customer_profile', 'first_invoice_free_months')) {
            Schema::table('customer_profile', function (Blueprint $table) {
                $table->dropColumn('first_invoice_free_months');
            });
        }

        foreach (['first_invoice_mode', 'first_invoice_free_months'] as $column) {
            if (Schema::hasColumn('service_plan', $column)) {
                Schema::table('service_plan', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
