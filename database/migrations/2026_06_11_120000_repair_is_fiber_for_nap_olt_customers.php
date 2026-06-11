<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reparación de datos: clientes que son de fibra pero quedaron con is_fiber=false.
 *
 * Algunos clientes tienen una caja NAP en sectorial_id (sectorial.element_type='nap')
 * y/o una OLT en olt_id, pero la columna is_fiber quedó en false (registros previos a
 * la columna, imports, o guardados que no persistieron el flag). En la vista Editar
 * Cliente eso hacía que el toggle "¿Es fibra?" saliera apagado y la caja asignada
 * quedara fuera de la lista (la lista inalámbrica excluye NAPs), apareciendo vacía.
 *
 * Aquí se marca is_fiber=true para todo cliente con OLT asignada o cuyo sectorial sea
 * una caja NAP. La condición es universalmente correcta (no requiere scope por tenant).
 * Idempotente y portable a sqlite (en tests afecta 0 filas → no-op).
 *
 * down(): no-op. No se puede revertir con seguridad (no sabemos qué filas eran false
 * legítimamente antes), y volver a false rompería el dato recién corregido.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('customer_profile')
            ->where('is_fiber', false)
            ->where(function ($q) {
                $q->whereNotNull('olt_id')
                  ->orWhereIn('sectorial_id', function ($sub) {
                      $sub->select('id')
                          ->from('sectorial')
                          ->where('element_type', 'nap');
                  });
            })
            ->update(['is_fiber' => true]);
    }

    public function down(): void
    {
        // Intencionalmente vacío: la corrección no es reversible de forma segura.
    }
};
