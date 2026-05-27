<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The original migration declared a FK on customer_id with ON DELETE CASCADE.
        // PostgreSQL allows dropping NOT NULL while keeping the FK intact; the FK
        // just won't be enforced for NULL rows, which is what we want for
        // documents that belong to a prospect-only installation.
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE customer_documents ALTER COLUMN customer_id DROP NOT NULL');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE customer_documents MODIFY customer_id BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE customer_documents ALTER COLUMN customer_id SET NOT NULL');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE customer_documents MODIFY customer_id BIGINT UNSIGNED NOT NULL');
        }
    }
};
