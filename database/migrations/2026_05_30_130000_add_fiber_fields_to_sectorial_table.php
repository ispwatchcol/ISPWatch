<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Convierte la tabla `sectorial` (Elementos de Red) en una jerarquía capaz de
 * representar planta externa de fibra (FTTH/GPON), además del inalámbrico que
 * ya soportaba.
 *
 * Lo nuevo:
 *  - parent_id : self-FK -> arma el árbol OLT -> splitter -> NAP.
 *  - split_ratio : ratio del splitter ("1:8", "1:32") para derivar su capacidad.
 *  - ports_total : puertos físicos de una caja NAP (límite duro de clientes).
 *  - pon_port : puerto PON del OLT del que cuelga el ramal (ej. "1/1/1").
 *  - vlan : VLAN de servicio asociada al elemento.
 *
 * Los element_type de fibra (olt/splitter/nap/mufa) no requieren cambio de
 * esquema: la columna element_type es un string libre, así que solo se validan
 * en el controlador / UI.
 *
 * Nota SQLite (tests): las FK creadas con Schema::table()->foreign() se compilan
 * a vacío en SQLite, así que esto es FK real en Postgres y no-op en sqlite.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sectorial', function (Blueprint $table) {
            if (!Schema::hasColumn('sectorial', 'parent_id')) {
                $table->unsignedBigInteger('parent_id')->nullable()->after('element_type');
                $table->index('parent_id');
                $table->foreign('parent_id')->references('id')->on('sectorial')->onDelete('set null');
            }
            if (!Schema::hasColumn('sectorial', 'split_ratio')) {
                $table->string('split_ratio', 10)->nullable()->after('type')
                    ->comment('Ratio del splitter, ej. 1:8 / 1:32');
            }
            if (!Schema::hasColumn('sectorial', 'ports_total')) {
                $table->unsignedSmallInteger('ports_total')->nullable()->after('split_ratio')
                    ->comment('Puertos físicos de la caja NAP');
            }
            if (!Schema::hasColumn('sectorial', 'pon_port')) {
                $table->string('pon_port', 50)->nullable()->after('ports_total')
                    ->comment('Puerto PON del OLT (ej. 1/1/1)');
            }
            if (!Schema::hasColumn('sectorial', 'vlan')) {
                $table->integer('vlan')->nullable()->after('pon_port')
                    ->comment('VLAN de servicio');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sectorial', function (Blueprint $table) {
            if (Schema::hasColumn('sectorial', 'parent_id')) {
                // dropForeign es no-op en sqlite; en Postgres elimina la FK real.
                try {
                    $table->dropForeign(['parent_id']);
                } catch (\Throwable $e) {
                    // La FK puede no existir (sqlite); ignorar.
                }
                $table->dropColumn('parent_id');
            }
            foreach (['split_ratio', 'ports_total', 'pon_port', 'vlan'] as $col) {
                if (Schema::hasColumn('sectorial', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
