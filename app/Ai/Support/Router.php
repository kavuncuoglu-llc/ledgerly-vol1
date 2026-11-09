<?php

namespace App\Ai\Support;

use App\Ai\Tiers\ModelTier;
use App\Ai\Tiers\RouteSignals;
use App\Enums\TicketCategory;

final class Router
{
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

        $tier = $this->tierFor(RouteSignals::for($question, $category, $agreement));

        return match ($category) {
            TicketCategory::Howto => $this->automate($category, $agreement, $votes, tools: [], area: '', cache: true, tier: $tier),
            TicketCategory::Billing => $this->automate($category, $agreement, $votes, tools: ['lookup_invoice', 'list_invoices', 'open_ticket'], area: '', cache: false, tier: $tier),
            TicketCategory::Bug => $this->automate($category, $agreement, $votes, tools: ['open_ticket'], area: 'troubleshooting', cache: false, tier: $tier, escalateAfterRounds: 2),
            TicketCategory::Account => $this->automate($category, $agreement, $votes, tools: ['account_status', 'open_ticket'], area: 'account', cache: false, tier: $tier),
            TicketCategory::Other => Route::decline($category, $agreement, $votes->values()),
        };
    }

    public function tierFor(RouteSignals $signals): ModelTier
    {
        if ($signals->humanFlagged) {
            return ModelTier::Frontier;
        }

        if ($signals->unresolvedRounds >= (int) config('ai.routing.frontier_after_rounds')) {
            return ModelTier::Frontier;
        }

        if ($signals->agreement < (float) config('ai.routing.mid_below_agreement')) {
            return ModelTier::Mid;
        }

        if ($signals->multiAsk) {
            return ModelTier::Mid;
        }

        if ($signals->isDispute) {
            return ModelTier::Mid;
        }

        return ModelTier::Small;
    }

    private function automate(TicketCategory $category, float $agreement, CategoryVotes $votes, array $tools, string $area, bool $cache, ModelTier $tier, int $escalateAfterRounds = 0): Route
    {
        return Route::make(
            category: $category,
            agreement: $agreement,
            votes: $votes->values(),
            tools: $tools,
            area: $area,
            tier: $tier->value,
            cache: $cache,
            escalateAfterRounds: $escalateAfterRounds,
        );
    }
}
