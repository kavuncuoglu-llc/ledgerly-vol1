<?php

namespace App\Ai\Cache;

use App\Ai\Embeddings\Embedder;
use App\Ai\Exceptions\CacheScopeRequired;
use App\Enums\TicketCategory;
use App\Models\CachedAnswer;

final class SemanticCache
{
    public function __construct(
        private readonly Embedder $embedder,
        private readonly CachePolicy $policy,
        private readonly HelpVersion $helpVersion,
    ) {}

    public function lookup(string $question, TicketCategory $category, ?int $tenantId): CachedAnswer
    {
        if (! $this->policy->cacheable($category)) {
            return new CachedAnswer;
        }

        return $this->nearest($question, [$category->value], $tenantId);
    }

    public function lookupAnyCacheable(string $question, ?int $tenantId): CachedAnswer
    {
        return $this->nearest($question, $this->policy->cacheableCategories(), $tenantId);
    }

    private function nearest(string $question, array $categories, ?int $tenantId): CachedAnswer
    {
        if ($tenantId === null) {
            return new CachedAnswer;
        }

        $hit = CachedAnswer::query()
            ->visibleTo($tenantId)
            ->unexpired()
            ->whereIn('category', $categories)
            ->where('help_version', $this->helpVersion->current())
            ->nearestTo($this->embedder->query($question))
            ->first();

        if ($hit === null) {
            return new CachedAnswer;
        }

        if ((float) $hit->similarity < $this->policy->thresholdFor($hit->category)) {
            return new CachedAnswer;
        }

        return $hit;
    }

    public function store(string $question, TicketCategory $category, string $answer, ?int $tenantId): CachedAnswer
    {
        if ($tenantId === null) {
            throw CacheScopeRequired::forStore();
        }

        return CachedAnswer::create([
            'tenant_id' => $tenantId,
            'category' => $category->value,
            'question' => $question,
            'answer' => $answer,
            'help_version' => $this->helpVersion->current(),
            'status' => CachedAnswer::STATUS_AUTO,
            'expires_at' => $this->policy->expiresAt(),
            'embedding' => $this->embedder->query($question),
        ]);
    }

    public function recordHit(CachedAnswer $hit): void
    {
        CachedAnswer::query()
            ->whereKey($hit->id)
            ->increment('hit_count');
    }
}
