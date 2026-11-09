<?php

namespace App\Ai\Support;

use App\Ai\Conversations\ConversationStore;
use App\Ai\Conversations\HistoryBudget;
use App\Ai\Conversations\SummarizeConversation;
use App\Ai\Memory\MemoryStore;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRunner;
use App\Ai\Tools\ToolRunResult;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

final class SupportAssistant
{
    public function __construct(
        private readonly ToolRunner $runner,
        private readonly ConversationStore $store,
        private readonly HistoryBudget $budget,
        private readonly MemoryStore $memories,
    ) {}

    public function answer(User $user, string $question, ?Conversation $conversation = null): ToolRunResult
    {
        $conversation ??= $this->store->start($user, SupportPrompt::VERSION);

        $allowance = $this->budget->historyAllowance(SupportPrompt::system($user));

        $messages = SupportPrompt::messages(
            question: $question,
            user: $user,
            summary: $conversation->summary,
            history: $this->store->history($conversation, $allowance),
            contextChunks: [],
        );

        $result = $this->runner->run(
            messages: $messages,
            ctx: new ToolContext(user: $user, conversationId: $conversation->id),
            options: [
                'profile' => 'assistant',
                'max_tokens' => config('ai.budget.answer'),
            ],
            feature: 'support.reply',
            promptVersion: SupportPrompt::VERSION,
        );

        $this->remember($conversation, $question, $result);

        $this->memories->markUsed($user);

        $this->summarizeInBackground($conversation, $allowance);

        return $result->forConversation($conversation->id);
    }

    private function remember(Conversation $conversation, string $question, ToolRunResult $result): void
    {
        $this->store->append($conversation, [
            'role' => Message::ROLE_USER,
            'content' => $question,
        ]);

        $this->store->appendRun($conversation, $result);
    }

    private function summarizeInBackground(Conversation $conversation, int $allowance): void
    {
        $fresh = $conversation->fresh();

        $needed = $this->store->needsSummary($fresh, $allowance) || $this->store->needsCompaction($fresh);

        SummarizeConversation::dispatchIf($needed, $fresh, $allowance);
    }
}
