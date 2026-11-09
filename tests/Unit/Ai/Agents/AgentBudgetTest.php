<?php

namespace Tests\Unit\Ai\Agents;

use App\Ai\Agents\AgentBudget;
use App\Ai\Agents\AgentState;
use App\Ai\Http\Usage;
use App\Ai\Tools\ToolKind;
use App\Ai\Tools\ToolResult;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AgentBudgetTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_allows_a_fresh_run(): void
    {
        $budget = $this->budget();

        $this->assertTrue($budget->allows());
        $this->assertSame('', $budget->reason());
    }

    public function test_stops_on_rounds(): void
    {
        $budget = $this->budget(maxRounds: 2);

        $budget->recordRound(new Usage(10, 5));
        $budget->recordRound(new Usage(10, 5));

        $this->assertFalse($budget->allows());
        $this->assertSame('rounds', $budget->reason());
    }

    public function test_stops_on_tokens_counting_prompt_and_completion(): void
    {
        $budget = $this->budget(maxTokens: 100);

        $budget->recordRound(new Usage(70, 30));

        $this->assertSame('tokens', $budget->reason());
        $this->assertSame(100, $budget->usage()['tokens']);
    }

    public function test_stops_on_wall_clock_time(): void
    {
        Carbon::setTestNow('2026-11-09 10:00:00');
        $budget = $this->budget(maxSeconds: 30);

        Carbon::setTestNow('2026-11-09 10:00:31');

        $this->assertSame('time', $budget->reason());
    }

    public function test_stops_on_writes_but_not_on_reads(): void
    {
        $budget = $this->budget(maxWrites: 1);

        $budget->recordCall(ToolKind::Read, ToolResult::ok());

        $this->assertTrue($budget->allows());

        $budget->recordCall(ToolKind::WriteLowRisk, ToolResult::ok(['created' => false]));

        $this->assertSame('writes', $budget->reason());
    }

    public function test_a_write_that_failed_or_was_denied_does_not_count(): void
    {
        $budget = $this->budget(maxWrites: 1);

        $budget->recordCall(ToolKind::WriteLowRisk, ToolResult::error('invalid arguments'));
        $budget->recordCall(ToolKind::WriteLowRisk, ToolResult::denied('limit reached'));
        $budget->recordCall(null, ToolResult::error('unknown tool'));

        $this->assertTrue($budget->allows());
    }

    public function test_two_rounds_that_leave_the_state_unchanged_end_the_run_as_no_progress(): void
    {
        $budget = $this->budget();
        $state = AgentState::fresh('goal');
        $budget->begin($state);

        $budget->observeProgress($state);

        $this->assertTrue($budget->allows());

        $budget->observeProgress($state);

        $this->assertSame('no_progress', $budget->reason());
    }

    public function test_a_changed_state_resets_the_progress_check(): void
    {
        $budget = $this->budget();
        $state = AgentState::fresh('goal');
        $budget->begin($state);
        $budget->observeProgress($state);

        $budget->observeProgress($state->withFinding('invoice', 'INV-4471'));
        $budget->observeProgress($state->withFinding('invoice', 'INV-4471'));

        $this->assertTrue($budget->allows());
    }

    public function test_restore_carries_usage_and_elapsed_time_into_a_new_budget(): void
    {
        Carbon::setTestNow('2026-11-09 10:00:00');

        $budget = $this->budget(maxRounds: 3, maxSeconds: 60)->restore([
            'rounds' => 2,
            'tokens' => 900,
            'writes' => 1,
            'seconds' => 50,
        ]);

        $this->assertSame(2, $budget->rounds());
        $this->assertSame(900, $budget->usage()['tokens']);

        Carbon::setTestNow('2026-11-09 10:00:11');

        $this->assertSame('time', $budget->reason());
    }

    public function test_from_config_reads_the_four_limits(): void
    {
        $budget = AgentBudget::fromConfig(config('ai.agents.triage'));

        $usage = $budget->usage();

        $this->assertSame(8, $usage['max_rounds']);
        $this->assertSame(30000, $usage['max_tokens']);
        $this->assertSame(120, $usage['max_seconds']);
        $this->assertSame(2, $usage['max_writes']);
    }

    private function budget(int $maxRounds = 10, int $maxTokens = 10000, int $maxSeconds = 600, int $maxWrites = 5): AgentBudget
    {
        return new AgentBudget($maxRounds, $maxTokens, $maxSeconds, $maxWrites);
    }
}
