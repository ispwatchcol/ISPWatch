<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'id' => 1,
                'name' => 'admin',
                'email' => 'admin@ispwatch.com',
                'password' => Hash::make('password'),
                'role_id' => 1,
                'tenant_id' => 1,
                'user_name' => 'Admin',
                'user_lastname' => 'ISPWatch',
                'status' => true
            ],
            [
                'id' => 2,
                'name' => 'staff1',
                'email' => 'staff1@ispwatch.com',
                'password' => Hash::make('password'),
                'role_id' => 2,
                'tenant_id' => 1,
                'user_name' => 'María',
                'user_lastname' => 'González',
                'status' => true
            ],
            [
                'id' => 4,
                'name' => 'jorge.clemente',
                'email' => 'jorge.clemente@gmail.com',
                'password' => Hash::make('password'),
                'role_id' => 3,
                'tenant_id' => 1,
                'service_id' => 1,
                'user_name' => 'Jorge',
                'user_lastname' => 'Clemente',
                'status' => true
            ],
        ];

        foreach ($users as $user) {
            DB::table('users')->updateOrInsert(
                ['id' => $user['id']],
                array_merge($user, ['created_at' => now(), 'updated_at' => now()])
            );
        }

        // Add profiles
        DB::table('staff_profile')->updateOrInsert(
            ['user_id' => 2],
            ['name' => 'María', 'last_name' => 'González', 'department' => 'Soporte', 'position' => 'Técnico']
        );

        DB::table('customer_profile')->updateOrInsert(
            ['user_id' => 4],
            [
                'name' => 'Jorge',
                'last_name' => 'Clemente',
                'department' => 'Residencial',
                'position' => 'Cliente Hogar',
                'address' => 'Calle 100 #15-20',
                'city' => 'Bogotá',
                'state' => 'Cundinamarca',
                'postal_code' => '110111',
                'country' => 'Colombia',
                'latitude' => 4.7110,
                'longitude' => -74.0721,
                'router_id' => 1,
                'service_id' => 1,
                'status' => true
            ]
        );
    }
}
