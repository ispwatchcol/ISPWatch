<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // For development environments, you might want to truncate tables,
        // but for a versioned seeder that uses updateOrInsert, it's safer
        // to just run the specialized seeders.

        $this->call([
            RoleSeeder::class,
            TenantSeeder::class,
            TypePlansSeeder::class,
            CutTypeSeeder::class,
            ServicePlanSeeder::class,
            RouterSeeder::class,
            UsersSeeder::class,
            CustomerSeeder::class,
            HelpCenterSeeder::class,
        ]);

        echo "✅ Database Seeded Successfully!\n";
    }
}
