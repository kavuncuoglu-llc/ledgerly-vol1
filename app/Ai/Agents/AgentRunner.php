<?php

namespace App\Ai\Agents;

use App\Ai\Exceptions\AgentRunNotResumable;
use App\Ai\Http\AiHttpClient;
use App\Ai\Http\ChatResponse;
use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRunner;
use App\Models\AgentRun;
use Throwable;

final class AgentRunner
{
    public function __construct(
        private readonly AiHttpClient $client,
        private readonly ToolRunner $tools,
    ) {}

    public function run(Agent $agent, AgentState $state, ToolContext $ctx): AgentResult
    {
        $budget = $agent->budget();

        $trace = new Trace($agent->name(), $state->id);

        $trace->goal($state->goal);

        $run = AgentRun::create([
            'agent' => $agent->name(),
            'user_id' => $ctx->user->id,
            'triage_run_id' => $ctx->triageRunId,
            'status' => AgentRun::STATUS_RUNNING,
            'goal' => $state->goal,
            'state' => $state->toArray(),
            'budget' => $budget->usage(),
            'trace' => $trace->toArray(),
        ]);

        return $this->guarded($agent, $run, $state, $budget, $trace, $ctx);
    }

    public function resume(Agent $agent, AgentRun $run, ToolContext $ctx): AgentResult
    {
        if ($run->status !== AgentRun::STATUS_FAILED) {
            throw new AgentRunNotResumable($run->id, $run->status);
        }

        $state = AgentState::fromArray($run->state);

        $trace = Trace::fromArray($run->agent, $state->id, $run->trace);

        $budget = $agent->budget()->restore($run->budget);

        $run->update([
            'status' => AgentRun::STATUS_RUNNING,
            'reason' => null,
        ]);

        return $this->guarded($agent, $run, $state, $budget, $trace, $ctx);
    }

    private function guarded(Agent $agent, AgentRun $run, AgentState $state, AgentBudget $budget, Trace $trace, ToolContext $ctx): AgentResult
    {
        try {
            return $this->loop($agent, $run, $state, $budget, $trace, $ctx);
        } catch (Throwable $exception) {
            $trace->failure($budget->rounds() + 1, $exception->getMessage());

            $run->update([
                'status' => AgentRun::STATUS_FAILED,
                'reason' => $exception->getMessage(),
                'budget' => $budget->usage(),
                'trace' => $trace->toArray(),
            ]);

            throw $exception;
        }
    }

    private function loop(Agent $agent, AgentRun $run, AgentState $state, AgentBudget $budget, Trace $trace, ToolContext $ctx): AgentResult
    {
        $budget->begin($state);

        while ($budget->allows()) {
            $round = $budget->rounds() + 1;

            $response = $this->decide($agent, $state);

            $budget->recordRound($response->usage);

            $trace->decision($round, $response);

            if ($response->toolCalls === []) {
                $state = $state->withConclusion(trim((string) $response->content));

                return $this->finish($run, AgentResult::STATUS_DONE, $state, $budget, $trace, '');
            }

            $state = $this->actOn($agent, $response, $state, $budget, $trace, $ctx, $round);

            $budget->observeProgress($state);

            $this->checkpoint($run, $state, $budget, $trace);
        }

        $trace->budget($budget->rounds(), $budget->reason());

        return $this->finish($run, AgentResult::STATUS_EXHAUSTED, $state, $budget, $trace, $budget->reason());
    }

    private function decide(Agent $agent, AgentState $state): ChatResponse
    {
        return $this->client->chat(
            messages: $agent->prompt($state),
            options: [
                ...$agent->options(),
                'tools' => $agent->tools()->definitions(),
            ],
            feature: $agent->feature(),
            promptVersion: $agent->promptVersion(),
        );
    }

    private function actOn(Agent $agent, ChatResponse $response, AgentState $state, AgentBudget $budget, Trace $trace, ToolContext $ctx, int $round): AgentState
    {
        $calls = $response->toolCalls;

        foreach ($calls as $index => $call) {
            $result = $this->tools->executeCall(
                call: $call,
                ctx: $ctx->forToolCall((string) ($call['id'] ?? ''), $response->modelCallId),
                registry: $agent->tools(),
            );

            $name = CallSignature::name($call);

            $tool = $agent->tools()->all()->first(fn (Tool $registered): bool => $registered->name() === $name);

            $budget->recordCall($tool?->kind(), $result);

            $trace->observation($round, ResultSummary::of($tool, $result));

            $state = $agent->observe($state, $call, $result);

            $remaining = count($calls) - $index - 1;

            if ($remaining === 0) {
                continue;
            }

            if ($budget->allows()) {
                continue;
            }

            $trace->skipped($round, $remaining);

            break;
        }

        return $state;
    }

    private function checkpoint(AgentRun $run, AgentState $state, AgentBudget $budget, Trace $trace): void
    {
        $usage = $budget->usage();

        $run->update([
            'state' => $state->toArray(),
            'budget' => $usage,
            'trace' => $trace->toArray(),
            'rounds' => $usage['rounds'],
            'tokens' => $usage['tokens'],
            'writes' => $usage['writes'],
            'duration_ms' => (int) ($usage['seconds'] * 1000),
        ]);
    }

    private function finish(AgentRun $run, string $status, AgentState $state, AgentBudget $budget, Trace $trace, string $reason): AgentResult
    {
        $trace->findings($state->findings());

        $this->checkpoint($run, $state, $budget, $trace);

        $run->update([
            'status' => $status,
            'reason' => $reason === ''
                ? null
                : $reason,
        ]);

        if ($status === AgentResult::STATUS_DONE) {
            return AgentResult::done($state, $state->conclusion, $trace, $run->id, $budget->usage());
        }

        return AgentResult::exhausted($state, $trace, $reason, $run->id, $budget->usage());
    }
}
