<?php

namespace Tests\Feature\Ai;

use App\Ai\Contracts\ChatRequest;
use App\Ai\Facades\Ai;
use App\Ai\Triage\TriageRunner;
use App\Enums\TicketCategory;
use App\Models\TriageRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesTriageModel;
use Tests\TestCase;

class TriagePipelineAgainstFakeTest extends TestCase
{
    use FakesTriageModel;
    use RefreshDatabase;

    public function test_a_billing_ticket_runs_the_whole_pipeline_with_no_model_and_no_http(): void
    {
        Http::preventStrayRequests();
        $ticket = $this->ticketForOwner();
        $this->invoiceFor($ticket->customer->owner);
        $this->scriptBillingRun();

        $run = app(TriageRunner::class)->triage($ticket, "ticket:{$ticket->id}");

        $this->assertSame(TriageRun::STATUS_QUEUED_FOR_REVIEW, $run->status);
        $this->assertSame('billing', $run->category);
        $this->assertSame(['billing', 'billing', 'billing'], $run->votes);
        $this->assertSame('4471', $run->fields['invoice_number']);
        $this->assertSame('overdue', $run->findings['invoices'][0]['status']);
        $this->assertSame('Findings gathered.', $run->findings['conclusion']);
        $this->assertStringContainsString('#4471', $run->draft);
        $this->assertSame(TicketCategory::Billing, $ticket->fresh()->category);
    }

    public function test_the_features_ask_for_the_options_their_profiles_promise(): void
    {
        $ticket = $this->ticketForOwner();
        $this->invoiceFor($ticket->customer->owner);
        $this->scriptBillingRun();

        app(TriageRunner::class)->triage($ticket, "ticket:{$ticket->id}");

        Ai::assertSent(fn (ChatRequest $request): bool => $request->feature === 'ticket.classify' && $request->options['temperature'] === 0.7);
        Ai::assertSent(fn (ChatRequest $request): bool => $request->feature === 'ticket.extract' && $request->options['temperature'] === 0);
        Ai::assertSent(fn (ChatRequest $request): bool => $request->feature === 'ticket.draft' && $request->options['temperature'] === 0.2);
        Ai::assertSentCount('ticket.classify', 3);
        Ai::assertSentCount('agent.investigate', 2);
        Ai::assertSentCount('ticket.draft', 1);
    }

    public function test_the_cost_rule_holds_nothing_is_sent_to_the_frontier_provider(): void
    {
        $ticket = $this->ticketForOwner();
        $this->invoiceFor($ticket->customer->owner);
        $this->scriptBillingRun();

        app(TriageRunner::class)->triage($ticket, "ticket:{$ticket->id}");

        Ai::assertNothingSentTo('frontier');
    }

    private function scriptBillingRun(): void
    {
        Ai::fake()
            ->for('ticket.classify')->respondWithJson($this->ticketFields(), times: 3)
            ->for('ticket.extract')->respondWithJson($this->ticketFields())
            ->for('agent.investigate')->respondWithToolCall('lookup_invoice', ['invoice_number' => '4471', 'month' => null])
            ->for('agent.investigate')->respondWith('Findings gathered.')
            ->for('ticket.draft')->respondWith('Hi, invoice #4471 shows as overdue and a teammate will confirm your payment.');
    }

    private function ticketFields(): array
    {
        return json_decode($this->ticketJson('billing', '4471'), true);
    }
}
