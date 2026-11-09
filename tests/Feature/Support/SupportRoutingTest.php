<?php

namespace Tests\Feature\Support;

use App\Models\ModelCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Tests\Support\FakesTriageModel;
use Tests\TestCase;

class SupportRoutingTest extends TestCase
{
    use FakesTriageModel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        RateLimiter::clear('ai');
        config()->set('ai.routing.enabled', true);
    }

    public function test_a_billing_question_gets_only_the_invoice_and_ticket_tools(): void
    {
        $this->fakeSupportModel(['billing']);

        $this->ask('Why was I charged a late fee on invoice 4438?');

        $this->assertSame(['list_invoices', 'lookup_invoice', 'open_ticket'], $this->toolNamesOfTheReplyRequest());
    }

    public function test_a_how_to_question_gets_no_tools(): void
    {
        $this->fakeSupportModel(['howto']);

        $this->ask('How do I add my logo?');

        $this->assertNull($this->replyRequest()['tools'] ?? null);
    }

    public function test_an_account_question_gets_the_account_tools(): void
    {
        $this->fakeSupportModel(['account']);

        $this->ask('I forgot my password');

        $this->assertSame(['account_status', 'open_ticket'], $this->toolNamesOfTheReplyRequest());
    }

    public function test_an_ambiguous_question_falls_back_to_every_tool(): void
    {
        $this->fakeSupportModel(['bug', 'billing', 'account']);

        $this->ask('Something is off with my invoice export');

        $this->assertSame(['account_status', 'list_invoices', 'lookup_invoice', 'open_ticket'], $this->toolNamesOfTheReplyRequest());
    }

    public function test_the_router_runs_before_the_reply_and_its_calls_are_logged(): void
    {
        $this->fakeSupportModel(['billing']);

        $this->ask('Why was I charged a late fee on invoice 4438?');

        $this->assertSame(3, ModelCall::query()->where('feature', 'ticket.classify')->count());
        $this->assertSame(1, ModelCall::query()->where('feature', 'support.reply')->count());
    }

    public function test_routing_is_off_when_the_switch_is_off(): void
    {
        config()->set('ai.routing.enabled', false);
        $this->fakeSupportModel(['billing']);

        $this->ask('Why was I charged a late fee on invoice 4438?');

        $this->assertSame(0, ModelCall::query()->where('feature', 'ticket.classify')->count());
        $this->assertSame(['account_status', 'list_invoices', 'lookup_invoice', 'open_ticket'], $this->toolNamesOfTheReplyRequest());
    }

    private function fakeSupportModel(array $votes): void
    {
        $script = $this->triageScript(votes: $votes);

        Http::fake(function (Request $request) use ($script) {
            $system = (string) $request['messages'][0]['content'];

            if (str_starts_with($system, 'You classify')) {
                return Http::response($this->triageReply($request, $script));
            }

            return Http::response($this->answerResponse('Here is what I found.'));
        });
    }

    private function ask(string $question): void
    {
        $this->actingAs($this->customerUser())
            ->postJson('support/ask', ['question' => $question])
            ->assertOk();
    }

    private function replyRequest(): array
    {
        $request = collect(Http::recorded())
            ->map(fn (array $pair): Request => $pair[0])
            ->first(fn (Request $request): bool => str_starts_with($request['messages'][0]['content'], 'You are the support assistant'));

        return $request->data();
    }

    private function toolNamesOfTheReplyRequest(): array
    {
        return collect($this->replyRequest()['tools'])->pluck('function.name')->all();
    }
}
