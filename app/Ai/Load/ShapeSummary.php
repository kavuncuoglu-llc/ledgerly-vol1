<?php

namespace App\Ai\Load;

final readonly class ShapeSummary
{
    public function __construct(
        public int $requests,
        public int $inputTokens,
        public int $outputTokens,
        public int $prefixTokens,
    ) {}

    public static function of(array $shapes): self
    {
        return new self(
            requests: count($shapes),
            inputTokens: (int) Percentile::of(array_map(fn (TrafficShape $shape): int => $shape->inputTokens, $shapes), 50),
            outputTokens: (int) Percentile::of(array_map(fn (TrafficShape $shape): int => $shape->outputTokens, $shapes), 50),
            prefixTokens: (int) Percentile::of(array_map(fn (TrafficShape $shape): int => $shape->prefixTokens, $shapes), 50),
        );
    }

    public function line(): string
    {
        return sprintf(
            'traffic shape: %d requests · input p50 %d tokens · output p50 %d tokens · shared prefix p50 %d tokens',
            $this->requests,
            $this->inputTokens,
            $this->outputTokens,
            $this->prefixTokens,
        );
    }
}
