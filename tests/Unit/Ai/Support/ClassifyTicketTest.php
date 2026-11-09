<?php

namespace Tests\Unit\Ai\Support;

use App\Ai\Events\ModelCalled;
use App\Ai\Exceptions\StructuredOutputFailed;
use App\Ai\Prompts\TicketClassifyPrompt;
use App\Ai\Support\ClassifyTicket;
use App\Enums\TicketCategory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesChatCompletions;
use Tests\TestCase;

class ClassifyTicketTest extends TestCase
{
    use FakesChatCompletions;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Event::fake([ModelCalled::class]);
    }

    public function test_invoke_sends_the_prompt_messages_with_the_deterministic_profile_and_the_ticket_schema(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->ticketJson('billing')))]);

        ($this->classify())('My card was charged twice');

        Http::assertSent(fn (Request $request): bool => $request['messages'] === TicketClassifyPrompt::messages('My card was charged twice')
            && $request['temperature'] === 0
            && $request['seed'] === 42
            && $request['response_format']['type'] === 'json_schema'
            && $request['response_format']['json_schema']['name'] === 'ticket_data'
            && $request['response_format']['json_schema']['strict'] === true
            && $request['response_format']['json_schema']['schema']['properties']['category']['enum'] === ['howto', 'billing', 'bug', 'account', 'other']);
    }

    public function test_invoke_returns_the_category_enum_the_model_replied_with(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->ticketJson('billing')))]);

        $category = ($this->classify())('My card was charged twice');

        $this->assertSame(TicketCategory::Billing, $category);
    }

    public function test_invoke_repairs_a_category_outside_the_allowed_set_once(): void
    {
        Http::fakeSequence()
            ->push($this->completion($this->ticketJson('Billing issue')))
            ->push($this->completion($this->ticketJson('billing')));

        $category = ($this->classify())('My card was charged twice');

        $this->assertSame(TicketCategory::Billing, $category);
        Http::assertSentCount(2);
    }

    public function test_invoke_throws_when_the_category_is_still_invalid_after_the_repair(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->ticketJson('urgent')))]);

        $this->expectException(StructuredOutputFailed::class);

        ($this->classify())('My card was charged twice');
    }

    public function test_invoke_logs_the_call_under_ticket_classify_with_the_prompt_version(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->ticketJson('bug')))]);

        ($this->classify())('Export is broken');

        Event::assertDispatched(ModelCalled::class, fn (ModelCalled $event): bool => $event->feature === 'ticket.classify'
            && $event->promptVersion === TicketClassifyPrompt::VERSION
            && $event->repaired === false);
    }

    private function classify(): ClassifyTicket
    {
        return app(ClassifyTicket::class);
    }
}
