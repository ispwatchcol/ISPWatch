<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TypePlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('type_plans')->insert([
            ['id' => 1, 'code' => 'queue', 'name' => 'Simple Queue', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'code' => 'pppoe', 'name' => 'PPPoE Profile', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'code' => 'hotspot', 'name' => 'HotSpot Profile', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'code' => 'pcq', 'name' => 'PCQ Type', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
