<?php

namespace App\Ai\Support;

use App\Ai\Cache\CacheLookup;
use App\Ai\Cache\SupportCacheGate;
use App\Ai\Context\AssembledPrompt;
use App\Ai\Conversations\ConversationStore;
use App\Ai\Conversations\HistoryBudget;
use App\Ai\Conversations\SummarizeConversation;
use App\Ai\Events\ModelCalled;
use App\Ai\Http\CallDetails;
use App\Ai\Http\ChatResponse;
use App\Ai\Http\Usage;
use App\Ai\Memory\MemoryStore;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRunner;
use App\Ai\Tools\ToolRunResult;
use App\Models\CachedAnswer;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

final class SupportAssistant
{
    public const CACHE_PROVIDER = 'semantic-cache';

    public const CACHE_MODEL = 'cached_answers';

    public function __construct(
        private readonly ToolRunner $runner,
        private readonly ConversationStore $store,
        private readonly HistoryBudget $budget,
        private readonly MemoryStore $memories,
        private readonly SupportCacheGate $cache,
    ) {}

    public function answer(User $user, string $question, ?Conversation $conversation = null): ToolRunResult
    {
        $started = hrtime(true);

        $conversation ??= $this->store->start($user, SupportPrompt::VERSION);

        $lookup = $this->cache->lookup($user, $question, $conversation);

        if ($lookup->isHit()) {
            return $this->answerFromCache($conversation, $question, $lookup, $started);
        }

        $allowance = $this->budget->historyAllowance(SupportPrompt::system($user));

        $assembled = $this->assemble($user, $question, $conversation, $allowance);

        $result = $this->runner->run(
            messages: $assembled->messages,
            ctx: new ToolContext(user: $user, conversationId: $conversation->id),
            options: [
                'profile' => 'assistant',
                'max_tokens' => config('ai.budget.answer'),
            ],
            feature: 'support.reply',
            promptVersion: SupportPrompt::VERSION,
            details: new CallDetails(
                contextBreakdown: $assembled->breakdown,
                semanticCacheHit: $lookup->semanticCacheHit(),
            ),
        );

        $this->cache->store($user, $question, $lookup, $result, grounded: false);

        $this->remember($conversation, $question, $result);

        $this->memories->markUsed($user);

        $this->summarizeInBackground($conversation, $allowance);

        return $result->forConversation($conversation->id);
    }

    private function assemble(User $user, string $question, Conversation $conversation, int $allowance): AssembledPrompt
    {
        return SupportPrompt::assemble(
            question: $question,
            user: $user,
            summary: $conversation->summary,
            history: $this->store->history($conversation, $allowance),
            contextChunks: [],
        );
    }

    private function answerFromCache(Conversation $conversation, string $question, CacheLookup $lookup, int|float $started): ToolRunResult
    {
        $hit = $lookup->answer;

        $event = new ModelCalled(
            feature: 'support.reply',
            provider: self::CACHE_PROVIDER,
            model: self::CACHE_MODEL,
            usage: [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
            ],
            finishReason: 'stop',
            latencyMs: (int) ((hrtime(true) - $started) / 1_000_000),
            conversationId: $conversation->id,
            promptVersion: SupportPrompt::VERSION,
            semanticCacheHit: true,
        );

        event($event);

        $this->cache->recordHit($hit);

        $result = new ToolRunResult(
            response: $this->cachedResponse($hit, $event),
            messages: [],
            rounds: 0,
            modelCallIds: [$event->modelCallId],
        );

        $this->remember($conversation, $question, $result);

        return $result->forConversation($conversation->id);
    }

    private function cachedResponse(CachedAnswer $hit, ModelCalled $event): ChatResponse
    {
        return new ChatResponse(
            id: "cached_answer:{$hit->id}",
            model: self::CACHE_MODEL,
            content: $hit->answer,
            toolCalls: [],
            finishReason: 'stop',
            usage: new Usage(promptTokens: 0, completionTokens: 0),
            raw: [
                'semantic_cache' => [
                    'cached_answer_id' => $hit->id,
                    'similarity' => (float) $hit->similarity,
                    'cached_question' => $hit->question,
                ],
            ],
            modelCallId: $event->modelCallId,
        );
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
