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

        $to = DB::table('service_plan')
            ->whereNull('deleted_at')
            ->where('name', 'Internet Fibra 100MB 60')
            ->value('id');

        if (! $from || ! $to) {
            $fromName = $from ? "found (id={$from})" : 'NOT FOUND';
            $toName   = $to   ? "found (id={$to})"   : 'NOT FOUND';
            throw new \RuntimeException(
                "Plan migration aborted — 'Internet Fibra 100MB 56': {$fromName} | 'Internet Fibra 100MB 60': {$toName}"
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
