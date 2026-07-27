<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            throw new \RuntimeException("Salesguard: Tests are running on '" . config('database.default') . "' database! Aborting to prevent data loss. Please ensure .env.testing exists and is being used.");
        }

        // Belt-and-suspenders: config('database.default') === 'sqlite' only proves
        // the connection NAME is sqlite, not that it actually behaves like sqlite.
        // Illuminate\Support\ConfigurationUrlParser lets a stray DB_URL silently
        // override a named connection's driver/host — this is exactly what almost
        // sent a stray script to the real Supabase database during development.
        // Assert the resolved driver and database path too, so this guard can't
        // be fooled by a similar misconfiguration creeping back in.
        $connection = \Illuminate\Support\Facades\DB::connection();

        if ($connection->getDriverName() !== 'sqlite') {
            throw new \RuntimeException(
                "Safeguard: connection 'sqlite' resolved to driver '{$connection->getDriverName()}' instead of sqlite "
                . '(likely a DB_URL override) — aborting to prevent hitting a real database.'
            );
        }

        if ($connection->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException(
                "Safeguard: sqlite connection is using database '{$connection->getDatabaseName()}' instead of ':memory:' "
                . '— aborting to prevent tests from touching a real sqlite file.'
            );
        }
    }
}
