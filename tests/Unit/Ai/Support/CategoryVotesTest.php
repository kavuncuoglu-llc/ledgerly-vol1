<?php

namespace Tests\Unit\Ai\Support;

use App\Ai\Support\CategoryVotes;
use App\Enums\TicketCategory;
use PHPUnit\Framework\TestCase;

class CategoryVotesTest extends TestCase
{
    public function test_a_unanimous_vote_has_full_agreement(): void
    {
        $votes = new CategoryVotes([TicketCategory::Billing, TicketCategory::Billing, TicketCategory::Billing]);

        $this->assertSame(TicketCategory::Billing, $votes->majority());
        $this->assertSame(1.0, $votes->agreement());
        $this->assertSame(['billing'], $votes->labels());
    }

    public function test_a_two_to_one_split_names_the_majority_and_both_labels(): void
    {
        $votes = new CategoryVotes([TicketCategory::Bug, TicketCategory::Billing, TicketCategory::Bug]);

        $this->assertSame(TicketCategory::Bug, $votes->majority());
        $this->assertEqualsWithDelta(0.667, $votes->agreement(), 0.001);
        $this->assertSame(['bug', 'billing'], $votes->labels());
        $this->assertSame(['bug', 'billing', 'bug'], $votes->values());
    }

    public function test_a_three_way_tie_goes_to_the_first_vote_with_low_agreement(): void
    {
        $votes = new CategoryVotes([TicketCategory::Account, TicketCategory::Bug, TicketCategory::Howto]);

        $this->assertSame(TicketCategory::Account, $votes->majority());
        $this->assertEqualsWithDelta(0.333, $votes->agreement(), 0.001);
    }

    public function test_no_votes_means_other_with_no_agreement(): void
    {
        $votes = new CategoryVotes([]);

        $this->assertSame(TicketCategory::Other, $votes->majority());
        $this->assertSame(0.0, $votes->agreement());
        $this->assertSame(0, $votes->count());
    }
}
