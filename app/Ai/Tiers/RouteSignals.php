<?php

namespace App\Ai\Tiers;

use App\Enums\TicketCategory;

final readonly class RouteSignals
{
    private const DISPUTE_PATTERN = '/\b(dispute[sd]?|chargeback|charged twice|double[- ]charged|refund)\b/i';

    public function __construct(
        public float $agreement,
        public bool $multiAsk = false,
        public bool $isDispute = false,
        public int $unresolvedRounds = 0,
        public bool $humanFlagged = false,
    ) {}

    public static function for(string $question, TicketCategory $category, float $agreement, int $unresolvedRounds = 0, bool $humanFlagged = false): self
    {
        return new self(
            agreement: $agreement,
            multiAsk: substr_count($question, '?') >= 2,
            isDispute: self::mentionsDispute($question, $category),
            unresolvedRounds: $unresolvedRounds,
            humanFlagged: $humanFlagged,
        );
    }

    private static function mentionsDispute(string $question, TicketCategory $category): bool
    {
        if ($category !== TicketCategory::Billing) {
            return false;
        }

        return preg_match(self::DISPUTE_PATTERN, $question) === 1;
    }
}
