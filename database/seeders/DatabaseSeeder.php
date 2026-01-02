<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Delete from tables in reverse order of dependencies
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        DB::table('customer_profile')->delete();
        DB::table('staff_profile')->delete();
        DB::table('inventory_device')->delete();
        DB::table('inventory_branch')->delete();
        DB::table('inventory_provider')->delete();
        DB::table('inventory_stock')->delete();
        DB::table('router')->delete();
        DB::table('users')->delete();
        DB::table('service_plan')->delete();
        DB::table('cut_type')->delete();
        DB::table('type_billing')->delete();
        DB::table('tenant')->delete();
        DB::table('role')->delete();

        // 1. Roles
        $roles = [
            ['id' => 1, 'name' => 'Administrador', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Staff', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Cliente', 'created_at' => now(), 'updated_at' => now()],
        ];
        DB::table('role')->insert($roles);

        // 2. Tenant
        $tenants = [
            ['id' => 1, 'name' => 'ISPWatch Main', 'domain' => 'ispwatch.local', 'created_at' => now(), 'updated_at' => now()],
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

        // 6. Users (Admin + Staff + Customers)
        // Insert users one by one to avoid column mismatch
        DB::table('users')->insert([
            'id' => 1,
            'name' => 'admin',
            'email' => 'admin@ispwatch.com',
            'password' => Hash::make('password'),
            'role_id' => 1,
            'tenant_id' => 1,
            'tel' => null,
            'email_tenant' => 'admin@ispwatch.com',
            'user_name' => 'admin',
            'user_lastname' => 'Admin',
            'status' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('users')->insert([
            'id' => 2,
            'name' => 'staff1',
            'email' => 'staff1@ispwatch.com',
            'password' => Hash::make('password'),
            'role_id' => 2,
            'tenant_id' => 1,
            'tel' => null,
            'email_tenant' => 'staff1@ispwatch.com',
            'user_name' => 'staff1',
            'user_lastname' => 'Staff',
            'status' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('users')->insert([
            'id' => 3,
            'name' => 'staff2',
            'email' => 'staff2@ispwatch.com',
            'password' => Hash::make('password'),
            'role_id' => 2,
            'tenant_id' => 1,
            'tel' => null,
            'email_tenant' => 'staff2@ispwatch.com',
            'user_name' => 'staff2',
            'user_lastname' => 'Staff',
            'status' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('users')->insert([
            'id' => 4,
            'name' => 'jorge.clemente',
            'email' => 'jorge.clemente@gmail.com',
            'password' => Hash::make('password'),
            'role_id' => 3,
            'tenant_id' => 1,
            'tel' => '3001234567',
            'email_tenant' => 'jorge.clemente@gmail.com',
            'user_name' => 'Jorge',
            'user_lastname' => 'Clemente',
            'status' => true,
            'created_at' => now()->subDays(30),
            'updated_at' => now()
        ]);

        DB::table('users')->insert([
            'id' => 5,
            'name' => 'camila.suarez',
            'email' => 'camila.suarez@gmail.com',
            'password' => Hash::make('password'),
            'role_id' => 3,
            'tenant_id' => 1,
            'tel' => '3009876543',
            'email_tenant' => 'camila.suarez@gmail.com',
            'user_name' => 'Camila',
            'user_lastname' => 'Suárez',
            'status' => true,
            'created_at' => now()->subDays(25),
            'updated_at' => now()
        ]);

        DB::table('users')->insert([
            'id' => 6,
            'name' => 'sofia.jimenez',
            'email' => 'sofia.jimenez@gmail.com',
            'password' => Hash::make('password'),
            'role_id' => 3,
            'tenant_id' => 1,
            'tel' => '3005551234',
            'email_tenant' => 'sofia.jimenez@gmail.com',
            'user_name' => 'Sofía',
            'user_lastname' => 'Jiménez',
            'status' => true,
            'created_at' => now()->subDays(20),
            'updated_at' => now()
        ]);

        DB::table('users')->insert([
            'id' => 7,
            'name' => 'david.ortiz',
            'email' => 'david.ortiz@gmail.com',
            'password' => Hash::make('password'),
            'role_id' => 3,
            'tenant_id' => 1,
            'tel' => '3007778888',
            'email_tenant' => 'david.ortiz@gmail.com',
            'user_name' => 'David',
            'user_lastname' => 'Ortiz',
            'status' => true,
            'created_at' => now()->subDays(15),
            'updated_at' => now()
        ]);

        DB::table('users')->insert([
            'id' => 8,
            'name' => 'paula.castano',
            'email' => 'paula.castano@gmail.com',
            'password' => Hash::make('password'),
            'role_id' => 3,
            'tenant_id' => 1,
            'tel' => '3004445555',
            'email_tenant' => 'paula.castano@gmail.com',
            'user_name' => 'Paula',
            'user_lastname' => 'Castaño',
            'status' => true,
            'created_at' => now()->subDays(10),
            'updated_at' => now()
        ]);


        // 7. Staff Profiles
        $staffProfiles = [
            ['user_id' => 2, 'name' => 'María', 'last_name' => 'González', 'department' => 'Soporte', 'position' => 'Técnico'],
            ['user_id' => 3, 'name' => 'Carlos', 'last_name' => 'Rodríguez', 'department' => 'Ventas', 'position' => 'Ejecutivo'],
        ];
        DB::table('staff_profile')->insert($staffProfiles);

        // 8. Customer Profiles with Locations
        $customerProfiles = [
            ['user_id' => 4, 'name' => 'Jorge', 'last_name' => 'Clemente', 'department' => 'Residencial', 'position' => 'Cliente Hogar', 'address' => 'Calle 100 #15-20', 'city' => 'Bogotá', 'state' => 'Cundinamarca', 'postal_code' => '110111', 'country' => 'Colombia', 'latitude' => 4.7110, 'longitude' => -74.0721],
            ['user_id' => 5, 'name' => 'Camila', 'last_name' => 'Suárez', 'department' => 'Empresarial', 'position' => 'Cliente Corporativo', 'address' => 'Carrera 43A #1-50', 'city' => 'Medellín', 'state' => 'Antioquia', 'postal_code' => '050001', 'country' => 'Colombia', 'latitude' => 6.2476, 'longitude' => -75.5658],
            ['user_id' => 6, 'name' => 'Sofía', 'last_name' => 'Jiménez', 'department' => 'Residencial', 'position' => 'Cliente Hogar', 'address' => 'Calle 5 #36-100', 'city' => 'Cali', 'state' => 'Valle del Cauca', 'postal_code' => '760001', 'country' => 'Colombia', 'latitude' => 3.4516, 'longitude' => -76.5320],
            ['user_id' => 7, 'name' => 'David', 'last_name' => 'Ortiz', 'department' => 'Empresarial', 'position' => 'Cliente Corporativo', 'address' => 'Calle 72 #10-34', 'city' => 'Barranquilla', 'state' => 'Atlántico', 'postal_code' => '080001', 'country' => 'Colombia', 'latitude' => 10.9685, 'longitude' => -74.7813],
            ['user_id' => 8, 'name' => 'Paula', 'last_name' => 'Castaño', 'department' => 'Residencial', 'position' => 'Cliente Hogar', 'address' => 'Carrera 15 #85-45', 'city' => 'Bogotá', 'state' => 'Cundinamarca', 'postal_code' => '110221', 'country' => 'Colombia', 'latitude' => 4.6870, 'longitude' => -74.0565],
        ];
        DB::table('customer_profile')->insert($customerProfiles);

        // 9. Routers
        $routers = [
            ['id' => 1, 'name' => 'Router Principal Bogotá', 'ip' => '192.168.1.1', 'user_rb' => 'admin', 'password_rb' => 'admin123', 'lan_interface' => 'ether1', 'cut_type_id' => 1, 'firmware_version' => '6.49.6', 'status' => 'active', 'coordinates' => json_encode(['lat' => 4.7110, 'lng' => -74.0721]), 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Router Medellín Centro', 'ip' => '192.168.2.1', 'user_rb' => 'admin', 'password_rb' => 'admin123', 'lan_interface' => 'ether1', 'cut_type_id' => 1, 'firmware_version' => '6.49.6', 'status' => 'active', 'coordinates' => json_encode(['lat' => 6.2476, 'lng' => -75.5658]), 'created_at' => now(), 'updated_at' => now()],
        ];
        DB::table('router')->insert($routers);

        // 10. Inventory Stock
        $inventoryStock = [
            ['id' => 1, 'brand' => 1, 'model' => 'Mikrotik RB750Gr3', 'price' => 250000, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'brand' => 2, 'model' => 'Ubiquiti NanoStation M5', 'price' => 180000, 'created_at' => now(), 'updated_at' => now()],
        ];
        DB::table('inventory_stock')->insert($inventoryStock);

        // 11. Inventory Provider
        $inventoryProvider = [
            ['id' => 1, 'name' => 'TechSupply Colombia', 'email' => 'ventas@techsupply.co', 'phone' => '6015551234', 'city' => 'Bogotá', 'advisor_name' => 'Juan Pérez', 'created_at' => now(), 'updated_at' => now()],
        ];
        DB::table('inventory_provider')->insert($inventoryProvider);

        // 12. Inventory Branch
        $inventoryBranch = [
            ['id' => 1, 'name' => 'Bodega Principal', 'dir' => 'Calle 50 #20-10', 'numero' => 1, 'created_at' => now(), 'updated_at' => now()],
        ];
        DB::table('inventory_branch')->insert($inventoryBranch);

        echo "✅ Database seeded successfully!\n";
        echo "📊 Created:\n";
        echo "   - 3 Roles\n";
        echo "   - 1 Tenant\n";
        echo "   - 4 Service Plans\n";
        echo "   - 3 Cut Types\n";
        echo "   - 3 Billing Types\n";
        echo "   - 8 Users (1 admin, 2 staff, 5 customers)\n";
        echo "   - 5 Customer Profiles (with locations)\n";
        echo "   - 2 Staff Profiles\n";
        echo "   - 2 Routers\n";
        echo "   - 2 Inventory Stock Items\n";
        echo "   - 1 Inventory Provider\n";
        echo "   - 1 Inventory Branch\n";
    }
}
