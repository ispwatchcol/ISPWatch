<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'domain' => $this->faker->domainName(),
            'status' => 'active',
            'max_customers' => 1000,
            'email_tenant' => $this->faker->companyEmail(),
            'tel_tenant' => $this->faker->phoneNumber(),
            'address_tenant' => $this->faker->address(),
            'currency' => 'COP',
            'next_invoice_number' => 1,
        ];
    }
}
