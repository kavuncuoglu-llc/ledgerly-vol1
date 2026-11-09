<?php

namespace App\Ai\Support;

use App\Enums\TicketCategory;

final class Router
{
    public const TIER_LOCAL = 'local-8b';

    public function __construct(
        private readonly VoteClassifier $classifier,
        private readonly int $samples,
        private readonly float $agreementThreshold,
    ) {}

    public function route(string $question): Route
    {
        $votes = $this->classifier->sample($question, $this->samples);

        $category = $votes->majority();

        $agreement = $votes->agreement();

        if ($agreement < $this->agreementThreshold) {
            return Route::human(
                category: $category,
                agreement: $agreement,
                votes: $votes->values(),
                reason: 'ambiguous category: '.implode('/', $votes->labels()),
                candidates: $votes->labels(),
            );
        }

        return match ($category) {
            TicketCategory::Howto => $this->automate($category, $agreement, $votes, tools: [], area: '', cache: true),
            TicketCategory::Billing => $this->automate($category, $agreement, $votes, tools: ['lookup_invoice', 'list_invoices', 'open_ticket'], area: '', cache: false),
            TicketCategory::Bug => $this->automate($category, $agreement, $votes, tools: ['open_ticket'], area: 'troubleshooting', cache: false, escalateAfterRounds: 2),
            TicketCategory::Account => $this->automate($category, $agreement, $votes, tools: ['account_status', 'open_ticket'], area: 'account', cache: false),
            TicketCategory::Other => Route::decline($category, $agreement, $votes->values()),
        };
    }

    private function automate(TicketCategory $category, float $agreement, CategoryVotes $votes, array $tools, string $area, bool $cache, int $escalateAfterRounds = 0): Route
    {
        return Route::make(
            category: $category,
            agreement: $agreement,
            votes: $votes->values(),
            tools: $tools,
            area: $area,
            tier: self::TIER_LOCAL,
            cache: $cache,
            escalateAfterRounds: $escalateAfterRounds,
        );
    }
}
