<?php

namespace Tests\Feature\Console;

use App\Ai\Agents\AgentRunner;
use App\Ai\Agents\AgentState;
use App\Ai\Agents\InvestigateAgent;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRegistry;
use App\Enums\TicketCategory;
use App\Models\AgentRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FakesToolModel;
use Tests\TestCase;

class AgentTraceCommandTest extends TestCase
{
    use FakesToolModel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    public function test_it_prints_the_header_and_every_decision_result_and_the_findings(): void
    {
        $user = $this->customerUser();
        $this->invoiceFor($user);
        Http::fakeSequence()
            ->push($this->toolCallResponse([$this->toolCall('call_1', 'lookup_invoice', ['invoice_number' => '4471', 'month' => null])]))
            ->push($this->answerResponse('INV-4471 is overdue.'));
        $result = app(AgentRunner::class)->run(
            new InvestigateAgent(TicketCategory::Billing, app(ToolRegistry::class)->only(['lookup_invoice']), config('ai.agents.triage')),
            AgentState::fresh('Gather the facts needed to resolve: "Client paid but invoice shows unpaid"'),
            new ToolContext($user),
        );

        $this->artisan('agent:trace', ['run' => $result->runId])
            ->expectsOutputToContain("run #{$result->runId} · investigate · done · 2 rounds · 149 tokens · 0 writes")
            ->expectsOutputToContain('goal: Gather the facts needed to resolve: "Client paid but invoice shows unpaid"')
            ->expectsOutputToContain('r1 decision → lookup_invoice(invoice_number: "4471", month: null)')
            ->expectsOutputToContain('r1 result: returned invoice INV-4471: overdue, 18 days overdue')
            ->expectsOutputToContain('r2 decision → done: INV-4471 is overdue.')
            ->expectsOutputToContain('findings: {"invoices":[')
            ->assertSuccessful();
    }

    public function test_it_names_the_reason_a_run_was_exhausted(): void
    {
        $run = AgentRun::query()->create([
            'agent' => 'investigate',
            'user_id' => $this->customerUser()->id,
            'status' => AgentRun::STATUS_EXHAUSTED,
            'reason' => 'writes',
            'goal' => 'g',
            'state' => ['id' => 's1'],
            'budget' => [],
            'trace' => [['t' => 1.0, 'round' => 4, 'kind' => 'budget', 'text' => 'budget: writes reached → run ends (exhausted: writes)']],
            'rounds' => 4,
            'tokens' => 900,
            'writes' => 2,
        ]);

        $this->artisan('agent:trace', ['run' => $run->id])
            ->expectsOutputToContain('exhausted: writes · 4 rounds · 900 tokens · 2 writes')
            ->expectsOutputToContain('[01.0s] r4 budget: writes reached')
            ->assertSuccessful();
    }

    public function test_it_fails_for_an_unknown_run(): void
    {
        $this->artisan('agent:trace', ['run' => 999])
            ->expectsOutput('No such agent run.')
            ->assertFailed();
    }
}
