<?php

namespace App\Ai\Prompt;

use App\Ai\Exceptions\PromptTooLarge;

final class Budget
{
    public function historyAllowance(): int
    {
        $budget = config('ai.budget');

        return $budget['window'] - (
            $budget['answer']
            + $budget['system']
            + $this->margin()
            + $budget['context']
        );
    }

    public function margin(): int
    {
        $budget = config('ai.budget');

        return max(200, (int) ceil($budget['window'] * $budget['margin_pct']));
    }

    public function assertFits(int $promptTokens): void
    {
        $budget = config('ai.budget');

        if ($promptTokens + $budget['answer'] > $budget['window']) {
            throw new PromptTooLarge($promptTokens, $budget['window']);
        }
    }
}
