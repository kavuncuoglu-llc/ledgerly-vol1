<?php

namespace Tests\Feature\Ai\Agents;

use App\Ai\Agents\AgentResult;
use App\Ai\Agents\AgentRunner;
use App\Ai\Agents\AgentState;
use App\Ai\Agents\InvestigateAgent;
use App\Ai\Exceptions\AgentRunNotResumable;
use App\Ai\Exceptions\ModelRequestFailed;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRegistry;
use App\Enums\TicketCategory;
use App\Models\AgentRun;
use App\Models\Ticket;
use App\Models\ToolCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FakesToolModel;
use Tests\TestCase;

class AgentRunnerTest extends TestCase
{
    use FakesToolModel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_run_executes_the_tool_the_model_chose_and_finishes_when_it_answers(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->lookup('call_1')]))
            ->push($this->answerResponse('INV-4471 is 18 days overdue.'));

        $result = $this->runInvestigation($user, ['lookup_invoice']);

        $this->assertTrue($result->isDone());
        $this->assertSame('INV-4471 is 18 days overdue.', $result->content);
        $this->assertSame(2, $result->usage['rounds']);
        $this->assertSame('INV-4471', $result->state->findings['invoices'][0]['number']);
        $this->assertSame('overdue', $result->state->findings['invoices'][0]['status']);
        $this->assertSame('allowed', ToolCall::query()->sole()->decision);
    }

    public function test_run_persists_the_run_with_its_state_budget_and_trace(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->lookup('call_1')]))
            ->push($this->answerResponse('Overdue.'));

        $result = $this->runInvestigation($user, ['lookup_invoice']);

        $run = AgentRun::query()->findOrFail($result->runId);
        $this->assertSame('investigate', $run->agent);
        $this->assertSame(AgentRun::STATUS_DONE, $run->status);
        $this->assertSame($user->id, $run->user_id);
        $this->assertSame(2, $run->rounds);
        $this->assertSame(149, $run->tokens);
        $this->assertSame('Overdue.', $run->state['conclusion']);
        $this->assertSame('lookup_invoice', array_values($run->state['steps'])[0]['tool']);
        $this->assertStringContainsString('decision → lookup_invoice(invoice_number: "4471", month: null)', implode("\n", array_column($run->trace, 'text')));
    }

    public function test_run_renders_what_is_established_into_the_next_prompt_instead_of_replaying_history(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->lookup('call_1')]))
            ->push($this->answerResponse('Overdue.'));

        $this->runInvestigation($user, ['lookup_invoice'], facts: ['invoice_number' => '4471']);

        $requests = Http::recorded();
        $first = $requests[0][0]['messages'][1]['content'];
        $second = $requests[1][0]['messages'][1]['content'];
        $this->assertStringContainsString('Still to check:', $first);
        $this->assertStringContainsString('look up invoice 4471', $first);
        $this->assertStringContainsString('Established so far: nothing yet', $first);
        $this->assertStringContainsString('lookup_invoice(invoice_number: "4471", month: null) → returned invoice INV-4471: overdue', $second);
        $this->assertStringContainsString('nothing specific', $second);
        $this->assertCount(2, $requests[1][0]['messages']);
    }

    public function test_run_sends_only_the_tools_of_the_agents_subset(): void
    {
        $user = $this->customerUser();
        Http::fake(['*' => Http::response($this->answerResponse('Nothing to do.'))]);

        $this->runInvestigation($user, ['lookup_invoice', 'open_ticket']);

        Http::assertSent(fn (Request $request): bool => collect($request['tools'])->pluck('function.name')->all() === ['lookup_invoice', 'open_ticket']);
    }

    public function test_run_ends_exhausted_on_the_round_cap_and_keeps_what_it_found(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user, 'INV-4471');
        $this->invoiceFor($user, 'INV-4438', 41);
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->lookup('call_1', '4471')]))
            ->push($this->toolCallResponse([$this->lookup('call_2', '4438')]))
            ->push($this->answerResponse('never reached'));

        $result = $this->runInvestigation($user, ['lookup_invoice'], budget: ['max_rounds' => 2]);

        $this->assertTrue($result->isExhausted());
        $this->assertSame('rounds', $result->reason);
        $this->assertCount(2, $result->state->findings['invoices']);
        $this->assertSame(AgentRun::STATUS_EXHAUSTED, AgentRun::query()->findOrFail($result->runId)->status);
        $this->assertSame('rounds', AgentRun::query()->findOrFail($result->runId)->reason);
    }

    public function test_run_ends_exhausted_on_the_token_cap(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);
        Http::fake(['*' => Http::response($this->toolCallResponse([$this->lookup('call_1')]))]);

        $result = $this->runInvestigation($user, ['lookup_invoice'], budget: ['max_tokens' => 40]);

        $this->assertSame('tokens', $result->reason);
        $this->assertSame(1, $result->usage['rounds']);
    }

    public function test_run_ends_when_the_model_repeats_a_call_that_changes_nothing(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);
        Http::fake(['*' => Http::response($this->toolCallResponse([$this->lookup('call_1')]))]);

        $result = $this->runInvestigation($user, ['lookup_invoice']);

        $this->assertSame('no_progress', $result->reason);
        $this->assertSame(3, $result->usage['rounds']);
        $this->assertStringContainsString('budget: no_progress', implode("\n", $result->trace->lines()));
    }

    public function test_run_counts_writes_and_ends_at_the_cap_with_the_second_ticket_idempotent(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);
        $summary = 'Payment reconciliation for INV-4471';
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->openTicket('call_1', $summary)]))
            ->push($this->toolCallResponse([$this->openTicket('call_2', $summary)]))
            ->push($this->answerResponse('never reached'));

        $result = $this->runInvestigation($user, ['open_ticket'], triageRunId: 7);

        $this->assertSame('writes', $result->reason);
        $this->assertSame(2, $result->usage['writes']);
        $this->assertSame(1, Ticket::query()->where('source', 'triage')->count());
        $this->assertSame([Ticket::query()->where('source', 'triage')->value('number')], $result->state->findings['tickets']);
        $this->assertStringContainsString('created: false', implode("\n", $result->trace->lines()));
    }

    public function test_run_skips_the_calls_left_in_a_round_once_the_write_cap_is_reached(): void
    {
        $user = $this->customerUser();
        Http::fake(['*' => Http::response($this->toolCallResponse([
            $this->openTicket('call_1', 'First follow-up for the client'),
            $this->openTicket('call_2', 'Second follow-up for the client'),
            $this->openTicket('call_3', 'Third follow-up for the client'),
        ]))]);

        $result = $this->runInvestigation($user, ['open_ticket'], budget: ['max_writes' => 1], triageRunId: 8);

        $this->assertSame('writes', $result->reason);
        $this->assertSame(1, Ticket::query()->where('source', 'triage')->count());
        $this->assertStringContainsString('skipped 2 further tool calls', implode("\n", $result->trace->lines()));
    }

    public function test_run_refuses_a_tool_outside_the_subset_even_if_the_model_names_it(): void
    {
        $user = $this->customerUser();
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->openTicket('call_1', 'Open a ticket nobody allowed')]))
            ->push($this->answerResponse('Done.'));

        $result = $this->runInvestigation($user, ['lookup_invoice']);

        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame('denied', ToolCall::query()->sole()->decision);
        $this->assertStringContainsString('result: error: unknown tool', implode("\n", $result->trace->lines()));
    }

    public function test_run_feeds_an_invalid_argument_error_back_with_the_reason(): void
    {
        $user = $this->customerUser();
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->toolCall('call_1', 'lookup_invoice', ['invoice_number' => null, 'month' => 'last march'])]))
            ->push($this->answerResponse('Could not look it up.'));

        $this->runInvestigation($user, ['lookup_invoice']);

        $second = Http::recorded()[1][0]['messages'][1]['content'];
        $this->assertStringContainsString('error: invalid arguments (', $second);
        $this->assertStringContainsString('month', $second);
    }

    public function test_run_treats_the_string_null_the_model_sends_for_an_empty_argument_as_null(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->toolCall('call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => 'null'])]))
            ->push($this->answerResponse('Overdue.'));

        $result = $this->runInvestigation($user, ['lookup_invoice']);

        $this->assertTrue($result->isDone());
        $this->assertSame('INV-4471', $result->state->findings['invoices'][0]['number']);
    }

    public function test_run_marks_the_run_failed_when_the_model_server_rejects_a_call_and_keeps_the_state(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->lookup('call_1')]))
            ->push('bad request', 400);

        try {
            $this->runInvestigation($user, ['lookup_invoice']);
            $this->fail('The model failure should propagate.');
        } catch (ModelRequestFailed) {
        }

        $run = AgentRun::query()->sole();
        $this->assertSame(AgentRun::STATUS_FAILED, $run->status);
        $this->assertNotEmpty($run->reason);
        $this->assertCount(1, $run->state['steps']);
        $this->assertSame(1, $run->rounds);
        $this->assertStringContainsString('failed:', implode("\n", array_column($run->trace, 'text')));
    }

    public function test_resume_continues_a_failed_run_from_its_saved_state_and_budget(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->lookup('call_1')]))
            ->push('bad request', 400)
            ->push($this->answerResponse('INV-4471 is overdue.'));
        $this->failQuietly(fn () => $this->runInvestigation($user, ['lookup_invoice']));
        $run = AgentRun::query()->sole();

        $result = app(AgentRunner::class)->resume($this->agent(['lookup_invoice']), $run, new ToolContext($user));

        $this->assertTrue($result->isDone());
        $this->assertSame(2, $result->usage['rounds']);
        $this->assertSame($run->id, $result->runId);
        $this->assertSame(AgentRun::STATUS_DONE, $run->fresh()->status);
        $this->assertSame(1, AgentRun::query()->count());
        $this->assertSame('INV-4471', $result->state->findings['invoices'][0]['number']);
        Http::assertSent(fn (Request $request): bool => str_contains($request['messages'][1]['content'], 'returned invoice INV-4471'));
    }

    public function test_resume_refuses_a_run_that_did_not_fail(): void
    {
        $user = $this->customerUser();
        Http::fake(['*' => Http::response($this->answerResponse('Nothing to do.'))]);
        $result = $this->runInvestigation($user, ['lookup_invoice']);

        $this->expectException(AgentRunNotResumable::class);

        app(AgentRunner::class)->resume($this->agent(['lookup_invoice']), AgentRun::query()->findOrFail($result->runId), new ToolContext($user));
    }

    private function runInvestigation(User $user, array $tools, array $budget = [], array $facts = [], ?int $triageRunId = null): AgentResult
    {
        return app(AgentRunner::class)->run(
            $this->agent($tools, $budget),
            AgentState::fresh('Gather the facts needed to resolve: invoice 4471 shows unpaid', $facts),
            new ToolContext($user, triageRunId: $triageRunId),
        );
    }

    private function agent(array $tools, array $budget = []): InvestigateAgent
    {
        return new InvestigateAgent(
            category: TicketCategory::Billing,
            tools: app(ToolRegistry::class)->only($tools),
            budget: [
                ...config('ai.agents.triage'),
                ...$budget,
            ],
        );
    }

    private function failQuietly(callable $run): void
    {
        try {
            $run();
        } catch (ModelRequestFailed) {
        }
    }

    private function lookup(string $id, string $number = '4471'): array
    {
        return $this->toolCall($id, 'lookup_invoice', [
            'invoice_number' => $number,
            'month' => null,
        ]);
    }

    private function openTicket(string $id, string $summary): array
    {
        return $this->toolCall($id, 'open_ticket', [
            'summary' => $summary,
            'invoice_number' => null,
        ]);
    }
}
