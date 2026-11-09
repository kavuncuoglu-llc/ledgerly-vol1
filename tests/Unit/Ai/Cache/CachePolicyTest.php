<?php

namespace Tests\Unit\Ai\Cache;

use App\Ai\Cache\CachePolicy;
use App\Enums\TicketCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CachePolicyTest extends TestCase
{
    public function test_only_how_to_questions_are_cacheable_by_default(): void
    {
        $policy = new CachePolicy;

        $this->assertTrue($policy->cacheable(TicketCategory::Howto));
        $this->assertFalse($policy->cacheable(TicketCategory::Billing));
        $this->assertFalse($policy->cacheable(TicketCategory::Account));
        $this->assertFalse($policy->cacheable(TicketCategory::Bug));
        $this->assertFalse($policy->cacheable(TicketCategory::Other));
    }

    public function test_threshold_comes_from_config_and_an_unlisted_category_can_never_match(): void
    {
        config()->set('ai.semantic_cache.thresholds', ['howto' => 0.95]);
        $policy = new CachePolicy;

        $this->assertSame(0.95, $policy->threshold(TicketCategory::Howto));
        $this->assertSame(1.0, $policy->threshold(TicketCategory::Billing));
    }

    #[DataProvider('genericQuestions')]
    public function test_question_is_generic_when_nothing_in_it_points_at_an_account(string $question): void
    {
        $this->assertTrue((new CachePolicy)->questionIsGeneric($question));
    }

    #[DataProvider('accountQuestions')]
    public function test_question_is_not_generic_when_it_points_at_an_account(string $question): void
    {
        $this->assertFalse((new CachePolicy)->questionIsGeneric($question));
    }

    #[DataProvider('storableAnswers')]
    public function test_answer_is_storable_when_it_is_a_real_answer(string $answer): void
    {
        $this->assertTrue((new CachePolicy)->answerIsStorable($answer));
    }

    #[DataProvider('unstorableAnswers')]
    public function test_answer_is_not_storable_when_it_declines_or_carries_account_data(string $answer): void
    {
        $this->assertFalse((new CachePolicy)->answerIsStorable($answer));
    }

    public function test_expires_at_adds_the_configured_days_and_is_open_ended_when_unset(): void
    {
        config()->set('ai.semantic_cache.ttl_days', 10);
        $this->assertTrue((new CachePolicy)->expiresAt()->isSameDay(now()->addDays(10)));

        config()->set('ai.semantic_cache.ttl_days', null);
        $this->assertNull((new CachePolicy)->expiresAt());
    }

    public static function genericQuestions(): array
    {
        return [
            ['How do I set up recurring invoices?'],
            ['Can I make an invoice repeat every month?'],
            ['How do I turn on two-factor authentication?'],
        ];
    }

    public static function accountQuestions(): array
    {
        return [
            'my' => ['Why is my invoice late?'],
            'our' => ['Can we change our late fee?'],
            'invoice number' => ['What is the late fee on INV-4471?'],
            'bare invoice number' => ['Look up INV 4471 for me'],
            'any digit' => ['Which invoices are over 30 days?'],
            'an email address' => ['Send it to billing@acme.example'],
            'a month name' => ['What happened in March?'],
            'a pointer' => ['Why was that one charged?'],
            'a relative date' => ['What is due today?'],
        ];
    }

    public static function storableAnswers(): array
    {
        return [
            ['Open Recurring, choose New recurring invoice, pick an interval and save the schedule.'],
            ['Go to Settings, then Team, and press Invite. The person gets an email with a link.'],
        ];
    }

    public static function unstorableAnswers(): array
    {
        return [
            'too short' => ['Yes.'],
            'offers a ticket' => ['I do not know that, but I can open a support ticket for you about recurring schedules.'],
            'cannot help' => ["I'm not able to help with importing clients from a spreadsheet right now."],
            'outside scope' => ['That is outside what I can help with in this chat, sorry.'],
            'says it lacks context' => ["I don't have that information in the context I was given."],
            'names an invoice' => ['Invoice INV-4471 is overdue and a late fee applies after thirty days.'],
            'names an email' => ['We will send the receipt to acme@example.com after you save the schedule.'],
            'names a ticket' => ['I opened ticket T-10422 and the team will reply by email shortly.'],
        ];
    }
}
