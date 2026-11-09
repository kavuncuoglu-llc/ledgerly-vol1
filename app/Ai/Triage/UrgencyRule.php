<?php

namespace App\Ai\Triage;

use App\Ai\Schemas\TicketData;
use App\Enums\TicketCategory;
use App\Models\Customer;

final class UrgencyRule
{
    public const BUSINESS_PLAN = 'business';

    public static function evaluate(TicketCategory $category, TicketData $fields, ?Customer $customer): bool
    {
        if ($category === TicketCategory::Billing) {
            return self::mentionsLargeAmount($fields);
        }

        if ($customer?->plan !== self::BUSINESS_PLAN) {
            return false;
        }

        if ($category === TicketCategory::Account) {
            return true;
        }

        return $category === TicketCategory::Bug;
    }

    private static function mentionsLargeAmount(TicketData $fields): bool
    {
        if ($fields->amount_mentioned === null) {
            return false;
        }

        return $fields->amount_mentioned >= (float) config('ai.triage.urgent_amount');
    }
}
