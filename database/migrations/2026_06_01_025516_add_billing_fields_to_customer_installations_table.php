<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columnas del bloque de cartera/facturación para customer_installations.
     * Todas son nullable para compatibilidad con registros existentes.
     * No se usa ->after() porque PostgreSQL no soporta reordenamiento de columnas.
     */
    private const BILLING_COLUMNS = [
        'payment_agreement',
        'installation_cost',
        'additional_charges',
        'discount',
        'discount_reason',
        'payment_method',
        'payment_received',
        'payment_notes',
        'customer_retention',
        'special_attention',
        'promotion_notes',
    ];

    public function up(): void
    {
        Schema::table('customer_installations', function (Blueprint $table) {
            if (!Schema::hasColumn('customer_installations', 'payment_agreement')) {
                $table->boolean('payment_agreement')->nullable();
            }
            if (!Schema::hasColumn('customer_installations', 'installation_cost')) {
                $table->decimal('installation_cost', 12, 2)->nullable();
            }
            if (!Schema::hasColumn('customer_installations', 'additional_charges')) {
                $table->decimal('additional_charges', 12, 2)->nullable();
            }
            if (!Schema::hasColumn('customer_installations', 'discount')) {
                $table->decimal('discount', 12, 2)->nullable();
            }
            if (!Schema::hasColumn('customer_installations', 'discount_reason')) {
                $table->string('discount_reason', 255)->nullable();
            }
            if (!Schema::hasColumn('customer_installations', 'payment_method')) {
                $table->string('payment_method', 50)->nullable();
            }
            if (!Schema::hasColumn('customer_installations', 'payment_received')) {
                $table->decimal('payment_received', 12, 2)->nullable();
            }
            if (!Schema::hasColumn('customer_installations', 'payment_notes')) {
                $table->text('payment_notes')->nullable();
            }
            if (!Schema::hasColumn('customer_installations', 'customer_retention')) {
                $table->boolean('customer_retention')->nullable();
            }
            if (!Schema::hasColumn('customer_installations', 'special_attention')) {
                $table->boolean('special_attention')->nullable();
            }
            if (!Schema::hasColumn('customer_installations', 'promotion_notes')) {
                $table->text('promotion_notes')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_installations', function (Blueprint $table) {
            $existing = array_filter(
                self::BILLING_COLUMNS,
                fn(string $col) => Schema::hasColumn('customer_installations', $col)
            );

            if (!empty($existing)) {
                $table->dropColumn(array_values($existing));
            }
        });
    }
};
