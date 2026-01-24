<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ServicePlanSeeder extends Seeder
{
    public function run(): void
    {
        $queueTypeId = DB::table('type_plans')->where('code', 'queue')->value('id');

        $plans = [
            ['id' => 1, 'name' => 'Plan Básico 10MB', 'speed_down' => '10', 'speed_up' => '5', 'cost_product' => 25000, 'commit' => '1/1', 'type' => 'residencial', 'type_plan_id' => $queueTypeId, 'tenant_id' => 1],
            ['id' => 2, 'name' => 'Plan Estándar 20MB', 'speed_down' => '20', 'speed_up' => '10', 'cost_product' => 40000, 'commit' => '2/2', 'type' => 'residencial', 'type_plan_id' => $queueTypeId, 'tenant_id' => 1],
            ['id' => 3, 'name' => 'Plan Premium 50MB', 'speed_down' => '50', 'speed_up' => '25', 'cost_product' => 75000, 'commit' => '5/5', 'type' => 'empresarial', 'type_plan_id' => $queueTypeId, 'tenant_id' => 1],
            ['id' => 4, 'name' => 'Plan Empresarial 100MB', 'speed_down' => '100', 'speed_up' => '50', 'cost_product' => 150000, 'commit' => '10/10', 'type' => 'empresarial', 'type_plan_id' => $queueTypeId, 'tenant_id' => 1],
        ];

        foreach ($plans as $plan) {
            DB::table('service_plan')->updateOrInsert(
                ['id' => $plan['id']],
                array_merge($plan, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
