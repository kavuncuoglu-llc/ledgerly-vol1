<?php

namespace App\Console\Commands;

use App\Ai\Agents\Trace;
use App\Models\AgentRun;
use Illuminate\Console\Command;

class AgentTrace extends Command
{
    protected $signature = 'agent:trace {run : The agent_runs id}';

    protected $description = 'Print one agent run as a readable log: every decision, tool result, budget event and the findings';

    public function handle(): int
    {
        $run = AgentRun::query()->find((int) $this->argument('run'));

        if ($run === null) {
            $this->error('No such agent run.');

            return Command::FAILURE;
        }

        $this->line($this->header($run));

        $trace = Trace::fromArray($run->agent, (string) ($run->state['id'] ?? ''), $run->trace);

        foreach ($trace->lines() as $line) {
            $this->line($line);
        }

        return Command::SUCCESS;
    }

    private function header(AgentRun $run): string
    {
        $outcome = $run->reason
            ? "{$run->status}: {$run->reason}"
            : $run->status;

        return sprintf(
            'run #%d · %s · %s · %d rounds · %d tokens · %d writes',
            $run->id,
            $run->agent,
            $outcome,
            $run->rounds,
            $run->tokens,
            $run->writes,
        );
    }
}
