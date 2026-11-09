<?php

namespace Tests\Feature\Ai;

use App\Enums\TicketCategory;
use App\Events\TicketReceived;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ClassifyReceivedTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_handle_stores_the_label_on_the_received_ticket(): void
    {
        Http::fake(['*' => Http::response($this->completion('account'))]);
        $ticket = Ticket::factory()->create(['category' => TicketCategory::Other]);

        TicketReceived::dispatch($ticket);

        $this->assertSame(TicketCategory::Account, $ticket->fresh()->category);
    }

    public function test_handle_classifies_the_ticket_body(): void
    {
        Http::fake(['*' => Http::response($this->completion('billing'))]);
        $ticket = Ticket::factory()->create(['body' => 'My card was charged twice']);

        TicketReceived::dispatch($ticket);

        Http::assertSent(function ($request): bool {
            $messages = $request['messages'];

            return end($messages)['content'] === 'My card was charged twice';
        });
    }

    public function test_handle_stores_other_when_the_model_replies_with_something_unexpected(): void
    {
        Http::fake(['*' => Http::response($this->completion('definitely billing, I think'))]);
        $ticket = Ticket::factory()->create(['category' => TicketCategory::Billing]);

        TicketReceived::dispatch($ticket);

        $this->assertSame(TicketCategory::Other, $ticket->fresh()->category);
    }

    private function completion(string $content): array
    {
        return [
            'id' => 'chatcmpl-1',
            'model' => 'llama3.1:8b',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => $content,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 120,
                'completion_tokens' => 1,
            ],
        ];
    }
}
