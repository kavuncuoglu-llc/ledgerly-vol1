<?php

namespace Tests\Feature\Ai;

use App\Ai\Exceptions\PromptTooLarge;
use App\Ai\Prompt\Budget;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Tokens\TokenCounter;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    public function test_history_allowance_matches_the_book_budget_for_an_8192_window(): void
    {
        $budget = new Budget;

        $allowance = $budget->historyAllowance();

        $this->assertSame(3432, $allowance);
    }

    public function test_margin_is_five_percent_of_the_window_rounded_up(): void
    {
        $budget = new Budget;

        $margin = $budget->margin();

        $this->assertSame(410, $margin);
    }

    public function test_margin_never_drops_below_two_hundred_tokens(): void
    {
        config()->set('ai.budget.window', 2000);

        $margin = (new Budget)->margin();

        $this->assertSame(200, $margin);
    }

    public function test_every_part_of_the_budget_adds_up_to_the_window(): void
    {
        $budget = new Budget;

        $total = config('ai.budget.answer')
            + config('ai.budget.system')
            + config('ai.budget.context')
            + $budget->margin()
            + $budget->historyAllowance();

        $this->assertSame(config('ai.budget.window'), $total);
    }

    public function test_the_support_system_prompt_fits_its_budget(): void
    {
        $tokens = app(TokenCounter::class)->count(SupportPrompt::system());

        $this->assertLessThanOrEqual(config('ai.budget.system'), $tokens);
    }

    public function test_assert_fits_accepts_a_prompt_that_leaves_room_for_the_answer(): void
    {
        $budget = new Budget;

        $budget->assertFits(7792);

        $this->addToAssertionCount(1);
    }

    public function test_assert_fits_rejects_a_prompt_that_leaves_no_room_for_the_answer(): void
    {
        $budget = new Budget;

        $this->expectException(PromptTooLarge::class);

        $budget->assertFits(7793);
    }
}
