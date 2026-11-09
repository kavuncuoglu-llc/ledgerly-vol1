<?php

namespace Tests\Unit\Ai\Conversations;

use App\Ai\Conversations\HistoryBudget;
use App\Ai\Prompt\Budget;
use App\Ai\Tokens\RatioCounter;
use App\Ai\Tokens\TokenCounter;
use Tests\TestCase;

class HistoryBudgetTest extends TestCase
{
    public function test_allowance_is_the_window_minus_answer_measured_system_margin_and_context(): void
    {
        $budget = new HistoryBudget(new RatioCounter(charsPerToken: 1.0), new Budget);

        $allowance = $budget->historyAllowance(str_repeat('a', 300));

        $this->assertSame(8192 - (400 + 300 + 410 + 3500), $allowance);
    }

    public function test_allowance_shrinks_as_the_system_text_grows(): void
    {
        $budget = new HistoryBudget(new RatioCounter(charsPerToken: 1.0), new Budget);

        $short = $budget->historyAllowance(str_repeat('a', 100));
        $long = $budget->historyAllowance(str_repeat('a', 400));

        $this->assertSame(300, $short - $long);
    }

    public function test_allowance_never_goes_negative(): void
    {
        $budget = new HistoryBudget(new RatioCounter(charsPerToken: 1.0), new Budget);

        $this->assertSame(0, $budget->historyAllowance(str_repeat('a', 20000)));
    }

    public function test_allowance_uses_the_bound_token_counter_by_default(): void
    {
        $budget = app(HistoryBudget::class);
        $text = str_repeat('word ', 50);

        $expected = 8192 - (400 + app(TokenCounter::class)->count($text) + 410 + 3500);

        $this->assertSame($expected, $budget->historyAllowance($text));
    }
}
