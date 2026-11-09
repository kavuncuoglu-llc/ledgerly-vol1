<?php

namespace Tests\Feature\Ai\Support;

use App\Ai\Support\Route;
use App\Ai\Support\Router;
use App\Enums\TicketCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\Support\FakesTriageModel;
use Tests\TestCase;

class RouterTest extends TestCase
{
    use FakesTriageModel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_route_samples_the_classifier_three_times_at_the_assistant_profile(): void
    {
        $this->fakeTriageModel(['billing']);

        $this->router()->route('Why was I charged a late fee?');

        $classifications = collect(Http::recorded())->filter(fn (array $pair): bool => str_starts_with($pair[0]['messages'][0]['content'], 'You classify'));
        $this->assertCount(3, $classifications);
        $this->assertSame(0.7, $classifications->first()[0]['temperature']);
    }

    public function test_a_unanimous_billing_vote_routes_to_the_invoice_tools(): void
    {
        $this->fakeTriageModel(['billing']);

        $route = $this->router()->route('Why was I charged a late fee?');

        $this->assertTrue($route->isAutomated());
        $this->assertSame(TicketCategory::Billing, $route->category);
        $this->assertSame(1.0, $route->agreement);
        $this->assertSame(['lookup_invoice', 'list_invoices', 'open_ticket'], $route->tools);
        $this->assertSame('small', $route->tier);
        $this->assertFalse($route->cache);
        $this->assertSame(['billing', 'billing', 'billing'], $route->votes);
    }

    public function test_how_to_gets_no_tools_and_may_use_the_cache(): void
    {
        $this->fakeTriageModel(['howto']);

        $route = $this->router()->route('How do I add my logo?');

        $this->assertSame([], $route->tools);
        $this->assertTrue($route->cache);
    }

    public function test_bug_gets_the_ticket_tool_the_troubleshooting_area_and_an_escalation_point(): void
    {
        $this->fakeTriageModel(['bug']);

        $route = $this->router()->route('Export is broken');

        $this->assertSame(['open_ticket'], $route->tools);
        $this->assertSame('troubleshooting', $route->area);
        $this->assertSame(2, $route->escalateAfterRounds);
    }

    public function test_account_gets_the_account_tools_and_area(): void
    {
        $this->fakeTriageModel(['account']);

        $route = $this->router()->route('I cannot sign in');

        $this->assertSame(['account_status', 'open_ticket'], $route->tools);
        $this->assertSame('account', $route->area);
    }

    public function test_other_is_declined(): void
    {
        $this->fakeTriageModel(['other']);

        $route = $this->router()->route('Recommend a bookkeeper');

        $this->assertTrue($route->isDecline());
        $this->assertSame([], $route->tools);
    }

    public function test_a_two_to_one_split_goes_to_a_person_with_both_labels(): void
    {
        $this->fakeTriageModel(['bug', 'billing', 'bug']);

        $route = $this->router()->route('The invoice export fails');

        $this->assertTrue($route->isHuman());
        $this->assertSame(TicketCategory::Bug, $route->category);
        $this->assertSame(['bug', 'billing'], $route->candidates);
        $this->assertSame('ambiguous category: bug/billing', $route->reason);
        $this->assertEqualsWithDelta(0.667, $route->agreement, 0.001);
    }

    public function test_a_lower_threshold_automates_a_majority(): void
    {
        config()->set('ai.routing.agreement_threshold', 0.34);
        $this->fakeTriageModel(['bug', 'billing', 'bug']);

        $route = $this->router()->route('The invoice export fails');

        $this->assertTrue($route->isAutomated());
        $this->assertSame(TicketCategory::Bug, $route->category);
    }

    public function test_a_unanimity_threshold_still_automates_a_clean_vote(): void
    {
        config()->set('ai.routing.agreement_threshold', 1.0);
        $this->fakeTriageModel(['billing']);

        $this->assertTrue($this->router()->route('Why was I charged?')->isAutomated());
    }

    public function test_a_vote_the_classifier_cannot_produce_counts_as_other_and_is_logged(): void
    {
        Log::spy();
        $this->fakeTriageModel(['definitely billing, I think']);

        $route = $this->router()->route('Why was I charged?');

        $this->assertTrue($route->isDecline());
        Log::shouldHaveReceived('warning')->times(3);
    }

    public function test_the_sample_count_comes_from_config(): void
    {
        config()->set('ai.routing.samples', 5);
        $this->fakeTriageModel(['billing']);

        $route = $this->router()->route('Why was I charged?');

        $this->assertCount(5, $route->votes);
        Http::assertSentCount(5);
    }

    public function test_unrouted_is_not_automated(): void
    {
        $this->assertFalse(Route::unrouted()->isAutomated());
        $this->assertFalse(Route::unrouted()->isHuman());
    }

    private function router(): Router
    {
        return app(Router::class);
    }
}
