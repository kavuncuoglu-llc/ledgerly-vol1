<?php

namespace Tests\Feature\Ai\Triage;

use App\Ai\Listeners\TriageTicket;
use App\Events\TicketReceived;
use App\Models\Ticket;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TriageQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_received_ticket_is_triaged_on_the_background_ai_queue(): void
    {
        Queue::fake();
        $ticket = Ticket::factory()->create();

        TicketReceived::dispatch($ticket);

        Queue::assertPushedOn('ai-batch', CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === TriageTicket::class);
    }

    public function test_the_listener_timeout_sits_above_the_agent_budget(): void
    {
        $listener = app(TriageTicket::class);

        $this->assertGreaterThan(config('ai.agents.triage.max_seconds'), $listener->timeout);
        $this->assertSame(180, $listener->timeout);
        $this->assertSame(3, $listener->tries);
        $this->assertSame([5, 15, 60], $listener->backoff());
    }
}
