<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixSequences extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:fix-sequences {--table= : Specific table to fix} {--all : Fix all tables}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset PostgreSQL sequences to match the maximum ID in each table';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $driver = config('database.default');

        if ($driver !== 'pgsql') {
            $this->error('This command only works with PostgreSQL databases.');
            return 1;
        }

        $tables = [];

        if ($this->option('table')) {
            $tables = [$this->option('table')];
        } elseif ($this->option('all')) {
            // Get all tables with 'id' column
            $tables = $this->getAllTablesWithIdColumn();
        } else {
            // Interactive mode: ask user which tables to fix
            $tables = $this->getAllTablesWithIdColumn();

            $this->info('📋 Tables with ID sequences:');
            foreach ($tables as $index => $table) {
                $maxId = DB::table($table)->max('id') ?? 0;
                $currentSeq = $this->getCurrentSequenceValue($table);
                $status = $currentSeq <= $maxId ? '⚠️ NEEDS FIX' : '✅ OK';
                $this->line("  [{$index}] {$table} (max ID: {$maxId}, sequence: {$currentSeq}) {$status}");
            }

            $selection = $this->ask('Enter table numbers to fix (comma-separated), or "all" for all tables');

            if (strtolower($selection) === 'all') {
                // Keep all tables
            } else {
                $indices = array_map('trim', explode(',', $selection));
                $selectedTables = [];
                foreach ($indices as $i) {
                    if (isset($tables[(int) $i])) {
                        $selectedTables[] = $tables[(int) $i];
                    }
                }
                $tables = $selectedTables;
            }
        }

        if (empty($tables)) {
            $this->warn('No tables selected.');
            return 0;
        }

        $this->info('🔧 Fixing sequences...');

        foreach ($tables as $table) {
            $this->fixSequence($table);
        }

        $this->info('✅ Done!');
        return 0;
    }

    /**
     * Get all tables that have an 'id' column.
     */
    private function getAllTablesWithIdColumn(): array
    {
        $tables = DB::select("
            SELECT table_name 
            FROM information_schema.columns 
            WHERE column_name = 'id' 
              AND table_schema = 'public'
              AND data_type IN ('integer', 'bigint')
            ORDER BY table_name
        ");

        return array_map(fn($t) => $t->table_name, $tables);
    }

    /**
     * Get current sequence value for a table.
     */
    private function getCurrentSequenceValue(string $table): int
    {
        try {
            $result = DB::select("SELECT last_value FROM {$table}_id_seq");
            return $result[0]->last_value ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Fix the sequence for a specific table.
     */
    private function fixSequence(string $table): void
    {
        try {
            $maxId = DB::table($table)->max('id') ?? 0;
            $newValue = $maxId + 1;

            DB::statement("SELECT setval('{$table}_id_seq', {$newValue}, false)");

            $this->line("  ✅ {$table}: sequence set to {$newValue} (max ID was {$maxId})");
        } catch (\Exception $e) {
            $this->error("  ❌ {$table}: " . $e->getMessage());
        }
    }
}
