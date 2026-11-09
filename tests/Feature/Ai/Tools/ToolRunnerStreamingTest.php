<?php

namespace Tests\Feature\Ai\Tools;

use App\Ai\Streaming\StreamTaps;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRegistry;
use App\Ai\Tools\ToolRunner;
use App\Models\ModelCall;
use App\Models\PendingToolCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FakesStreamedModel;
use Tests\Support\FakesToolModel;
use Tests\Support\RefundTool;
use Tests\TestCase;

class ToolRunnerStreamingTest extends TestCase
{
    use FakesStreamedModel;
    use FakesToolModel;
    use RefreshDatabase;

    private array $deltas = [];

    private array $tools = [];

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_run_with_taps_streams_every_round_and_reports_the_tool_before_it_runs(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);
        Http::fakeSequence()
            ->push($this->streamedToolCall('call_1', 'lookup_invoice', ['invoice_number' => '4471']))
            ->push($this->streamedAnswer('18 days overdue.'));

        $result = app(ToolRunner::class)->run(
            messages: [['role' => 'user', 'content' => 'INV-4471?']],
            ctx: new ToolContext($user),
            taps: $this->taps(),
        );

        $this->assertSame(['lookup_invoice'], $this->tools);
        $this->assertSame('18 days overdue.', implode('', $this->deltas));
        $this->assertSame('18 days overdue.', $result->answer());
        $this->assertSame(2, $result->rounds);
        $this->assertCount(2, $result->modelCallIds);
        $this->assertSame($result->modelCallIds[1], $result->response->modelCallId);
    }

    public function test_run_without_taps_still_uses_the_non_streaming_request(): void
    {
        $user = $this->customerUser();
        Http::fakeSequence()->push($this->answerResponse('Plain.'));

        app(ToolRunner::class)->run(
            messages: [['role' => 'user', 'content' => 'Hi']],
            ctx: new ToolContext($user),
        );

        Http::assertSent(fn ($request): bool => $request['stream'] === false);
    }

    public function test_a_streamed_run_records_its_calls_with_the_conversation(): void
    {
        $user = $this->customerUser();
        Http::fakeSequence()->push($this->streamedAnswer('Hello there.'));

        app(ToolRunner::class)->run(
            messages: [['role' => 'user', 'content' => 'Hi']],
            ctx: new ToolContext($user, conversationId: 42),
            feature: 'support.reply',
            taps: $this->taps(),
        );

        $call = ModelCall::query()->sole();
        $this->assertSame(42, $call->conversation_id);
        $this->assertSame('support.reply', $call->feature);
        $this->assertNotNull($call->ttft_ms);
        $this->assertSame(20, $call->completion_tokens);
    }

    public function test_a_streamed_run_pauses_on_a_gated_tool_and_resumes_streaming(): void
    {
        $this->app->singleton(ToolRegistry::class, fn () => (new ToolRegistry)->add(new RefundTool));
        $user = $this->customerUser();
        Http::fakeSequence()
            ->push($this->streamedToolCall('call_r1', 'issue_refund', ['invoice' => '4471', 'amount' => 21.6]))
            ->push($this->streamedAnswer('Refunded.'));
        $runner = app(ToolRunner::class);

        $paused = $runner->run(
            messages: [['role' => 'user', 'content' => 'Refund me']],
            ctx: new ToolContext($user),
            taps: $this->taps(),
        );
        $pending = PendingToolCall::query()->sole();
        $pending->update(['status' => PendingToolCall::STATUS_APPROVED, 'result' => ['ok' => true]]);
        $resumed = $runner->resume($pending->run_id, $this->taps());

        $this->assertTrue($paused->hasPending());
        $this->assertSame(['issue_refund'], $this->tools);
        $this->assertSame('Refunded.', $resumed->answer());
        $this->assertSame('Refunded.', implode('', $this->deltas));
    }

    private function taps(): StreamTaps
    {
        return new StreamTaps(
            onDelta: function (string $delta): void {
                $this->deltas[] = $delta;
            },
            onToolStart: function (string $tool): void {
                $this->tools[] = $tool;
            },
        );
    }
}
