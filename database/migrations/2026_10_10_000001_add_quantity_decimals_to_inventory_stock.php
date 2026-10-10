<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuántos decimales admite la cantidad de un producto «por cantidad».
 *
 * `unit` es sólo una etiqueta y por eso no podía decidir nada: un RJ45 y un
 * metro de fibra se aceptaban igual con 0,37. El cable sí se gasta en
 * fracciones (12,5 m); un conector no se parte. El producto declara cuál es su
 * caso y el ledger lo exige en TODA entrada, traspaso y consumo.
 *
 *   0 → piezas indivisibles: sólo enteros.
 *   1 / 2 → hasta ese número de decimales. Nunca más de 2: los saldos y el
 *          kardex son decimal(12,2) y un tercer decimal se redondearía en
 *          silencio, que es una diferencia que nadie puede explicar después.
 *
 * Default 2: es lo que el sistema aceptaba hasta hoy, y un producto existente
 * no puede empezar a rechazar cantidades de la noche a la mañana. Sólo se pasan
 * a 0 los que dicen ser piezas (unidad vacía o «unidad», «und», «pieza»…) y que
 * NO tienen ninguna cantidad fraccionaria ni en saldos ni en el kardex: si ya
 * tienen fracciones, alguien las usa así y lo decide un administrador, no esta
 * migración. Los serializados no usan este dato (siempre 1 por unidad).
 */
return new class extends Migration
{
    private const PIEZAS = ['', 'unidad', 'unidades', 'und', 'und.', 'un', 'u', 'pieza', 'piezas', 'pza', 'pzas', 'pz'];

    public function up(): void
    {
        Schema::table('inventory_stock', function (Blueprint $table) {
            $table->unsignedTinyInteger('quantity_decimals')->default(2);
        });

        $fraccionarios = DB::table('inventory_balances')
            ->whereRaw('quantity <> CAST(quantity AS INTEGER)')
            ->pluck('stock_id')
            ->merge(
                DB::table('inventory_movements')
                    ->whereNotNull('stock_id')
                    ->whereRaw('quantity <> CAST(quantity AS INTEGER)')
                    ->pluck('stock_id')
            )
            ->unique()
            ->all();

        DB::table('inventory_stock')
            ->where('is_serialized', false)
            ->where(function ($q) {
                $q->whereNull('unit')
                    ->orWhereIn(DB::raw('LOWER(TRIM(unit))'), self::PIEZAS);
            })
            ->when($fraccionarios, fn ($q) => $q->whereNotIn('id', $fraccionarios))
            ->update(['quantity_decimals' => 0]);
    }

    public function down(): void
    {
        Schema::table('inventory_stock', function (Blueprint $table) {
            $table->dropColumn('quantity_decimals');
        });
    }
};
