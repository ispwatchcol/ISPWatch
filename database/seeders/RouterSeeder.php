<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RouterSeeder extends Seeder
{
    public function run(): void
    {
        $routers = [
            [
                'id' => 1,
                'name' => 'Router Principal Bogotá',
                'ip' => '192.168.1.1',
                'user_rb' => 'admin',
                'password_rb' => 'admin123',
                'lan_interface' => 'ether1',
                'wan_interface' => 'ether2',
                'cut_type_id' => 1,
                'status' => 'active',
                'tenant_id' => 1,
                'coordinates' => json_encode(['lat' => 4.7110, 'lng' => -74.0721]),
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'id' => 2,
                'name' => 'Router Medellín Centro',
                'ip' => '192.168.2.1',
                'user_rb' => 'admin',
                'password_rb' => 'admin123',
                'lan_interface' => 'ether1',
                'wan_interface' => 'ether2',
                'cut_type_id' => 1,
                'status' => 'active',
                'tenant_id' => 1,
                'coordinates' => json_encode(['lat' => 6.2476, 'lng' => -75.5658]),
                'created_at' => now(),
                'updated_at' => now()
            ],
        ];

        foreach ($routers as $router) {
            DB::table('router')->updateOrInsert(
                ['id' => $router['id']],
                $router
            );
        }
    }
}
