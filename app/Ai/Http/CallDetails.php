<?php

namespace App\Ai\Http;

final readonly class CallDetails
{
    public function __construct(
        public ?array $contextBreakdown = null,
        public ?bool $semanticCacheHit = null,
    ) {}

    public function forFollowUpRound(array $extraSlots): self
    {
        return new self(
            contextBreakdown: [
                ...($this->contextBreakdown ?? []),
                ...$extraSlots,
            ],
            semanticCacheHit: null,
        );
    }
}
