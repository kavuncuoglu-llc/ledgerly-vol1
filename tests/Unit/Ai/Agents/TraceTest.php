<?php

namespace Tests\Unit\Ai\Agents;

use App\Ai\Agents\Trace;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\FinishReason;
use App\Ai\Contracts\Usage;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TraceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_lines_read_like_a_log_with_elapsed_seconds_and_round_numbers(): void
    {
        Carbon::setTestNow('2026-11-09 10:00:00');
        $trace = new Trace('investigate', 'state-1');
        $trace->goal('Gather the facts');
        Carbon::setTestNow(Carbon::parse('2026-11-09 10:00:00')->addMilliseconds(400));
        $trace->decision(1, $this->toolResponse('lookup_invoice', ['invoice_number' => '4471']));
        $trace->observation(1, 'invoice INV-4471: overdue');

        $this->assertSame([
            '[00.0s] goal: Gather the facts',
            '[00.4s] r1 decision → lookup_invoice(invoice_number: "4471")',
            '[00.4s] r1 result: invoice INV-4471: overdue',
        ], $trace->lines());
    }

    public function test_a_decision_without_tool_calls_is_logged_as_done(): void
    {
        $trace = new Trace('investigate', 'state-1');

        $trace->decision(3, new ChatResponse('id', 'm', 'The invoice is overdue.', [], FinishReason::Stop, new Usage(1, 1), []));

        $this->assertStringContainsString('r3 decision → done: The invoice is overdue.', $trace->lines()[0]);
    }

    public function test_budget_and_failure_events_are_logged(): void
    {
        $trace = new Trace('investigate', 'state-1');

        $trace->budget(4, 'writes');
        $trace->failure(5, 'The model server answered HTTP 500');
        $trace->skipped(4, 2);

        $lines = implode("\n", $trace->lines());

        $this->assertStringContainsString('budget: writes reached → run ends (exhausted: writes)', $lines);
        $this->assertStringContainsString('failed: The model server answered HTTP 500', $lines);
        $this->assertStringContainsString('skipped 2 further tool calls', $lines);
    }

    public function test_it_restores_from_stored_entries_and_keeps_counting_from_the_last_time(): void
    {
        Carbon::setTestNow('2026-11-09 10:00:00');
        $trace = new Trace('investigate', 'state-1');
        $trace->goal('Gather the facts');
        Carbon::setTestNow('2026-11-09 10:00:05');
        $trace->observation(1, 'first');

        Carbon::setTestNow('2026-11-09 11:00:00');
        $restored = Trace::fromArray('investigate', 'state-1', $trace->toArray());
        Carbon::setTestNow('2026-11-09 11:00:02');
        $restored->observation(2, 'second');

        $this->assertSame('[07.0s] r2 result: second', $restored->lines()[2]);
        $this->assertCount(3, $restored->toArray());
    }

    public function test_findings_are_logged_as_json(): void
    {
        $trace = new Trace('investigate', 'state-1');

        $trace->findings(['tickets' => ['T-10422']]);

        $this->assertStringContainsString('findings: {"tickets":["T-10422"]}', $trace->lines()[0]);
    }

    private function toolResponse(string $name, array $arguments): ChatResponse
    {
        return new ChatResponse(
            id: 'id',
            model: 'm',
            content: null,
            toolCalls: [
                [
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => [
                        'name' => $name,
                        'arguments' => json_encode($arguments),
                    ],
                ],
            ],
            finishReason: FinishReason::ToolCalls,
            usage: new Usage(1, 1),
            raw: [],
        );
    }
}
