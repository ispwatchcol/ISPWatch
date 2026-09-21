<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Lista los equipos que son el mismo pero están escritos distinto (KAN-100 · P-44).
 *
 * Existe porque la migración que sella la unicidad por tenant —índice único
 * sobre `LOWER(serial)`— ABORTA si encuentra duplicados, y abortar sin decir
 * cuáles obligaría a buscarlos a ciegas contra producción. Con esto se miran
 * antes, se deciden, y sólo entonces se migra.
 *
 * Cuenta con `COUNT(*)` real y no con las estimaciones de `pg_stat_user_tables`:
 * `n_live_tup` ya produjo dos falsos positivos en la auditoría del 2026-07-30,
 * uno de ellos declarando «vacía» una tabla con filas.
 */
class ReportDuplicateInventoryIdentifiers extends Command
{
    protected $signature = 'inventory:duplicate-identifiers
                            {--tenant= : Limitar a un tenant}';

    protected $description = 'Lista seriales y MAC repetidos dentro de un tenant ignorando mayúsculas (KAN-100)';

    public function handle(): int
    {
        $total = 0;

        foreach (['serial', 'mac'] as $columna) {
            $duplicados = $this->duplicates($columna);

            if ($duplicados->isEmpty()) {
                $this->info("✓ Sin duplicados de {$columna}.");
                continue;
            }

            $total += $duplicados->count();

            $this->warn("Duplicados de {$columna}: {$duplicados->count()}");
            $this->table(
                ['tenant_id', 'valor (en minúsculas)', 'filas', 'ids'],
                $duplicados->map(fn ($fila) => [
                    $fila->tenant_id,
                    $fila->valor,
                    $fila->total,
                    $this->idsOf($columna, (int) $fila->tenant_id, (string) $fila->valor),
                ])->all()
            );
        }

        if ($total === 0) {
            $this->info('');
            $this->info('Nada que decidir: la migración del índice único puede aplicarse.');

            return self::SUCCESS;
        }

        $this->info('');
        $this->warn('Decide cuál fila se queda con cada valor antes de migrar. La migración');
        $this->warn('2026_09_21_000001 aborta mientras queden duplicados — a propósito: son');
        $this->warn('equipos reales y normalizarlos a ciegas puede borrar el rastro del que');
        $this->warn('de verdad está instalado en casa de un cliente.');

        // Código de salida 1 para que un script lo detecte sin leer el texto.
        return self::FAILURE;
    }

    /** Agrupa por tenant + valor en minúsculas y deja sólo lo repetido. */
    private function duplicates(string $columna)
    {
        return DB::table('inventory_device')
            ->selectRaw("tenant_id, LOWER({$columna}) AS valor, COUNT(*) AS total")
            ->whereNotNull($columna)
            ->where($columna, '<>', '')
            ->when($this->option('tenant'), fn ($q, $tenant) => $q->where('tenant_id', (int) $tenant))
            ->groupByRaw("tenant_id, LOWER({$columna})")
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('tenant_id')
            ->get();
    }

    /**
     * Los ids en conflicto. Una consulta por grupo duplicado —no por fila—:
     * son pocos por definición, y `string_agg`/`group_concat` no se escriben
     * igual en PostgreSQL y en SQLite.
     */
    private function idsOf(string $columna, int $tenantId, string $valor): string
    {
        return DB::table('inventory_device')
            ->where('tenant_id', $tenantId)
            ->whereRaw("LOWER({$columna}) = ?", [$valor])
            ->orderBy('id')
            ->pluck('id')
            ->implode(', ');
    }
}
