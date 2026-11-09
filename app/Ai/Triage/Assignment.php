<?php

namespace App\Ai\Triage;

use App\Enums\TicketCategory;

final class Assignment
{
    public const HUMAN_REVIEW = 'support-lead';

    public static function for(TicketCategory $category, bool $urgent): string
    {
        return match ($category) {
            TicketCategory::Billing => 'billing-team',
            TicketCategory::Howto => 'docs-team',
            TicketCategory::Account => 'account-team',
            TicketCategory::Bug => $urgent
                ? 'engineering-oncall'
                : 'engineering',
            TicketCategory::Other => self::HUMAN_REVIEW,
        };
    }
}
