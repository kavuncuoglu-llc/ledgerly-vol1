<?php

namespace Tests\Feature\Ai;

use App\Enums\TicketCategory;
use App\Events\TicketReceived;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\Concerns\FakesChatCompletions;
use Tests\TestCase;

class ClassifyReceivedTicketTest extends TestCase
{
    use FakesChatCompletions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_handle_stores_the_label_on_the_received_ticket(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->ticketJson('account')))]);
        $ticket = Ticket::factory()->create(['category' => TicketCategory::Other]);

        TicketReceived::dispatch($ticket);

        $this->assertSame(TicketCategory::Account, $ticket->fresh()->category);
    }

    public function test_handle_classifies_the_ticket_body(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->ticketJson('billing')))]);
        $ticket = Ticket::factory()->create(['body' => 'My card was charged twice']);

        TicketReceived::dispatch($ticket);

        Http::assertSent(function ($request): bool {
            $messages = $request['messages'];

            return end($messages)['content'] === 'My card was charged twice';
        });
    }

    public function test_handle_stores_other_and_logs_a_warning_when_the_model_stays_invalid_after_the_repair(): void
    {
        Http::fake(['*' => Http::response($this->completion($this->ticketJson('definitely billing, I think')))]);
        Log::spy();
        $ticket = Ticket::factory()->create(['category' => TicketCategory::Billing]);

        TicketReceived::dispatch($ticket);

        $this->assertSame(TicketCategory::Other, $ticket->fresh()->category);
        Log::shouldHaveReceived('warning')->once();
    }
}
