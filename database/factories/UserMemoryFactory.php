<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserMemory;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserMemoryFactory extends Factory
{
    protected $model = UserMemory::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tenant_id' => null,
            'key' => fake()->unique()->word(),
            'value' => fake()->sentence(3),
            'source' => UserMemory::SOURCE_STATED,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
            'tenant_id' => $user->customer_id,
        ]);
    }
}
