<?php

namespace App\Ai\Support;

use App\Ai\Prompts\SupportPrompt;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRunner;
use App\Ai\Tools\ToolRunResult;
use App\Models\User;

final class SupportAssistant
{
    public function __construct(private readonly ToolRunner $runner) {}

    public function answer(User $user, string $question, array $contextChunks = [], ?int $conversationId = null): ToolRunResult
    {
        return $this->runner->run(
            messages: SupportPrompt::messages($question, $contextChunks),
            ctx: new ToolContext(user: $user, conversationId: $conversationId),
            options: [
                'profile' => 'assistant',
                'max_tokens' => config('ai.budget.answer'),
            ],
            feature: 'support.reply',
            promptVersion: SupportPrompt::VERSION,
        );
    }
}
