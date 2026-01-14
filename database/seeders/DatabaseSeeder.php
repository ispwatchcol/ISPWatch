<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Disable foreign key checks for truncation
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        // List of tables to truncate (Order matters less with deferred constraints, but good practice)
        $tables = [
            'support_ticket_message',
            'support_ticket_attachment',
            'support_ticket',
            'inventory_device',
            'inventory_branch',
            'inventory_provider',
            'inventory_stock',
            'user_services',
            'customer_profile',
            'staff_profile',
            'router_ip_range',
            'ip_assignment',
            'ip_range',
            'request_installations' ?? null, // if exists
            'users',
            'sectorial',
            'router',
            'service_plan',
            'billing',
            'cut_type',
            'type_billing',
            'tenant',
            'role',
            'script_version',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        // 1. Roles
        $roles = [
            ['id' => 1, 'name' => 'Administrador', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Staff', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Cliente', 'created_at' => now(), 'updated_at' => now()],
        ];
        DB::table('role')->insert($roles);

        // 2. Tenant
        $tenants = [
            ['id' => 1, 'name' => 'ISPWatch Main', 'domain' => 'ispwatch.local', 'created_at' => now(), 'updated_at' => now(), 'email_tenant' => 'contact@ispwatch.local', 'address_tenant' => 'Main St', 'currency_tenant' => 'COP'],
        ];
        DB::table('tenant')->insert($tenants);

        // 3. Service Plans
        $servicePlans = [
            ['id' => 1, 'name' => 'Plan Básico 10MB', 'speed_down' => '10', 'speed_up' => '5', 'cost_product' => 25000, 'commit' => '1/1', 'type' => 'residencial', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Plan Estándar 20MB', 'speed_down' => '20', 'speed_up' => '10', 'cost_product' => 40000, 'commit' => '2/2', 'type' => 'residencial', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Plan Premium 50MB', 'speed_down' => '50', 'speed_up' => '25', 'cost_product' => 75000, 'commit' => '5/5', 'type' => 'empresarial', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'name' => 'Plan Empresarial 100MB', 'speed_down' => '100', 'speed_up' => '50', 'cost_product' => 150000, 'commit' => '10/10', 'type' => 'empresarial', 'created_at' => now(), 'updated_at' => now()],
        ];
        DB::table('service_plan')->insert($servicePlans);

        // 4. Cut Types
        $cutTypes = [
            ['id' => 1, 'name' => 'Corte Automático', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Corte Manual', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Sin Corte', 'created_at' => now(), 'updated_at' => now()],
        ];
        DB::table('cut_type')->insert($cutTypes);

        // 5. Type Billing
        $typeBilling = [
            ['id' => 1, 'type' => 'Mensual', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'type' => 'Trimestral', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'type' => 'Anual', 'created_at' => now(), 'updated_at' => now()],
        ];
        DB::table('type_billing')->insert($typeBilling);

        // 6. Billing Configuration (Using dates as per schema, assuming current month anchor)
        DB::table('billing')->insert([
            'id' => 1,
            'id_type' => 1, // Mensual
            'create_invoice' => now()->startOfMonth()->addDays(0), // 1st
            'payment_day' => now()->startOfMonth()->addDays(4), // 5th
            'payment_reminder' => now()->startOfMonth()->addDays(2), // 3rd
            'cut_day' => now()->startOfMonth()->addDays(14), // 15th
            'overdue_invoices' => 0,
            'amount' => 0,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // 7. Routers
        DB::table('router')->insert([
            ['id' => 1, 'name' => 'Router Principal Bogotá', 'ip' => '192.168.1.1', 'user_rb' => 'admin', 'password_rb' => 'admin123', 'lan_interface' => 'ether1', 'cut_type_id' => 1, 'billing_router_id' => 1, 'firmware_version' => '6.49.6', 'comments' => null, 'status' => 'active', 'coordinates' => json_encode(['lat' => 4.7110, 'lng' => -74.0721]), 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Router Medellín Centro', 'ip' => '192.168.2.1', 'user_rb' => 'admin', 'password_rb' => 'admin123', 'lan_interface' => 'ether1', 'cut_type_id' => 1, 'billing_router_id' => 1, 'firmware_version' => '6.49.6', 'comments' => null, 'status' => 'active', 'coordinates' => json_encode(['lat' => 6.2476, 'lng' => -75.5658]), 'created_at' => now(), 'updated_at' => now()],
        ]);

        // 8. Sectorials
        // Note: zona_id refers to a router usually, or a zone table. Assuming router_id based on schema FK name 'sectorial_zona_id_fkey' -> router(id)
        DB::table('sectorial')->insert([
            ['id' => 1, 'name' => 'Sector 1 Norte (Bogotá)', 'zona_id' => 1, 'ssid' => 'ISP_Norte', 'frequency' => '5800', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Sector 2 Sur (Bogotá)', 'zona_id' => 1, 'ssid' => 'ISP_Sur', 'frequency' => '5745', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // 9. Users
        $users = [
            // Admin
            [
                'id' => 1,
                'name' => 'admin',
                'email' => 'admin@ispwatch.com',
                'password' => Hash::make('password'),
                'role_id' => 1,
                'tenant_id' => 1,
                'email_tenant' => 'admin@ispwatch.com',
                'status' => true
            ],
            // Staff
            [
                'id' => 2,
                'name' => 'staff1',
                'email' => 'staff1@ispwatch.com',
                'password' => Hash::make('password'),
                'role_id' => 2,
                'tenant_id' => 1,
                'email_tenant' => 'staff1@ispwatch.com',
                'status' => true
            ],
            [
                'id' => 3,
                'name' => 'staff2',
                'email' => 'staff2@ispwatch.com',
                'password' => Hash::make('password'),
                'role_id' => 2,
                'tenant_id' => 1,
                'email_tenant' => 'staff2@ispwatch.com',
                'status' => true
            ],
            // Customers
            [
                'id' => 4,
                'name' => 'jorge.clemente',
                'email' => 'jorge.clemente@gmail.com',
                'password' => Hash::make('password'),
                'role_id' => 3,
                'tenant_id' => 1,
                'service_id' => 1,
                'sectorial_id' => 1,
                'email_tenant' => 'jorge.clemente@gmail.com',
                'tel' => '3001234567',
                'status' => true
            ],
            [
                'id' => 5,
                'name' => 'camila.suarez',
                'email' => 'camila.suarez@gmail.com',
                'password' => Hash::make('password'),
                'role_id' => 3,
                'tenant_id' => 1,
                'service_id' => 2,
                'sectorial_id' => null,
                'email_tenant' => 'camila.suarez@gmail.com',
                'tel' => '3009876543',
                'status' => true
            ],
            [
                'id' => 6,
                'name' => 'sofia.jimenez',
                'email' => 'sofia.jimenez@gmail.com',
                'password' => Hash::make('password'),
                'role_id' => 3,
                'tenant_id' => 1,
                'service_id' => 3,
                'sectorial_id' => 2,
                'email_tenant' => 'sofia.jimenez@gmail.com',
                'tel' => '3005551234',
                'status' => true
            ],
            [
                'id' => 7,
                'name' => 'david.ortiz',
                'email' => 'david.ortiz@gmail.com',
                'password' => Hash::make('password'),
                'role_id' => 3,
                'tenant_id' => 1,
                'service_id' => 4,
                'sectorial_id' => 1,
                'email_tenant' => 'david.ortiz@gmail.com',
                'tel' => '3007778888',
                'status' => true
            ],
            [
                'id' => 8,
                'name' => 'paula.castano',
                'email' => 'paula.castano@gmail.com',
                'password' => Hash::make('password'),
                'role_id' => 3,
                'tenant_id' => 1,
                'service_id' => 1,
                'sectorial_id' => 2,
                'email_tenant' => 'paula.castano@gmail.com',
                'tel' => '3004445555',
                'status' => true
            ]
        ];

        foreach ($users as $user) {
            DB::table('users')->insert(array_merge($user, [
                'created_at' => now(),
                'updated_at' => now(),
                'user_name' => explode('.', $user['name'])[0],
                'user_lastname' => isset(explode('.', $user['name'])[1]) ? explode('.', $user['name'])[1] : 'User'
            ]));
        }

        // 10. Staff Profiles
        DB::table('staff_profile')->insert([
            ['user_id' => 2, 'name' => 'María', 'last_name' => 'González', 'department' => 'Soporte', 'position' => 'Técnico'],
            ['user_id' => 3, 'name' => 'Carlos', 'last_name' => 'Rodríguez', 'department' => 'Ventas', 'position' => 'Ejecutivo'],
        ]);

        // 11. Customer Profiles
        $customerProfiles = [
            ['user_id' => 4, 'name' => 'Jorge', 'last_name' => 'Clemente', 'department' => 'Residencial', 'position' => 'Cliente Hogar', 'address' => 'Calle 100 #15-20', 'city' => 'Bogotá', 'state' => 'Cundinamarca', 'postal_code' => '110111', 'country' => 'Colombia', 'latitude' => 4.7110, 'longitude' => -74.0721, 'router_id' => 1, 'sectorial_id' => 1, 'service_id' => 1, 'status' => true],
            ['user_id' => 5, 'name' => 'Camila', 'last_name' => 'Suárez', 'department' => 'Empresarial', 'position' => 'Cliente Corporativo', 'address' => 'Carrera 43A #1-50', 'city' => 'Medellín', 'state' => 'Antioquia', 'postal_code' => '050001', 'country' => 'Colombia', 'latitude' => 6.2476, 'longitude' => -75.5658, 'router_id' => 2, 'sectorial_id' => null, 'service_id' => 2, 'status' => true],
            ['user_id' => 6, 'name' => 'Sofía', 'last_name' => 'Jiménez', 'department' => 'Residencial', 'position' => 'Cliente Hogar', 'address' => 'Calle 5 #36-100', 'city' => 'Cali', 'state' => 'Valle del Cauca', 'postal_code' => '760001', 'country' => 'Colombia', 'latitude' => 3.4516, 'longitude' => -76.5320, 'router_id' => 1, 'sectorial_id' => 2, 'service_id' => 3, 'status' => true],
            ['user_id' => 7, 'name' => 'David', 'last_name' => 'Ortiz', 'department' => 'Empresarial', 'position' => 'Cliente Corporativo', 'address' => 'Calle 72 #10-34', 'city' => 'Barranquilla', 'state' => 'Atlántico', 'postal_code' => '080001', 'country' => 'Colombia', 'latitude' => 10.9685, 'longitude' => -74.7813, 'router_id' => 1, 'sectorial_id' => 1, 'service_id' => 4, 'status' => true],
            ['user_id' => 8, 'name' => 'Paula', 'last_name' => 'Castaño', 'department' => 'Residencial', 'position' => 'Cliente Hogar', 'address' => 'Carrera 15 #85-45', 'city' => 'Bogotá', 'state' => 'Cundinamarca', 'postal_code' => '110221', 'country' => 'Colombia', 'latitude' => 4.6870, 'longitude' => -74.0565, 'router_id' => 1, 'sectorial_id' => 2, 'service_id' => 1, 'status' => true],
        ];
        DB::table('customer_profile')->insert($customerProfiles);

        // 12. User Services
        foreach ($customerProfiles as $cp) {
            DB::table('user_services')->insert([
                'user_id' => $cp['user_id'],
                'service_plan_id' => $cp['service_id'],
                'start_date' => now()->subMonths(3),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 13. Inventory Basics
        DB::table('inventory_stock')->insert([
            ['id' => 1, 'brand' => 1, 'model' => 'Mikrotik RB750Gr3', 'price' => 250000, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'brand' => 2, 'model' => 'Ubiquiti NanoStation M5', 'price' => 180000, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('inventory_provider')->insert([
            ['id' => 1, 'name' => 'TechSupply Colombia', 'email' => 'ventas@techsupply.co', 'phone' => '6015551234', 'city' => 'Bogotá', 'advisor_name' => 'Juan Pérez', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('inventory_branch')->insert([
            ['id' => 1, 'name' => 'Bodega Principal', 'dir' => 'Calle 50 #20-10', 'numero' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        // Devices
        DB::table('inventory_device')->insert([
            ['stock_id' => 1, 'provider_id' => 1, 'branch_id' => 1, 'mac' => '00:11:22:33:44:55', 'serial' => 'MK123456', 'user_id' => null, 'created_at' => now(), 'updated_at' => now()]
        ]);
        // Optional: Assign one to a user
        DB::table('inventory_device')->insert([
            ['stock_id' => 2, 'provider_id' => 1, 'branch_id' => 1, 'mac' => 'AA:BB:CC:DD:EE:FF', 'serial' => 'UBNT987654', 'user_id' => 4, 'created_at' => now(), 'updated_at' => now()]
        ]);

        // 14. IP Ranges
        DB::table('ip_range')->insert([
            ['id' => 1, 'range' => '192.168.10.0/24', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'range' => '10.0.0.0/24', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('router_ip_range')->insert([
            ['router_id' => 1, 'range_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['router_id' => 2, 'range_id' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // 15. Script Versions
        DB::table('script_version')->insert([
            ['version' => '1.0.0', 'created_at' => now(), 'updated_at' => now()]
        ]);

        // 16. Basic Support Ticket (Manual creation)
        DB::table('support_ticket')->insert([
            ['id' => 1, 'user_id' => 4, 'staff_id' => 2, 'tenant_id' => 1, 'subject' => 'Lentitud', 'description' => 'Internet lento', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]
        ]);

        echo "✅ Basic Database Seeded (No Payments/Invoices)!\n";
    }
}
