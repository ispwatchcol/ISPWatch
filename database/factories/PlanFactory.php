<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'name' => 'Plan ' . $this->faker->randomElement(['Basic', 'Standard', 'Premium']),
            'speed_down' => $this->faker->randomElement(['10M', '20M', '50M', '100M']),
            'speed_up' => $this->faker->randomElement(['5M', '10M', '20M', '50M']),
            'cost_product' => $this->faker->numberBetween(15000, 100000),
            'type' => 'residential',
        ];
    }
}
