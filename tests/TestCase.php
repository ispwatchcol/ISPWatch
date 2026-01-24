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
    }
}
