<?php

namespace App\Ai\Support;

use App\Ai\Http\AiHttpClient;
use App\Ai\Http\ChatResponse;
use App\Models\User;

final class SupportAssistant
{
    public function __construct(private readonly AiHttpClient $client) {}

    public function answer(User $user, string $question): ChatResponse
    {
        return $this->client->chat(
            messages: [
                [
                    'role' => 'system',
                    'content' => SupportPrompt::system(),
                ],
                [
                    'role' => 'user',
                    'content' => $question,
                ],
            ],
            options: [
                'profile' => 'assistant',
                'max_tokens' => config('ai.budget.answer'),
            ],
            feature: 'support.reply',
        );
    }
}
