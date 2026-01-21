<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('tenant')->updateOrInsert(
            ['id' => 1],
            [
                'name' => 'ISPWatch Main',
                'domain' => 'ispwatch.local',
                'email_tenant' => 'contact@ispwatch.local',
                'address_tenant' => 'Main St',
                'currency_tenant' => 'COP',
                'status' => 'pro',
                'max_customers' => 500,
                'created_at' => now(),
                'updated_at' => now()
            ]
        );
    }
}
