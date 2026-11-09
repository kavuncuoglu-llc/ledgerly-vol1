<?php

namespace Database\Factories;

use App\Enums\TicketCategory;
use App\Enums\TicketStatus;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class TicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'number' => 'T-'.fake()->unique()->numberBetween(20000, 99999),
            'customer_id' => Customer::factory(),
            'invoice_id' => null,
            'subject' => fake()->sentence(6),
            'body' => fake()->paragraph(),
            'category' => fake()->randomElement(TicketCategory::cases()),
            'status' => TicketStatus::Open,
            'received_at' => now()->subHours(fake()->numberBetween(1, 240)),
            'resolved_at' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TicketStatus::Resolved,
            'resolved_at' => now(),
        ]);
    }
}
