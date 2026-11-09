<?php

namespace App\Ai\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class ModelCalled
{
    use Dispatchable;

    public ?int $modelCallId = null;

    public function __construct(
        public readonly string $feature,
        public readonly string $provider,
        public readonly string $model,
        public readonly array $usage,
        public readonly string $finishReason,
        public readonly int $latencyMs,
        public readonly ?int $conversationId = null,
        public readonly ?string $promptVersion = null,
        public readonly bool $repaired = false,
        public readonly ?int $cachedTokens = null,
        public readonly ?int $ttftMs = null,
        public readonly ?array $contextBreakdown = null,
        public readonly ?bool $semanticCacheHit = null,
        public readonly bool $cachedTokensEstimated = false,
    ) {}
}
