<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->string('legal_name', 255)
                ->nullable()
                ->comment('Legal or corporate name of the Colombian company');

            $table->string('trade_name', 255)
                ->nullable()
                ->comment('Commercial or trade name of the company');

            $table->string('nit', 50)
                ->nullable()
                ->comment('Tax Identification Number (Número de Identificación Tributaria)');

            $table->string('nit_verification_digit', 5)
                ->nullable()
                ->comment('Verification digit for the NIT');

            $table->string('tax_regime', 100)
                ->nullable()
                ->comment('Tax regime classification in Colombia (e.g., Responsable de IVA, No responsable)');

            $table->string('economic_activity', 255)
                ->nullable()
                ->comment('Primary economic activity (e.g., CIIU code or description)');

            $table->string('billing_email', 255)
                ->nullable()
                ->comment('Email address for electronic billing and communications');

            $table->string('billing_phone', 50)
                ->nullable()
                ->comment('Contact phone number for billing purposes');

            $table->string('billing_address', 255)
                ->nullable()
                ->comment('Physical address for billing');

            $table->string('city', 100)
                ->nullable()
                ->comment('City of the billing address');

            $table->string('department', 100)
                ->nullable()
                ->comment('Department or state of the billing address');

            $table->string('country', 2)
                ->nullable()
                ->default('CO')
                ->comment('Two-letter country code (ISO 3166-1 alpha-2)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->dropColumn([
                'legal_name',
                'trade_name',
                'nit',
                'nit_verification_digit',
                'tax_regime',
                'economic_activity',
                'billing_email',
                'billing_phone',
                'billing_address',
                'city',
                'department',
                'country',
            ]);
        });
    }
};
