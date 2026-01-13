<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

/**
 * Trait FixesSequences
 * 
 * Provides automatic PostgreSQL sequence fixing when duplicate key errors occur.
 * Use this trait in controllers to automatically recover from sequence desync issues.
 */
trait FixesSequences
{
    /**
     * Fix PostgreSQL sequence for a specific table.
     * Sets the sequence to max(id) + 1.
     *
     * @param string $table The table name
     * @return void
     */
    protected function fixSequence(string $table): void
    {
        if (config('database.default') !== 'pgsql') {
            return; // Only applies to PostgreSQL
        }

        try {
            $maxId = DB::table($table)->max('id') ?? 0;
            $newValue = $maxId + 1;
            DB::statement("SELECT setval('{$table}_id_seq', {$newValue}, false)");
        } catch (\Exception $e) {
            // Log silently, don't break the request
            \Log::warning("Failed to fix sequence for {$table}: " . $e->getMessage());
        }
    }

    /**
     * Execute a create operation with automatic sequence fixing.
     * If a duplicate key error occurs, fixes the sequence and retries once.
     *
     * @param string $modelClass The Eloquent model class (e.g., User::class)
     * @param array $data The data to create
     * @return \Illuminate\Database\Eloquent\Model
     * @throws QueryException If the error persists after retry
     */
    protected function createWithSequenceFix(string $modelClass, array $data)
    {
        try {
            return $modelClass::create($data);
        } catch (QueryException $e) {
            // Check if it's a duplicate key error (sequence out of sync)
            if ($this->isDuplicateKeyError($e)) {
                // Get table name from model
                $table = (new $modelClass)->getTable();
                $this->fixSequence($table);

                // Retry the create
                return $modelClass::create($data);
            }

            throw $e;
        }
    }

    /**
     * Check if the exception is a duplicate key error.
     *
     * @param QueryException $e
     * @return bool
     */
    protected function isDuplicateKeyError(QueryException $e): bool
    {
        $message = $e->getMessage();
        return str_contains($message, 'duplicate key')
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'Duplicate entry');
    }
}
