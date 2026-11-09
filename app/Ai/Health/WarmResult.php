<?php

namespace App\Ai\Health;

final readonly class WarmResult
{
    public function __construct(
        public string $model,
        public float $firstSeconds,
        public float $warmSeconds,
        public int $promptTokens,
        public ?int $cachedTokens,
    ) {}
}
