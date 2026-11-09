<?php

namespace App\Ai\Support;

use App\Enums\TicketCategory;

final readonly class Route
{
    public const KIND_AUTOMATE = 'automate';

    public const KIND_HUMAN = 'human';

    public const KIND_DECLINE = 'decline';

    public const KIND_NONE = 'none';

    private function __construct(
        public string $kind,
        public TicketCategory $category,
        public float $agreement,
        public array $votes = [],
        public array $tools = [],
        public string $area = '',
        public string $tier = '',
        public bool $cache = false,
        public int $escalateAfterRounds = 0,
        public string $reason = '',
        public array $candidates = [],
    ) {}

    public static function make(
        TicketCategory $category,
        float $agreement,
        array $votes,
        array $tools,
        string $area,
        string $tier,
        bool $cache,
        int $escalateAfterRounds = 0,
    ): self {
        return new self(
            kind: self::KIND_AUTOMATE,
            category: $category,
            agreement: $agreement,
            votes: $votes,
            tools: $tools,
            area: $area,
            tier: $tier,
            cache: $cache,
            escalateAfterRounds: $escalateAfterRounds,
        );
    }

    public static function human(TicketCategory $category, float $agreement, array $votes, string $reason, array $candidates): self
    {
        return new self(
            kind: self::KIND_HUMAN,
            category: $category,
            agreement: $agreement,
            votes: $votes,
            reason: $reason,
            candidates: $candidates,
        );
    }

    public static function unrouted(): self
    {
        return new self(
            kind: self::KIND_NONE,
            category: TicketCategory::Other,
            agreement: 0.0,
        );
    }

    public static function decline(TicketCategory $category, float $agreement, array $votes): self
    {
        return new self(
            kind: self::KIND_DECLINE,
            category: $category,
            agreement: $agreement,
            votes: $votes,
            reason: 'not a Ledgerly support request',
        );
    }

    public function isAutomated(): bool
    {
        return $this->kind === self::KIND_AUTOMATE;
    }

    public function isHuman(): bool
    {
        return $this->kind === self::KIND_HUMAN;
    }

    public function isDecline(): bool
    {
        return $this->kind === self::KIND_DECLINE;
    }
}
