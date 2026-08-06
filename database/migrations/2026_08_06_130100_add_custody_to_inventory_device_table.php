<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un equipo pasa a saber DÓNDE está, no sólo a quién se le asignó alguna vez.
 *
 * La tabla ya tenía user_id ("asignado a") y branch_id ("sucursal"), pero sin
 * un estado que dijera cuál de los dos manda: un equipo con las dos columnas
 * llenas podía estar en la bodega o en la mochila del técnico y nadie sabía
 * cuál. Peor: no había forma de decir "ya está instalado en casa del cliente",
 * así que el equipo seguía apareciendo como disponible para siempre.
 *
 * status es el árbitro y define qué columna es el custodio real:
 *
 *   stock     → está en la sucursal branch_id (o sin ubicar si es NULL)
 *   assigned  → lo tiene el usuario user_id; branch_id queda como procedencia
 *   installed → está instalado en casa de customer_id
 *   retired   → dado de baja (dañado, perdido, devuelto al proveedor)
 *
 * El backfill respeta lo que ya significaba la tabla: lo que tenía "asignado a"
 * queda en assigned, lo demás en stock. Ningún equipo existente cambia de dueño.
 *
 * customer_id apunta a users.id —igual que invoices.customer_id— y es SET NULL:
 * si se borra el cliente el equipo no debe desaparecer, sólo dejar de tener a
 * quién apuntar. Un equipo instalado que se queda huérfano se ve en el kardex.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_device', function (Blueprint $table) {
            $table->string('status', 20)->default('stock');
            $table->unsignedBigInteger('customer_id')->nullable();

            $table->foreign('customer_id')->references('id')->on('users')->nullOnDelete();

            // Las dos consultas calientes: "qué tiene Juan" y "qué hay en bodega".
            $table->index(['tenant_id', 'status', 'user_id']);
            $table->index(['tenant_id', 'status', 'branch_id']);
        });

        // Lo que hoy tiene custodio asignado ya estaba, de hecho, fuera de bodega.
        DB::table('inventory_device')->whereNotNull('user_id')->update(['status' => 'assigned']);
    }

    public function down(): void
    {
        Schema::table('inventory_device', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropIndex(['tenant_id', 'status', 'user_id']);
            $table->dropIndex(['tenant_id', 'status', 'branch_id']);
            $table->dropColumn(['status', 'customer_id']);
        });
    }
};
