<?php

namespace Database\Factories;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'amount' => fake()->randomElement([
                320.00,
                640.00,
                1284.50,
            ]),
            'method' => fake()->randomElement([
                'card',
                'bank_transfer',
                'check',
            ]),
            'reference' => 'pay_'.fake()->bothify('????########'),
            'received_at' => now()->subDays(fake()->numberBetween(1, 90)),
        ];
    }
}
