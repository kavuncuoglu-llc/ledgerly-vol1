<?php

namespace Tests\Unit\Ai\Agents;

use App\Ai\Agents\ResultSummary;
use App\Ai\Tools\AccountStatus\AccountStatus;
use App\Ai\Tools\OpenTicket\OpenTicket;
use App\Ai\Tools\ToolResult;
use PHPUnit\Framework\TestCase;

class ResultSummaryTest extends TestCase
{
    public function test_a_tool_that_compacts_its_results_supplies_the_summary(): void
    {
        $result = ToolResult::ok([
            'plan' => 'pro',
            'overdue_invoices' => 2,
            'oldest_overdue_days' => 41,
            'outstanding_invoices' => 1,
        ]);

        $this->assertSame('plan pro; 2 overdue invoices (oldest 41 days); 1 outstanding', ResultSummary::of(new AccountStatus, $result));
    }

    public function test_other_ok_results_list_their_values(): void
    {
        $result = ToolResult::ok(['ticket_number' => 'T-10422', 'created' => false]);

        $this->assertSame("ok, ticket_number: 'T-10422', created: false", ResultSummary::of(new OpenTicket, $result));
    }

    public function test_a_text_result_is_cut_to_its_first_line(): void
    {
        $this->assertSame('invoices[2]{number}:', ResultSummary::of(null, ToolResult::text("invoices[2]{number}:\nINV-1\nINV-2")));
    }

    public function test_errors_carry_the_validation_reasons_so_the_model_can_correct_itself(): void
    {
        $result = ToolResult::error('invalid arguments', ['month' => ['The month field must match the format Y-m.']]);

        $this->assertSame('error: invalid arguments (month: The month field must match the format Y-m.)', ResultSummary::of(null, $result));
    }

    public function test_denied_declined_and_pending_results_say_so(): void
    {
        $this->assertSame('denied: limit reached', ResultSummary::of(null, ToolResult::denied('limit reached')));
        $this->assertSame('declined', ResultSummary::of(null, ToolResult::declined()));
        $this->assertSame('awaiting approval: Refund $20', ResultSummary::of(null, ToolResult::pending('p1', 'Refund $20', 'http://x')));
    }

    public function test_long_summaries_are_cut(): void
    {
        $summary = ResultSummary::of(null, ToolResult::ok(['note' => str_repeat('a', 400)]));

        $this->assertLessThanOrEqual(ResultSummary::LIMIT + 3, mb_strlen($summary));
    }
}
