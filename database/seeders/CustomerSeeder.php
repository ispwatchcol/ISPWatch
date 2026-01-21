<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $customers = [
            [
                'id' => 5,
                'name' => 'camila.suarez',
                'email' => 'camila.suarez@gmail.com',
                'password' => Hash::make('password'),
                'role_id' => 3,
                'tenant_id' => 1,
                'service_id' => 2,
                'user_name' => 'Camila',
                'user_lastname' => 'Suárez',
                'status' => true
            ],
        ];

        foreach ($customers as $customer) {
            DB::table('users')->updateOrInsert(
                ['id' => $customer['id']],
                array_merge($customer, ['created_at' => now(), 'updated_at' => now()])
            );

            DB::table('customer_profile')->updateOrInsert(
                ['user_id' => $customer['id']],
                [
                    'name' => $customer['user_name'],
                    'last_name' => $customer['user_lastname'],
                    'address' => 'Carrera 43A #1-50',
                    'city' => 'Medellín',
                    'router_id' => 2,
                    'service_id' => 2,
                    'status' => true
                ]
            );
        }
    }
}
