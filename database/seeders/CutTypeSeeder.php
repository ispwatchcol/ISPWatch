<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CutTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['id' => 1, 'name' => 'Corte Automático'],
            ['id' => 2, 'name' => 'Corte Manual'],
            ['id' => 3, 'name' => 'Sin Corte'],
        ];

        foreach ($types as $type) {
            DB::table('cut_type')->updateOrInsert(
                ['id' => $type['id']],
                array_merge($type, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
