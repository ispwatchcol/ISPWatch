<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `inventory_branch.numero` era `integer` (int4, tope 2.147.483.647).
 *
 * Todo celular colombiano son diez dígitos que empiezan por 3 — 3001234567 es
 * 3.001.234.567 — así que CUALQUIERA de ellos desborda la columna. El campo se
 * llama "Número" en la interfaz y la gente escribe ahí el teléfono de la
 * sucursal, con lo que guardar reventaba con SQLSTATE[22003] y el usuario sólo
 * veía "No se pudo guardar".
 *
 * La validación tampoco lo atrapaba: `nullable|integer` acepta ese valor en PHP
 * de 64 bits y el fallo aparecía después, en el INSERT, como un 500 opaco.
 *
 * Se pasa a texto en vez de a bigint a propósito: un teléfono no es una
 * cantidad. Como texto admite indicativo (+57), ceros a la izquierda,
 * separadores y extensiones. Nada ordena ni compara este campo — sólo se
 * muestra en la pantalla de Sucursales — así que el cambio es seguro.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // PostgreSQL no castea int -> varchar solo: hay que decirle cómo.
            DB::statement('ALTER TABLE inventory_branch ALTER COLUMN numero TYPE varchar(30) USING numero::varchar');

            return;
        }

        Schema::table('inventory_branch', function (Blueprint $table) {
            $table->string('numero', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // La vuelta atrás es necesariamente con pérdida: lo que no quepa en
            // un int4 se anula en vez de reventar la migración. El patrón de 1 a
            // 9 dígitos garantiza que el valor entra sin desbordar.
            DB::statement(
                "ALTER TABLE inventory_branch ALTER COLUMN numero TYPE integer "
                . "USING CASE WHEN numero ~ '^[0-9]{1,9}$' THEN numero::integer ELSE NULL END"
            );

            return;
        }

        Schema::table('inventory_branch', function (Blueprint $table) {
            $table->integer('numero')->nullable()->change();
        });
    }
};
