<?php

namespace Tests\Feature\Ai\Triage;

use App\Ai\Triage\TriageRunner;
use App\Enums\TicketCategory;
use App\Models\AgentRun;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TriageRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\Support\FakesTriageModel;
use Tests\TestCase;

class TriagePipelineTest extends TestCase
{
    use FakesTriageModel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_a_billing_ticket_is_classified_extracted_investigated_assigned_and_drafted(): void
    {
        $ticket = $this->ticketForOwner();
        $this->invoiceFor($ticket->customer->owner);
        $this->fakeTriageModel(
            votes: ['billing'],
            agentTurns: [
                $this->toolCallResponse([$this->toolCall('call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null])]),
                $this->answerResponse('INV-4471 is overdue by 18 days.'),
            ],
            draft: 'Invoice INV-4471 is 18 days overdue; a billing teammate will confirm the payment.',
        );

        $run = $this->triage($ticket);

        $this->assertSame(TriageRun::STATUS_QUEUED_FOR_REVIEW, $run->status);
        $this->assertSame('billing', $run->category);
        $this->assertSame(1.0, $run->agreement);
        $this->assertSame(['billing', 'billing', 'billing'], $run->votes);
        $this->assertSame('4471', $run->fields['invoice_number']);
        $this->assertTrue($run->urgent);
        $this->assertSame('billing-team', $run->assignee);
        $this->assertSame('overdue', $run->findings['invoices'][0]['status']);
        $this->assertSame('INV-4471 is overdue by 18 days.', $run->findings['conclusion']);
        $this->assertStringContainsString('18 days overdue', $run->draft);
        $this->assertNull($run->reason);
        $this->assertSame(AgentRun::STATUS_DONE, $run->agentRun->status);
        $this->assertSame($run->id, $run->agentRun->triage_run_id);
        $this->assertContains($run->id, TriageRun::query()->awaitingReview()->pluck('id')->all());
    }

    public function test_the_ticket_is_labeled_and_assigned(): void
    {
        $ticket = $this->ticketForOwner(['category' => TicketCategory::Other]);
        $this->fakeTriageModel(votes: ['billing']);

        $this->triage($ticket);

        $fresh = $ticket->fresh();
        $this->assertSame(TicketCategory::Billing, $fresh->category);
        $this->assertSame('billing-team', $fresh->assignee);
        $this->assertTrue($fresh->urgent);
    }

    public function test_the_draft_is_written_from_the_findings_and_the_ticket_text(): void
    {
        $ticket = $this->ticketForOwner();
        $this->invoiceFor($ticket->customer->owner);
        $this->fakeTriageModel(
            votes: ['billing'],
            agentTurns: [
                $this->toolCallResponse([$this->toolCall('call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null])]),
                $this->answerResponse('Overdue.'),
            ],
        );

        $this->triage($ticket);

        Http::assertSent(fn (Request $request): bool => str_starts_with($request['messages'][0]['content'], 'You draft')
            && str_contains($request['messages'][1]['content'], 'INV-4471')
            && str_contains($request['messages'][1]['content'], 'invoice 4471 still shows unpaid'));
    }

    public function test_a_how_to_ticket_has_no_agentic_step(): void
    {
        $ticket = $this->ticketForOwner(['body' => 'How do I add my logo to the invoice template?']);
        $this->fakeTriageModel(votes: ['howto'], extraction: ['category' => 'howto', 'invoiceNumber' => null, 'amountMentioned' => null]);

        $run = $this->triage($ticket);

        $this->assertSame(TriageRun::STATUS_QUEUED_FOR_REVIEW, $run->status);
        $this->assertSame('docs-team', $run->assignee);
        $this->assertFalse($run->urgent);
        $this->assertNull($run->agent_run_id);
        $this->assertSame(0, AgentRun::query()->count());
        $this->assertNotEmpty($run->draft);
    }

    public function test_an_account_ticket_investigates_with_the_account_tools_only(): void
    {
        $ticket = $this->ticketForOwner(['body' => 'I cannot sign in, it says my account is locked']);
        $this->fakeTriageModel(
            votes: ['account'],
            extraction: ['category' => 'account', 'invoiceNumber' => null, 'amountMentioned' => null],
            agentTurns: [
                $this->toolCallResponse([$this->toolCall('call_1', 'account_status', ['include_open_tickets' => true])]),
                $this->answerResponse('The account is on the pro plan with nothing overdue.'),
            ],
        );

        $run = $this->triage($ticket);

        $this->assertSame('account-team', $run->assignee);
        $this->assertStringContainsString('plan pro', $run->agentRun->trace[2]['text']);
        $this->assertSame('pro', $run->findings['account']['plan']);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request['messages'][0]['content'], 'You investigate')
            && collect($request['tools'])->pluck('function.name')->all() === ['account_status', 'open_ticket']);
    }

    public function test_a_split_vote_goes_to_a_person_unlabeled_with_no_extraction_agent_or_draft(): void
    {
        $ticket = $this->ticketForOwner(['category' => TicketCategory::Other]);
        $this->fakeTriageModel(votes: ['bug', 'billing', 'bug']);

        $run = $this->triage($ticket);

        $this->assertSame(TriageRun::STATUS_HUMAN, $run->status);
        $this->assertSame('ambiguous category: bug/billing', $run->reason);
        $this->assertSame('support-lead', $run->assignee);
        $this->assertNull($run->draft);
        $this->assertSame(TicketCategory::Other, $ticket->fresh()->category);
        $this->assertSame('support-lead', $ticket->fresh()->assignee);
        $this->assertCount(3, Http::recorded());
        $this->assertSame(0, TriageRun::query()->awaitingReview()->count());
    }

    public function test_an_off_topic_message_is_declined_to_a_person_but_labeled_other(): void
    {
        $ticket = $this->ticketForOwner(['category' => TicketCategory::Billing]);
        $this->fakeTriageModel(votes: ['other']);

        $run = $this->triage($ticket);

        $this->assertSame(TriageRun::STATUS_HUMAN, $run->status);
        $this->assertSame('not a Ledgerly support request', $run->reason);
        $this->assertSame(TicketCategory::Other, $ticket->fresh()->category);
    }

    public function test_a_ticket_whose_fields_cannot_be_extracted_goes_to_a_person(): void
    {
        Log::spy();
        $ticket = $this->ticketForOwner();
        $this->fakeTriageModel(votes: ['billing'], extraction: ['category' => 'definitely billing']);

        $run = $this->triage($ticket);

        $this->assertSame(TriageRun::STATUS_HUMAN, $run->status);
        $this->assertSame('extraction failed', $run->reason);
        $this->assertNull($run->draft);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_an_exhausted_investigation_still_hands_its_findings_to_the_draft(): void
    {
        $ticket = $this->ticketForOwner();
        $this->invoiceFor($ticket->customer->owner);
        $lookup = $this->toolCallResponse([$this->toolCall('call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null])]);
        $this->fakeTriageModel(votes: ['billing'], agentTurns: [$lookup, $lookup, $lookup, $lookup]);

        $run = $this->triage($ticket);

        $this->assertSame(AgentRun::STATUS_EXHAUSTED, $run->agentRun->status);
        $this->assertSame('no_progress', $run->agentRun->reason);
        $this->assertSame('INV-4471', $run->findings['invoices'][0]['number']);
        $this->assertSame(TriageRun::STATUS_QUEUED_FOR_REVIEW, $run->status);
        $this->assertNotEmpty($run->draft);
    }

    public function test_a_billing_ticket_for_a_customer_with_no_user_skips_the_agent(): void
    {
        $ticket = Ticket::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'body' => 'Invoice 4471 shows unpaid',
        ]);
        $this->fakeTriageModel(votes: ['billing']);

        $run = $this->triage($ticket);

        $this->assertNull($run->agent_run_id);
        $this->assertSame([], $run->findings);
        $this->assertNotEmpty($run->draft);
    }

    public function test_a_ticket_opened_by_the_agent_is_idempotent_within_one_triage_run(): void
    {
        $ticket = $this->ticketForOwner();
        $this->invoiceFor($ticket->customer->owner);
        $open = $this->toolCallResponse([$this->toolCall('call_1', 'open_ticket', ['summary' => 'Payment reconciliation for INV-4471', 'invoice_number' => '4471'])]);
        $this->fakeTriageModel(votes: ['billing'], agentTurns: [$open, $open, $open]);

        $run = $this->triage($ticket);

        $this->assertSame(1, Ticket::query()->where('source', 'triage')->count());
        $this->assertSame('writes', $run->agentRun->reason);
        $this->assertCount(1, $run->findings['tickets']);
    }

    public function test_the_run_records_how_long_it_took(): void
    {
        $this->fakeTriageModel(votes: ['howto'], extraction: ['category' => 'howto', 'invoiceNumber' => null, 'amountMentioned' => null]);

        $run = $this->triage($this->ticketForOwner());

        $this->assertGreaterThanOrEqual(0, $run->duration_ms);
    }

    private function triage(Ticket $ticket): TriageRun
    {
        return app(TriageRunner::class)->triage($ticket, "ticket:{$ticket->id}");
    }
}
