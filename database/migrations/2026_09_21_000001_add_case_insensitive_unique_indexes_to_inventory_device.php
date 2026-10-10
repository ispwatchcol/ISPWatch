<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Unicidad real de serial y MAC por tenant, sin distinguir mayúsculas
 * (KAN-100 · P-44).
 *
 * Hasta aquí, `serial` y `mac` eran «únicos por tenant» sólo de palabra: la
 * regla `unique` del formulario compara con `=`, que en PostgreSQL distingue
 * mayúsculas, así que `SN-001` y `sn-001` convivían como dos equipos. La carga
 * masiva, que compara en minúsculas, los veía como uno y rechazaba el archivo
 * entero — sin que nadie entendiera por qué, porque el alta uno por uno había
 * «funcionado».
 *
 * El índice es FUNCIONAL (`LOWER(...)`) y PARCIAL: no toca las filas sin
 * serial o sin MAC, que son legítimas —un rollo de cable no tiene serial— y
 * que un índice único normal habría dejado con un solo NULL por tenant.
 *
 * ABORTA SI YA HAY DUPLICADOS, a propósito. Son equipos reales: decidir cuál
 * de las dos filas se queda con el valor es una decisión de inventario, no de
 * una migración, y normalizarlos a ciegas puede borrar el rastro del que de
 * verdad está instalado en casa de un cliente. Para verlos antes de migrar:
 *
 *     php artisan inventory:duplicate-identifiers
 *
 * La sintaxis de índice funcional + parcial es la misma en PostgreSQL y en
 * SQLite (3.9+), así que no hay ramas por motor: la suite corre sobre SQLite y
 * debe ejercitar exactamente el mismo índice que producción.
 */
return new class extends Migration
{
    private const COLUMNAS = [
        'serial' => 'inventory_device_tenant_serial_ci_unique',
        'mac'    => 'inventory_device_tenant_mac_ci_unique',
    ];

    public function up(): void
    {
        foreach (self::COLUMNAS as $columna => $indice) {
            $this->abortIfDuplicates($columna);

            DB::statement("
                CREATE UNIQUE INDEX IF NOT EXISTS {$indice}
                ON inventory_device (tenant_id, LOWER({$columna}))
                WHERE {$columna} IS NOT NULL AND {$columna} <> ''
            ");
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNAS as $indice) {
            DB::statement("DROP INDEX IF EXISTS {$indice}");
        }
    }

    /**
     * `COUNT(*)` real y no estimaciones del catálogo: `n_live_tup` ya dio dos
     * falsos positivos en la auditoría del 2026-07-30.
     */
    private function abortIfDuplicates(string $columna): void
    {
        $duplicados = DB::table('inventory_device')
            ->selectRaw("tenant_id, LOWER({$columna}) AS valor, COUNT(*) AS total")
            ->whereNotNull($columna)
            ->where($columna, '<>', '')
            ->groupByRaw("tenant_id, LOWER({$columna})")
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('tenant_id')
            ->get();

        if ($duplicados->isEmpty()) {
            return;
        }

        $detalle = $duplicados
            ->take(20)
            ->map(fn ($fila) => "tenant_id={$fila->tenant_id} {$columna}=\"{$fila->valor}\" x{$fila->total}")
            ->implode(' | ');

        throw new \RuntimeException(
            "No se puede sellar la unicidad de {$columna}: ya existen valores repetidos que sólo se "
            . 'diferencian en mayúsculas. Revísalos con «php artisan inventory:duplicate-identifiers», '
            . 'deja un solo equipo con cada valor y vuelve a migrar. '
            . "Grupos en conflicto ({$duplicados->count()}): {$detalle}"
        );
    }
};
