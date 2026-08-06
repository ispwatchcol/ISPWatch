<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saldos de los modelos NO serializados: cuántos RJ45 le quedan a Juan.
 *
 * Un consumible no puede vivir en inventory_device porque no hay "un" RJ45:
 * hay 500, indistinguibles, y registrarlos uno por uno no lo haría nadie. Lo
 * que sí importa es el saldo por custodio, que es exactamente una fila aquí.
 *
 * El custodio es polimórfico (holder_type + holder_id) en lugar de dos columnas
 * branch_id/user_id nulables porque la unicidad es el corazón de esta tabla:
 * "un saldo por modelo y custodio". Con columnas nulables, PostgreSQL considera
 * distintos dos NULL y el índice único dejaría entrar saldos duplicados del
 * mismo modelo — que es justo el bug que hace que un inventario deje de cuadrar.
 * Por eso holder_id es NOT NULL y no lleva FK: apunta a inventory_branch o a
 * users según holder_type.
 *
 * Contrapartida asumida: si se borra una sucursal o un usuario, su saldo queda
 * huérfano en vez de irse con él. Es preferible a perder existencias en
 * silencio — un saldo huérfano se ve y se traspasa; uno borrado no se recupera.
 *
 * quantity es decimal, no entero: el cable se lleva en metros y 12,5 m es un
 * consumo real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('stock_id');
            $table->string('holder_type', 20);           // branch | user
            $table->unsignedBigInteger('holder_id');
            $table->decimal('quantity', 12, 2)->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('cascade');
            $table->foreign('stock_id')->references('id')->on('inventory_stock')->onDelete('cascade');

            $table->unique(['tenant_id', 'stock_id', 'holder_type', 'holder_id'], 'inventory_balances_holder_unique');
            $table->index(['tenant_id', 'holder_type', 'holder_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_balances');
    }
};
