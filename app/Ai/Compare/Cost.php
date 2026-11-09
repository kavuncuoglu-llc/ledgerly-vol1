<?php

namespace App\Ai\Compare;

final readonly class Cost
{
    public const BASIS_RATE = 'rate';

    public const BASIS_GPU = 'gpu';

    public const BASIS_FREE = 'free';

    public const BASIS_UNKNOWN = 'unknown';

    public function __construct(
        public float $usd,
        public string $basis,
    ) {}

    public function isKnown(): bool
    {
        return $this->basis !== self::BASIS_UNKNOWN;
    }
}
