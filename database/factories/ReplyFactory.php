<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Reply;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReplyFactory extends Factory
{
    protected $model = Reply::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'user_id' => User::factory(),
            'question' => 'Why was I charged a late fee?',
            'status' => Reply::STATUS_PENDING,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'conversation_id' => Conversation::factory()->forUser($user),
            'user_id' => $user->id,
        ]);
    }

    public function inConversation(Conversation $conversation): static
    {
        return $this->state(fn (array $attributes) => [
            'conversation_id' => $conversation->id,
            'user_id' => $conversation->user_id,
        ]);
    }

    public function completed(string $content = 'Late fees apply after the grace period.'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Reply::STATUS_COMPLETED,
            'content' => $content,
            'completed_at' => now(),
        ]);
    }

    public function paused(string $resumeToken, string $summary = 'Refund $21.60 late fee on invoice INV-4471'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Reply::STATUS_PAUSED,
            'resume_token' => $resumeToken,
            'paused_for' => $summary,
            'paused_at' => now(),
        ]);
    }
}
