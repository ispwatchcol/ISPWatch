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
            return;
        }

        $affected = DB::table('user_services')
            ->where('service_plan_id', $from)
            ->update(['service_plan_id' => $to]);

        echo "user_services: migrated {$affected} row(s) from plan id={$from} to id={$to}\n";
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

        DB::table('user_services')
            ->where('service_plan_id', $from)
            ->update(['service_plan_id' => $to]);
    }
};
