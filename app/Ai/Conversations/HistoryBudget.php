<?php

namespace App\Ai\Conversations;

use App\Ai\Prompt\Budget;
use App\Ai\Tokens\TokenCounter;

final class HistoryBudget
{
    public function __construct(
        private readonly TokenCounter $counter,
        private readonly Budget $budget,
    ) {}

    public function historyAllowance(string $systemText): int
    {
        $config = config('ai.budget');

        $system = $this->counter->count($systemText);

        $reserved = $config['answer'] + $system + $this->budget->margin() + $config['context'];

        return max(0, $config['window'] - $reserved);
    }
}
