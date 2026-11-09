<?php

namespace App\Ai\Cache;

use App\Ai\Exceptions\StructuredOutputFailed;
use App\Ai\Exceptions\StructuredRefusal;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Support\ClassifyTicket;
use App\Ai\Tools\ToolRunResult;
use App\Enums\TicketCategory;
use App\Models\CachedAnswer;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Log;

final class SupportCacheGate
{
    public function __construct(
        private readonly SemanticCache $cache,
        private readonly CachePolicy $policy,
        private readonly ClassifyTicket $classify,
    ) {}

    public function lookup(User $user, string $question, Conversation $conversation): CacheLookup
    {
        if (! $this->mayUseCache($user, $question, $conversation)) {
            return CacheLookup::skipped();
        }

        $nearest = $this->cache->lookupAnyCacheable($question, $user->customer_id);

        if ($nearest->exists) {
            return new CacheLookup(
                attempted: true,
                category: TicketCategory::from($nearest->category),
                answer: $nearest,
            );
        }

        $category = $this->categoryOf($question);

        if (! $this->policy->cacheable($category)) {
            return CacheLookup::skipped();
        }

        return new CacheLookup(
            attempted: true,
            category: $category,
            answer: new CachedAnswer,
        );
    }

    public function store(User $user, string $question, CacheLookup $lookup, ToolRunResult $result, bool $grounded): CachedAnswer
    {
        if (! $this->mayStore($user, $lookup, $result, $grounded)) {
            return new CachedAnswer;
        }

        return $this->cache->store(
            question: $question,
            category: $lookup->category,
            answer: $result->answer(),
            tenantId: $user->customer_id,
        );
    }

    public function recordHit(CachedAnswer $hit): void
    {
        $this->cache->recordHit($hit);
    }

    private function mayUseCache(User $user, string $question, Conversation $conversation): bool
    {
        if (! config('ai.semantic_cache.enabled')) {
            return false;
        }

        if ($user->customer_id === null) {
            return false;
        }

        if ($this->hasEarlierTurns($conversation)) {
            return false;
        }

        return $this->policy->questionIsGeneric($question);
    }

    private function hasEarlierTurns(Conversation $conversation): bool
    {
        return Message::query()
            ->withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->exists();
    }

    private function categoryOf(string $question): TicketCategory
    {
        try {
            return ($this->classify)($question);
        } catch (StructuredOutputFailed|StructuredRefusal $exception) {
            Log::warning('Semantic cache skipped: the question could not be classified', [
                'reason' => $exception->getMessage(),
            ]);

            return TicketCategory::Other;
        }
    }

    private function mayStore(User $user, CacheLookup $lookup, ToolRunResult $result, bool $grounded): bool
    {
        if (! $lookup->attempted) {
            return false;
        }

        if ($lookup->isHit()) {
            return false;
        }

        if ($user->customer_id === null) {
            return false;
        }

        if (! $this->runWasPlain($result)) {
            return false;
        }

        if ($this->policy->requiresGrounding() && ! $grounded) {
            return false;
        }

        if (SupportPrompt::memoriesBlock($user) !== '') {
            return false;
        }

        return $this->policy->answerIsStorable($result->answer());
    }

    private function runWasPlain(ToolRunResult $result): bool
    {
        if ($result->hasPending()) {
            return false;
        }

        if ($result->hitCap) {
            return false;
        }

        if ($result->rounds !== 1) {
            return false;
        }

        return $result->response->finishReason === 'stop';
    }
}
