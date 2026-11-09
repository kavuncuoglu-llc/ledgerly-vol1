<?php

namespace Tests\Unit\Ai\Triage;

use App\Ai\Triage\Assignment;
use App\Enums\TicketCategory;
use PHPUnit\Framework\TestCase;

class AssignmentTest extends TestCase
{
    public function test_each_category_has_a_team(): void
    {
        $this->assertSame('billing-team', Assignment::for(TicketCategory::Billing, false));
        $this->assertSame('docs-team', Assignment::for(TicketCategory::Howto, false));
        $this->assertSame('account-team', Assignment::for(TicketCategory::Account, true));
        $this->assertSame('engineering', Assignment::for(TicketCategory::Bug, false));
    }

    public function test_an_urgent_bug_goes_to_the_on_call_engineer(): void
    {
        $this->assertSame('engineering-oncall', Assignment::for(TicketCategory::Bug, true));
    }

    public function test_other_goes_to_the_support_lead(): void
    {
        $this->assertSame(Assignment::HUMAN_REVIEW, Assignment::for(TicketCategory::Other, false));
    }
}
