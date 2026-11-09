<?php

namespace Tests\Unit\Ai\Triage;

use App\Ai\Schemas\TicketData;
use App\Ai\Triage\UrgencyRule;
use App\Enums\TicketCategory;
use App\Models\Customer;
use Tests\TestCase;

class UrgencyRuleTest extends TestCase
{
    public function test_a_billing_message_naming_a_large_amount_is_urgent(): void
    {
        $this->assertTrue(UrgencyRule::evaluate(TicketCategory::Billing, $this->fields(amount: 1284.50), $this->customer('free')));
    }

    public function test_a_billing_message_with_a_small_or_no_amount_is_not(): void
    {
        $this->assertFalse(UrgencyRule::evaluate(TicketCategory::Billing, $this->fields(amount: 89.0), $this->customer('business')));
        $this->assertFalse(UrgencyRule::evaluate(TicketCategory::Billing, $this->fields(amount: null), $this->customer('business')));
    }

    public function test_the_threshold_comes_from_config(): void
    {
        config()->set('ai.triage.urgent_amount', 50);

        $this->assertTrue(UrgencyRule::evaluate(TicketCategory::Billing, $this->fields(amount: 89.0), null));
    }

    public function test_account_and_bug_tickets_are_urgent_only_on_the_business_plan(): void
    {
        $this->assertTrue(UrgencyRule::evaluate(TicketCategory::Account, $this->fields(), $this->customer('business')));
        $this->assertTrue(UrgencyRule::evaluate(TicketCategory::Bug, $this->fields(), $this->customer('business')));
        $this->assertFalse(UrgencyRule::evaluate(TicketCategory::Account, $this->fields(), $this->customer('pro')));
        $this->assertFalse(UrgencyRule::evaluate(TicketCategory::Bug, $this->fields(), null));
    }

    public function test_how_to_questions_are_never_urgent(): void
    {
        $this->assertFalse(UrgencyRule::evaluate(TicketCategory::Howto, $this->fields(), $this->customer('business')));
    }

    private function fields(?float $amount = null): TicketData
    {
        return new TicketData(TicketCategory::Billing, null, null, $amount, null);
    }

    private function customer(string $plan): Customer
    {
        return new Customer(['plan' => $plan]);
    }
}
