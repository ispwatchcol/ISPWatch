<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que se PREVÉ llevar a una instalación: «1 router, 30 m de cable».
 *
 * Hasta ahora el plan era un texto de 255 caracteres en
 * `customer_installations.equipment`, y el selector de la pantalla de agendar
 * sólo sabía pegar ahí el serial de un equipo concreto. El cable y los demás
 * consumibles se escribían a mano, sin cantidad ni unidad comparables con el
 * inventario.
 *
 * PLANIFICAR NO MUEVE EXISTENCIAS. Esta tabla no pasa por InventoryLedger, no
 * descuenta ni reserva, y puede pedir más de lo que hay. El consumo real sigue
 * siendo `installation_equipment`, que es lo único que descuenta y lo único que
 * deja rastro en el kardex. Por eso las dos tablas van separadas: juntarlas
 * obligaría a distinguir con una bandera qué filas mueven inventario, y esa
 * bandera es exactamente el tipo de dato que alguien acaba ignorando.
 *
 * `stock_id` es el producto del inventario del tenant. `label` y `unit` se
 * CONGELAN al planificar: si mañana alguien renombra «CABLE UTP» a «UTP CAT6»
 * o borra el producto, la orden de ayer tiene que seguir diciendo lo que se
 * planificó. Por eso `stock_id` es SET NULL y no CASCADE.
 *
 * `is_serialized` también se congela: decide si la línea se pinta como
 * «equipo» (se elige por serial al usar) o como «material» (se descuenta por
 * cantidad), y ese dato puede cambiar en el catálogo con el tiempo.
 *
 * Borrar la orden sí arrastra su plan (CASCADE): el plan no tiene efecto en el
 * inventario y no hay nada que conciliar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installation_planned_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('installation_id');
            $table->unsignedBigInteger('stock_id')->nullable();
            $table->string('label', 255);
            $table->string('unit', 20)->nullable();
            $table->boolean('is_serialized')->default(false);
            $table->decimal('quantity', 12, 2);
            $table->string('notes', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenant')->onDelete('cascade');
            $table->foreign('installation_id')->references('id')->on('customer_installations')->onDelete('cascade');
            $table->foreign('stock_id')->references('id')->on('inventory_stock')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'installation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installation_planned_items');
    }
};
