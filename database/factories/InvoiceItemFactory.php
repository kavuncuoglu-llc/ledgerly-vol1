<?php

namespace Database\Factories;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceItemFactory extends Factory
{
    public function definition(): array
    {
        $unitPrice = fake()->randomElement([
            45.00,
            90.00,
            150.00,
            320.00,
        ]);

        $quantity = fake()->numberBetween(1, 8);

        return [
            'invoice_id' => Invoice::factory(),
            'description' => fake()->words(3, true),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => $unitPrice * $quantity,
        ];
    }
}
