<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de unidades de medida del inventario (P-92).
 *
 * `inventory_stock.unit` era texto libre: «m», «metro» y «mts» convivían y el
 * resumen de cable sólo reconocía algunas. Ahora cada empresa tiene su lista
 * de unidades y el producto elige una de ella. `inventory_stock.unit` sigue
 * siendo la columna que leen todas las pantallas (sigue guardando el NOMBRE de
 * la unidad), así que nada de lo que ya funciona cambia de forma.
 *
 * Las unidades básicas (unidad, metro, rollo, caja, paquete) no se guardan
 * aquí: son del sistema y las agrega InventoryUnit::catalogFor().
 *
 * `decimals` es la precisión que se sugiere al crear un producto con esa
 * unidad (0 = piezas enteras, 2 = metros con centímetros). El producto conserva
 * la suya en `inventory_stock.quantity_decimals`, que es la que se exige.
 *
 * Backfill: cada unidad que ya usa algún producto y que no es básica entra al
 * catálogo de su empresa, para que el selector del formulario la muestre y un
 * producto viejo con «mts» pueda editarse sin perderla.
 */
return new class extends Migration
{
    private const BASICAS = ['unidad', 'metro', 'rollo', 'caja', 'paquete'];

    public function up(): void
    {
        Schema::create('inventory_units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 20);
            $table->unsignedTinyInteger('decimals')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('cascade');
            $table->unique(['tenant_id', 'name']);
        });

        $usadas = DB::table('inventory_stock')
            ->whereNotNull('unit')
            ->where('unit', '<>', '')
            ->where('is_serialized', false)
            ->whereNotNull('tenant_id')
            ->select('tenant_id', 'unit', DB::raw('MAX(quantity_decimals) as decimals'))
            ->groupBy('tenant_id', 'unit')
            ->get();

        $vistas = [];
        $now    = now();

        foreach ($usadas as $fila) {
            $nombre = trim((string) $fila->unit);
            $clave  = $fila->tenant_id . '|' . mb_strtolower($nombre);

            if ($nombre === '' || in_array(mb_strtolower($nombre), self::BASICAS, true) || isset($vistas[$clave])) {
                continue;
            }

            $vistas[$clave] = true;

            DB::table('inventory_units')->insert([
                'tenant_id'  => $fila->tenant_id,
                'name'       => mb_substr($nombre, 0, 20),
                'decimals'   => min(2, max(0, (int) $fila->decimals)),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_units');
    }
};
