<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TypePlansSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'queue', 'name' => 'Simple Queue'],
            ['code' => 'pppoe', 'name' => 'PPPoE'],
            ['code' => 'hotspot', 'name' => 'Hotspot'],
            ['code' => 'pcq', 'name' => 'PCQ'],
        ];

        foreach ($types as $type) {
            DB::table('type_plans')->updateOrInsert(
                ['code' => $type['code']],
                array_merge($type, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
