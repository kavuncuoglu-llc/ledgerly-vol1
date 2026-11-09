<?php

namespace App\Ai\Stats;

final readonly class FeatureSnapshot
{
    public function __construct(
        public int $calls,
        public ?float $promptCacheHitRate,
        public bool $promptCacheEstimated,
        public ?float $semanticHitRate,
        public int $semanticHits,
        public int $semanticLookups,
        public ?int $tokensPerAnswer,
        public array $slots,
        public ?int $averageTtftMs,
        public ?int $averageLatencyMs,
        public ?float $citationFlagRate = null,
        public int $citationFlagged = 0,
        public int $citationChecked = 0,
        public array $routed = [],
        public int $failedJobs = 0,
    ) {}
}
