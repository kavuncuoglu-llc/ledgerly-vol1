<?php

namespace Database\Factories;

use App\Ai\Prompts\SupportPrompt;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tenant_id' => null,
            'status' => Conversation::STATUS_OPEN,
            'prompt_version' => SupportPrompt::VERSION,
            'summary_through_seq' => 0,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
            'tenant_id' => $user->customer_id,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Conversation::STATUS_CLOSED,
        ]);
    }
}
