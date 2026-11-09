<?php

namespace Tests\Feature\Ai\Tools;

use App\Ai\Exceptions\ApprovalAlreadyResolved;
use App\Ai\Tools\LookupInvoice\LookupInvoice;
use App\Ai\Tools\OpenTicket\OpenTicket;
use App\Ai\Tools\ToolApprovals;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRegistry;
use App\Ai\Tools\ToolRunner;
use App\Ai\Tools\ToolRunResult;
use App\Models\ModelCall;
use App\Models\PendingToolCall;
use App\Models\Ticket;
use App\Models\ToolCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\ExplodingTool;
use Tests\Support\FakesToolModel;
use Tests\Support\RefundTool;
use Tests\TestCase;

class ToolRunnerTest extends TestCase
{
    use FakesToolModel;
    use RefreshDatabase;

    private RefundTool $refund;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        $this->refund = new RefundTool;

        $this->app->singleton(ToolRegistry::class, fn () => (new ToolRegistry)
            ->add(new LookupInvoice)
            ->add(new OpenTicket)
            ->add(new ExplodingTool)
            ->add($this->refund));
    }

    public function test_run_returns_the_answer_in_one_round_when_the_model_calls_no_tool(): void
    {
        Http::fake(['*' => Http::response($this->answerResponse('Hello.'))]);

        $result = $this->runAssistant($this->customerUser());

        $this->assertSame('Hello.', $result->answer());
        $this->assertSame(1, $result->rounds);
        $this->assertFalse($result->hitCap);
        $this->assertSame(0, ToolCall::query()->count());
    }

    public function test_run_sends_the_tool_definitions_with_the_request(): void
    {
        Http::fake(['*' => Http::response($this->answerResponse('Hello.'))]);

        $this->runAssistant($this->customerUser());

        Http::assertSent(fn (Request $request): bool => collect($request['tools'])->pluck('function.name')->contains('lookup_invoice')
            && $request['tools'][0]['type'] === 'function');
    }

    public function test_run_executes_the_tool_appends_both_messages_and_calls_the_model_again(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);
        $call = $this->toolCall('call_8a1', 'lookup_invoice', ['invoice_number' => '#4471', 'month' => null]);
        Http::fakeSequence()
            ->push($this->toolCallResponse([$call]))
            ->push($this->answerResponse('It is 18 days overdue.'));

        $result = $this->runAssistant($user);

        $this->assertSame('It is 18 days overdue.', $result->answer());
        $this->assertSame(2, $result->rounds);
        $secondRequest = Http::recorded()[1][0];
        $messages = $secondRequest['messages'];
        $assistantTurn = $messages[count($messages) - 2];
        $toolTurn = $messages[count($messages) - 1];
        $this->assertSame('assistant', $assistantTurn['role']);
        $this->assertSame([$call], $assistantTurn['tool_calls']);
        $this->assertSame('tool', $toolTurn['role']);
        $this->assertSame('call_8a1', $toolTurn['tool_call_id']);
        $payload = json_decode($toolTurn['content'], true);
        $this->assertTrue($payload['ok']);
        $this->assertSame('INV-4471', $payload['invoice']['number']);
        $this->assertSame(18, $payload['invoice']['days_overdue']);
    }

    public function test_run_writes_an_audit_row_linked_to_the_model_call_that_asked(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->toolCall('call_8a1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null])]))
            ->push($this->answerResponse('Done.'));

        $this->runAssistant($user);

        $audit = ToolCall::query()->sole();
        $this->assertSame('lookup_invoice', $audit->tool);
        $this->assertSame('allowed', $audit->decision);
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame('call_8a1', $audit->tool_call_id);
        $this->assertSame('web', $audit->source);
        $this->assertSame('4471', $audit->arguments['invoice_number']);
        $this->assertTrue($audit->result['ok']);
        $this->assertSame(ModelCall::query()->orderBy('id')->first()->id, $audit->model_call_id);
        $this->assertSame(2, ModelCall::query()->count());
    }

    public function test_run_answers_every_parallel_call_with_its_own_tool_message(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user, 'INV-4471');
        $this->invoiceFor($user, 'INV-4438', 41);
        Http::fakeSequence()
            ->push($this->toolCallResponse([
                $this->toolCall('call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null]),
                $this->toolCall('call_2', 'lookup_invoice', ['invoice_number' => 'INV-4438', 'month' => null]),
            ]))
            ->push($this->answerResponse('Both are overdue.'));

        $this->runAssistant($user);

        $messages = Http::recorded()[1][0]['messages'];
        $toolTurns = array_values(array_filter($messages, fn (array $message): bool => $message['role'] === 'tool'));
        $this->assertSame(['call_1', 'call_2'], array_column($toolTurns, 'tool_call_id'));
        $this->assertSame('INV-4471', json_decode($toolTurns[0]['content'], true)['invoice']['number']);
        $this->assertSame('INV-4438', json_decode($toolTurns[1]['content'], true)['invoice']['number']);
        $this->assertSame(2, ToolCall::query()->count());
    }

    public function test_run_stops_at_the_round_cap_and_flags_it(): void
    {
        $user = $this->customerUser();
        $call = $this->toolCall('call_x', 'lookup_invoice', ['invoice_number' => '1', 'month' => null]);
        Http::fake(['*' => Http::response($this->toolCallResponse([$call]))]);

        $result = $this->runAssistant($user);

        $this->assertTrue($result->hitCap);
        $this->assertSame(ToolRunner::MAX_ROUNDS, $result->rounds);
        $this->assertCount(ToolRunner::MAX_ROUNDS, Http::recorded());
        $this->assertSame(ToolRunner::MAX_ROUNDS, ModelCall::query()->count());
    }

    public function test_run_tells_the_model_unknown_tool_without_running_anything(): void
    {
        $user = $this->customerUser();
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->toolCall('call_1', 'drop_tables', [])]))
            ->push($this->answerResponse('Sorry.'));

        $this->runAssistant($user);

        $toolTurn = collect(Http::recorded()[1][0]['messages'])->last();
        $this->assertSame(['ok' => false, 'error' => 'unknown tool'], json_decode($toolTurn['content'], true));
        $this->assertSame('denied', ToolCall::query()->sole()->decision);
    }

    public function test_run_returns_validation_errors_to_the_model_and_skips_the_tool(): void
    {
        $user = $this->customerUser();
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->toolCall('call_1', 'lookup_invoice', ['invoice_number' => null, 'month' => null])]))
            ->push($this->answerResponse('Which invoice?'));

        $this->runAssistant($user);

        $toolTurn = collect(Http::recorded()[1][0]['messages'])->last();
        $payload = json_decode($toolTurn['content'], true);
        $this->assertFalse($payload['ok']);
        $this->assertSame('invalid arguments', $payload['error']);
        $this->assertArrayHasKey('invoice_number', $payload['details']);
        $this->assertSame('invalid', ToolCall::query()->sole()->decision);
    }

    public function test_run_rejects_a_name_where_an_invoice_number_belongs(): void
    {
        $user = $this->customerUser();
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->toolCall('call_1', 'lookup_invoice', ['invoice_number' => 'Acme Studios', 'month' => null])]))
            ->push($this->answerResponse('Which invoice?'));

        $this->runAssistant($user);

        $this->assertSame('invalid', ToolCall::query()->sole()->decision);
    }

    public function test_run_reports_malformed_argument_json_as_invalid_arguments(): void
    {
        $user = $this->customerUser();
        $call = $this->toolCall('call_1', 'lookup_invoice', []);
        $call['function']['arguments'] = '{"invoice_number":';
        Http::fakeSequence()
            ->push($this->toolCallResponse([$call]))
            ->push($this->answerResponse('Sorry.'));

        $this->runAssistant($user);

        $toolTurn = collect(Http::recorded()[1][0]['messages'])->last();
        $this->assertSame('invalid arguments', json_decode($toolTurn['content'], true)['error']);
    }

    public function test_run_tells_the_model_only_not_permitted_when_the_gate_denies(): void
    {
        $user = User::factory()->create();
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->toolCall('call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null])]))
            ->push($this->answerResponse('I cannot do that.'));

        $this->runAssistant($user);

        $toolTurn = collect(Http::recorded()[1][0]['messages'])->last();
        $this->assertSame(['ok' => false, 'error' => 'not permitted'], json_decode($toolTurn['content'], true));
        $audit = ToolCall::query()->sole();
        $this->assertSame('denied', $audit->decision);
        $this->assertSame('not permitted', $audit->result['reason']);
    }

    public function test_run_does_not_leak_exception_details_to_the_model(): void
    {
        $user = $this->customerUser();
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->toolCall('call_1', 'explode', ['invoice_number' => '1', 'month' => null])]))
            ->push($this->answerResponse('Sorry.'));

        $this->runAssistant($user);

        $toolTurn = collect(Http::recorded()[1][0]['messages'])->last();
        $this->assertStringNotContainsString('SQLSTATE', $toolTurn['content']);
        $this->assertSame('tool failed', json_decode($toolTurn['content'], true)['error']);
    }

    public function test_open_ticket_stops_at_its_per_conversation_limit_in_memory(): void
    {
        $user = $this->customerUser();
        $ctx = new ToolContext(user: $user);
        $runner = app(ToolRunner::class);
        $tool = app(ToolRegistry::class)->get('open_ticket');
        $results = [];

        foreach (range(1, 4) as $attempt) {
            $results[] = $runner->executeOne($tool, ['summary' => "Payment {$attempt} is missing", 'invoice_number' => null], $ctx->forToolCall("call_{$attempt}"));
        }

        $this->assertTrue($results[2]->ok);
        $this->assertSame('denied', $results[3]->status);
        $this->assertSame('limit reached', $results[3]->reason);
        $this->assertSame(3, Ticket::query()->count());
    }

    public function test_open_ticket_stops_at_its_per_conversation_limit_from_the_audit_log(): void
    {
        $user = $this->customerUser();
        $runner = app(ToolRunner::class);
        $tool = app(ToolRegistry::class)->get('open_ticket');
        $results = [];

        foreach (range(1, 4) as $attempt) {
            $ctx = new ToolContext(user: $user, conversationId: 77, toolCallId: "call_{$attempt}");
            $results[] = $runner->executeOne($tool, ['summary' => "Payment {$attempt} is missing", 'invoice_number' => null], $ctx);
        }

        $this->assertTrue($results[2]->ok);
        $this->assertSame('limit reached', $results[3]->reason);
    }

    public function test_run_marks_requests_not_parallel_when_a_high_risk_tool_is_registered(): void
    {
        Http::fake(['*' => Http::response($this->answerResponse('Hello.'))]);

        $this->runAssistant($this->customerUser());

        Http::assertSent(fn (Request $request): bool => $request['parallel_tool_calls'] === false);
    }

    public function test_run_pauses_on_a_high_risk_call_and_stores_the_state_to_resume(): void
    {
        $user = $this->customerUser();
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->toolCall('call_r1', 'issue_refund', ['invoice' => '4471', 'amount' => 21.6])]));

        $result = $this->runAssistant($user);

        $this->assertTrue($result->hasPending());
        $this->assertSame(0, $this->refund->executions);
        $this->assertCount(1, Http::recorded());
        $pending = PendingToolCall::query()->sole();
        $this->assertSame('Refund $21.6 late fee on invoice 4471', $pending->summary);
        $this->assertSame('pending', $pending->status);
        $this->assertSame('call_r1', $pending->tool_call_id);
        $this->assertSame($user->id, $pending->user_id);
        $this->assertSame('assistant', collect($pending->messages)->last()['role']);
        $this->assertSame('pending_approval', ToolCall::query()->sole()->decision);
        $this->assertStringContainsString("support/approvals/{$pending->id}", $result->pending[0]->approvalUrl);
    }

    public function test_approving_runs_the_tool_and_resumes_the_conversation(): void
    {
        $user = $this->customerUser();
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->toolCall('call_r1', 'issue_refund', ['invoice' => '4471', 'amount' => 21.6])]))
            ->push($this->answerResponse('The refund went through.'));
        $this->runAssistant($user);

        $outcome = app(ToolApprovals::class)->approve(PendingToolCall::query()->sole());

        $this->assertSame(1, $this->refund->executions);
        $this->assertSame('The refund went through.', $outcome->run->answer());
        $this->assertSame(2, $outcome->run->rounds);
        $resumedMessages = Http::recorded()[1][0]['messages'];
        $toolTurn = collect($resumedMessages)->last();
        $this->assertSame('call_r1', $toolTurn['tool_call_id']);
        $this->assertSame(['ok' => true, 'refunded' => 21.6], json_decode($toolTurn['content'], true));
        $this->assertSame(['pending_approval', 'approved'], ToolCall::query()->orderBy('id')->pluck('decision')->all());
        $this->assertSame('approved', PendingToolCall::query()->sole()->status);
    }

    public function test_declining_never_runs_the_tool_and_tells_the_model_it_was_declined(): void
    {
        $user = $this->customerUser();
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->toolCall('call_r1', 'issue_refund', ['invoice' => '4471', 'amount' => 21.6])]))
            ->push($this->answerResponse('Understood, I will not refund it.'));
        $this->runAssistant($user);

        $outcome = app(ToolApprovals::class)->decline(PendingToolCall::query()->sole());

        $this->assertSame(0, $this->refund->executions);
        $this->assertSame('Understood, I will not refund it.', $outcome->run->answer());
        $toolTurn = collect(Http::recorded()[1][0]['messages'])->last();
        $this->assertSame(['ok' => false, 'error' => 'declined by user'], json_decode($toolTurn['content'], true));
        $this->assertSame('declined', ToolCall::query()->orderByDesc('id')->first()->decision);
    }

    public function test_a_resolved_approval_cannot_be_resolved_again(): void
    {
        $user = $this->customerUser();
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->toolCall('call_r1', 'issue_refund', ['invoice' => '4471', 'amount' => 21.6])]))
            ->push($this->answerResponse('Done.'));
        $this->runAssistant($user);
        $pending = PendingToolCall::query()->sole();
        app(ToolApprovals::class)->approve($pending);

        $this->expectException(ApprovalAlreadyResolved::class);

        app(ToolApprovals::class)->approve($pending);
    }

    public function test_approving_one_of_two_pending_calls_waits_for_the_other(): void
    {
        $user = $this->customerUser();
        Http::fakeSequence()
            ->push($this->toolCallResponse([
                $this->toolCall('call_r1', 'issue_refund', ['invoice' => '4471', 'amount' => 21.6]),
                $this->toolCall('call_r2', 'issue_refund', ['invoice' => '4438', 'amount' => 5]),
            ]))
            ->push($this->answerResponse('Both refunded.'));
        $this->runAssistant($user);
        $pending = PendingToolCall::query()->orderBy('position')->get();

        $first = app(ToolApprovals::class)->approve($pending[0]);
        $second = app(ToolApprovals::class)->approve($pending[1]);

        $this->assertNull($first->run);
        $this->assertSame('Both refunded.', $second->run->answer());
        $this->assertCount(2, Http::recorded());
        $toolTurns = array_values(array_filter(Http::recorded()[1][0]['messages'], fn (array $message): bool => $message['role'] === 'tool'));
        $this->assertSame(['call_r1', 'call_r2'], array_column($toolTurns, 'tool_call_id'));
    }

    private function runAssistant(User $user): ToolRunResult
    {
        return app(ToolRunner::class)->run(
            messages: [['role' => 'user', 'content' => 'Hi']],
            ctx: new ToolContext(user: $user),
            options: ['profile' => 'assistant'],
            feature: 'support.reply',
        );
    }
}
