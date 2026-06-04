<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrección de datos (tenant 16): reclasificar elementos que estaban como
 * 'sectorial' a su tipo real de planta de fibra.
 *
 *   - NAP:      PON 1/2 CAJA x (ids 13-23) + CAJA DE PASO (id 25)
 *   - SPLITTER: MUFA 1 (id 24)
 *
 * Acotado por tenant_id = 16 + ids exactos para no afectar otros tenants ni
 * filas que en otra BD reutilicen esos ids. En ispwatch_dev no existen estas
 * filas, así que ahí el UPDATE afecta 0 filas (no-op). Reversible: down() los
 * regresa a 'sectorial'.
 */
return new class extends Migration {
    private const TENANT_ID = 16;
    private const NAP_IDS      = [13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 25];
    private const SPLITTER_IDS = [24];

    public function up(): void
    {
        DB::table('sectorial')
            ->where('tenant_id', self::TENANT_ID)
            ->whereIn('id', self::NAP_IDS)
            ->update(['element_type' => 'nap']);

        DB::table('sectorial')
            ->where('tenant_id', self::TENANT_ID)
            ->whereIn('id', self::SPLITTER_IDS)
            ->update(['element_type' => 'splitter']);
    }

    public function down(): void
    {
        DB::table('sectorial')
            ->where('tenant_id', self::TENANT_ID)
            ->whereIn('id', array_merge(self::NAP_IDS, self::SPLITTER_IDS))
            ->update(['element_type' => 'sectorial']);
    }
};
