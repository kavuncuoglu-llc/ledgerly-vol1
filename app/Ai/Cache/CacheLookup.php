<?php

namespace App\Ai\Cache;

use App\Enums\TicketCategory;
use App\Models\CachedAnswer;

final readonly class CacheLookup
{
    public function __construct(
        public bool $attempted,
        public TicketCategory $category,
        public CachedAnswer $answer,
    ) {}

    public static function skipped(): self
    {
        return new self(
            attempted: false,
            category: TicketCategory::Other,
            answer: new CachedAnswer,
        );
    }

    public function isHit(): bool
    {
        return $this->answer->exists;
    }

    public function semanticCacheHit(): ?bool
    {
        if (! $this->attempted) {
            return null;
        }

        return $this->isHit();
    }
}
