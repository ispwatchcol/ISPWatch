<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El mismo «no se le cobra al cliente», en la otra puerta por la que entra una
 * visita: el ticket de soporte.
 *
 *   no_charge         boolean NOT NULL default false
 *   no_charge_reason  varchar(255) NULL
 *
 * POR QUÉ TAMBIÉN AQUÍ, SI EL CARGO DEL TICKET YA ERA OPCIONAL
 *
 * Que el interruptor «Cargo Asociado» venga apagado al crear el ticket no
 * decide nada: `POST /support/{id}/charge` sigue abierto el resto de la vida
 * del ticket, y cualquiera que lo pueda usar puede facturar mañana la visita
 * que el técnico dio por regalada hoy. Apagado no es lo mismo que prohibido.
 * Esta columna es lo segundo: con ella puesta, el endpoint de cargo responde
 * 422 y la pantalla no ofrece el formulario.
 *
 * Misma forma exacta que en `customer_installations` —mismos nombres, mismo
 * default, mismo motivo opcional— porque es la misma decisión de negocio vista
 * desde dos módulos. Nombrarla distinto en cada tabla obligaría a traducir en
 * cada informe que cruce las dos.
 */
return new class extends Migration
{
    private const TABLA = 'support_ticket';

    public function up(): void
    {
        Schema::table(self::TABLA, function (Blueprint $table) {
            if (!Schema::hasColumn(self::TABLA, 'no_charge')) {
                $table->boolean('no_charge')->default(false);
            }

            if (!Schema::hasColumn(self::TABLA, 'no_charge_reason')) {
                $table->string('no_charge_reason', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table(self::TABLA, function (Blueprint $table) {
            $existentes = array_values(array_filter(
                ['no_charge', 'no_charge_reason'],
                fn (string $col) => Schema::hasColumn(self::TABLA, $col)
            ));

            if ($existentes !== []) {
                $table->dropColumn($existentes);
            }
        });
    }
};
