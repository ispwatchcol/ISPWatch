<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $from = DB::table('service_plan')
            ->whereNull('deleted_at')
            ->where('name', 'Internet Fibra 100MB 56')
            ->value('id');

        // Nothing to migrate from (fresh / test DB, or a tenant that never had
        // this plan): no-op. The migration only matters where the source plan
        // exists, so it must not abort environments that don't have it.
        if (! $from) {
            return;
        }

        $to = DB::table('service_plan')
            ->whereNull('deleted_at')
            ->where('name', 'Internet Fibra 100MB 60')
            ->value('id');

        // Source plan exists but the target doesn't — a genuine inconsistency
        // worth stopping for (we'd otherwise orphan customers).
        if (! $to) {
            throw new \RuntimeException(
                "Plan migration aborted — 'Internet Fibra 100MB 56': found (id={$from}) | 'Internet Fibra 100MB 60': NOT FOUND"
            );
        }

        $affected = DB::table('customer_profile')
            ->where('service_id', $from)
            ->update(['service_id' => $to]);

        echo "Migrated {$affected} customer(s) from plan id={$from} to id={$to}\n";
    }

    public function down(): void
    {
        $from = DB::table('service_plan')
            ->whereNull('deleted_at')
            ->where('name', 'Internet Fibra 100MB 60')
            ->value('id');

        $to = DB::table('service_plan')
            ->whereNull('deleted_at')
            ->where('name', 'Internet Fibra 100MB 56')
            ->value('id');

        if (! $from || ! $to) {
            return;
        }

        DB::table('customer_profile')
            ->where('service_id', $from)
            ->update(['service_id' => $to]);
    }
};
