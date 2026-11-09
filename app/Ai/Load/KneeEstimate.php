<?php

namespace App\Ai\Load;

final readonly class KneeEstimate
{
    public const WITHIN_TESTED_RANGE = 'interpolated';

    public const ALL_LEVELS_PASS = 'not saturated';

    public const NO_LEVEL_PASSES = 'saturated at the lowest level';

    public function __construct(
        public int $concurrency,
        public string $basis,
        public float $ceilingMs,
    ) {}

    public function line(): string
    {
        if ($this->basis === self::NO_LEVEL_PASSES) {
            return sprintf('no knee: TTFT p99 is already above %d ms at the lowest concurrency tested. Look at prompt size, --max-model-len and the GPU before the queue.', $this->ceilingMs);
        }

        if ($this->basis === self::ALL_LEVELS_PASS) {
            return sprintf('knee not reached: TTFT p99 stayed under %d ms up to concurrency %d. Test higher levels before sizing the queue.', $this->ceilingMs, $this->concurrency);
        }

        return sprintf('knee ≈ %d concurrent for TTFT p99 < %d ms: set AI_CONCURRENCY=%d for the ai queue', $this->concurrency, $this->ceilingMs, $this->concurrency);
    }
}
