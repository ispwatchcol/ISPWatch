<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncDevFromPublic extends Command
{
    protected $signature = 'db:sync-dev
                            {--no-migrate : Skip running migrations before sync}
                            {--tables= : Comma-separated list of specific tables to sync}
                            {--yes : Skip confirmation prompt}';

    protected $description = 'Copy all data from public schema into ispwatch_dev (structure stays, data is replaced)';

    private const SKIP_TABLES = [
        'migrations',
        'spatial_ref_sys',
        'geography_columns',
        'geometry_columns',
        'raster_columns',
        'raster_overviews',
    ];

    public function handle(): int
    {
        if (config('database.default') !== 'pgsql') {
            $this->error('This command only works with PostgreSQL.');
            return 1;
        }

        $this->warn('⚠️  This will REPLACE all data in ispwatch_dev with data from public.');
        if (!$this->option('yes') && !$this->confirm('¿Continuar?')) {
            $this->info('Abortado.');
            return 0;
        }

        // 1. Sync structure first
        if (!$this->option('no-migrate')) {
            $this->info('');
            $this->info('▶ Syncing schema structure (migrate:both)...');
            $this->call('migrate:both', ['--force' => true]);
        }

        // 2. Discover & sort tables
        $allTables = $this->option('tables')
            ? array_map('trim', explode(',', $this->option('tables')))
            : $this->getPublicTables();

        if (empty($allTables)) {
            $this->warn('No tables found in public schema.');
            return 0;
        }

        $sorted = $this->topologicalSort($allTables);

        $this->info('');
        $this->info('▶ Copying data (' . count($sorted) . ' tables)...');

        // 3. DELETE in reverse topo order (children first, parents last)
        foreach (array_reverse($sorted) as $table) {
            try {
                DB::statement("DELETE FROM ispwatch_dev.\"{$table}\"");
            } catch (\Throwable $e) {
                $this->line("  <fg=yellow>WARN</> DELETE {$table}: " . $e->getMessage());
            }
        }

        // 4. INSERT in forward topo order (parents first, children last)
        //    Retry pending tables up to 3 extra passes to resolve any remaining FK ordering issues.
        $pending = $sorted;
        $errors  = [];

        for ($pass = 1; $pass <= 4 && !empty($pending); $pass++) {
            $stillFailing = [];

            foreach ($pending as $table) {
                try {
                    $commonCols = $this->getCommonColumns('public', 'ispwatch_dev', $table);

                    if (empty($commonCols)) {
                        $this->line("  <fg=yellow>SKIP</> {$table} — not found in ispwatch_dev");
                        continue;
                    }

                    $colList = implode(', ', array_map(fn($c) => "\"{$c}\"", $commonCols));

                    DB::statement(
                        "INSERT INTO ispwatch_dev.\"{$table}\" ({$colList})
                         SELECT {$colList} FROM public.\"{$table}\""
                    );

                    $count = DB::selectOne("SELECT COUNT(*) AS n FROM ispwatch_dev.\"{$table}\"")->n;
                    $prefix = $pass > 1 ? " (pass {$pass})" : '';
                    $this->line("  <fg=green>OK</>    {$table} ({$count} rows){$prefix}");
                } catch (\Throwable $e) {
                    $stillFailing[] = $table;
                    if ($pass === 4) {
                        $errors[] = $table;
                        $this->line("  <fg=red>ERROR</> {$table}: " . $e->getMessage());
                    }
                }
            }

            $pending = $stillFailing;
        }

        // 5. Fix sequences
        $this->info('');
        $this->info('▶ Fixing sequences in ispwatch_dev...');
        $this->fixSequencesForSchema('ispwatch_dev');

        // Summary
        $ok = count($sorted) - count($errors);
        $this->info('');
        $this->info("✅ Done — {$ok} OK" . (count($errors) ? ', ' . count($errors) . ' errors' : '') . '.');

        if (!empty($errors)) {
            $this->warn('Tables with errors: ' . implode(', ', $errors));
            return 1;
        }

        return 0;
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    private function getPublicTables(): array
    {
        $skip = "'" . implode("','", self::SKIP_TABLES) . "'";
        $rows = DB::select("
            SELECT tablename FROM pg_tables
            WHERE schemaname = 'public'
              AND tablename NOT IN ({$skip})
            ORDER BY tablename
        ");
        return array_map(fn($r) => $r->tablename, $rows);
    }

    /**
     * Kahn's algorithm topological sort based on FK deps inside ispwatch_dev.
     * Tables with no deps come first (parents), dependents come last.
     */
    private function topologicalSort(array $tables): array
    {
        $tableSet = array_flip($tables);

        // FK edges via pg_constraint (more reliable than information_schema joins)
        $rows = DB::select("
            SELECT
                child_cls.relname  AS child,
                parent_cls.relname AS parent
            FROM pg_constraint  c
            JOIN pg_class       child_cls  ON c.conrelid  = child_cls.oid
            JOIN pg_namespace   child_ns   ON child_cls.relnamespace = child_ns.oid
            JOIN pg_class       parent_cls ON c.confrelid = parent_cls.oid
            JOIN pg_namespace   parent_ns  ON parent_cls.relnamespace = parent_ns.oid
            WHERE c.contype = 'f'
              AND child_ns.nspname  = 'ispwatch_dev'
              AND parent_ns.nspname = 'ispwatch_dev'
              AND child_cls.relname != parent_cls.relname
        ");

        // inDegree[table] = number of parents it depends on (within our set)
        $inDegree  = array_fill_keys($tables, 0);
        $children  = array_fill_keys($tables, []);  // parent → [children]

        foreach ($rows as $row) {
            if (!isset($tableSet[$row->child]) || !isset($tableSet[$row->parent])) {
                continue;
            }
            $inDegree[$row->child]++;
            $children[$row->parent][] = $row->child;
        }

        // Queue tables with no dependencies first
        $queue  = array_keys(array_filter($inDegree, fn($d) => $d === 0));
        $sorted = [];

        while (!empty($queue)) {
            $node = array_shift($queue);
            $sorted[] = $node;
            foreach (array_unique($children[$node] ?? []) as $child) {
                $inDegree[$child]--;
                if ($inDegree[$child] === 0) {
                    $queue[] = $child;
                }
            }
        }

        // Any remaining tables (cycles or not in FK graph) — append them
        $remaining = array_diff($tables, $sorted);
        return array_merge($sorted, array_values($remaining));
    }

    private function getCommonColumns(string $schemaA, string $schemaB, string $table): array
    {
        $rows = DB::select("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = ? AND table_name = ?
            INTERSECT
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = ? AND table_name = ?
            ORDER BY column_name
        ", [$schemaA, $table, $schemaB, $table]);

        return array_map(fn($r) => $r->column_name, $rows);
    }

    private function fixSequencesForSchema(string $schema): void
    {
        $tables = DB::select("
            SELECT table_name
            FROM information_schema.columns
            WHERE column_name = 'id'
              AND table_schema = ?
              AND data_type IN ('integer', 'bigint')
            ORDER BY table_name
        ", [$schema]);

        foreach ($tables as $row) {
            $table = $row->table_name;
            try {
                $maxId = DB::selectOne("SELECT COALESCE(MAX(id), 0) AS m FROM {$schema}.\"{$table}\"")->m;
                $next  = $maxId + 1;
                DB::statement("SELECT setval('{$schema}.{$table}_id_seq', {$next}, false)");
                $this->line("  seq {$table}_id_seq → {$next}");
            } catch (\Throwable $e) {
                // No serial sequence for this table — skip silently
            }
        }
    }
}
