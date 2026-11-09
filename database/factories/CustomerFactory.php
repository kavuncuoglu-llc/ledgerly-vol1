<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'plan' => fake()->randomElement([
                'free',
                'pro',
                'pro',
                'business',
            ]),
            'currency' => 'USD',
            'late_fee_enabled' => true,
            'late_fee_percent' => 1.50,
        ];
    }
}
