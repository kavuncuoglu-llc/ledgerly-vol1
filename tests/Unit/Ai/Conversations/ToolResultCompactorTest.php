<?php

namespace Tests\Unit\Ai\Conversations;

use App\Ai\Conversations\ToolResultCompactor;
use App\Ai\Tools\ToolRegistry;
use Tests\Support\RefundTool;
use Tests\TestCase;

class ToolResultCompactorTest extends TestCase
{
    private ToolResultCompactor $compactor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compactor = app(ToolResultCompactor::class);
    }

    public function test_a_lookup_result_is_summarized_with_number_status_and_amount(): void
    {
        $content = json_encode([
            'ok' => true,
            'found' => true,
            'invoice' => [
                'number' => 'INV-4471',
                'status' => 'overdue',
                'issued_at' => '2026-03-02',
                'due_date' => '2026-04-02',
                'days_overdue' => 18,
                'total' => 1284.5,
                'currency' => 'USD',
                'late_fee' => 21.6,
            ],
        ]);

        $stub = json_decode($this->compactor->stubFor('lookup_invoice', $content), true);

        $this->assertTrue($stub['compacted']);
        $this->assertSame('lookup_invoice', $stub['tool']);
        $this->assertSame('returned invoice INV-4471: overdue, 18 days overdue, total USD 1284.50, due 2026-04-02', $stub['summary']);
    }

    public function test_a_lookup_that_found_nothing_says_so(): void
    {
        $stub = json_decode($this->compactor->stubFor('lookup_invoice', '{"ok":true,"found":false}'), true);

        $this->assertSame('no matching invoice found', $stub['summary']);
    }

    public function test_a_list_with_no_overdue_rows_says_none_overdue(): void
    {
        $content = "invoices[2]{number,status}:\n INV-4402,paid\n INV-4403,sent";

        $stub = json_decode($this->compactor->stubFor('list_invoices', $content), true);

        $this->assertSame('2 invoices listed; none overdue', $stub['summary']);
    }

    public function test_a_list_in_json_form_still_finds_the_overdue_numbers(): void
    {
        $content = '[{"number":"INV-4471","status":"overdue"},{"number":"INV-4402","status":"paid"}]';

        $stub = json_decode($this->compactor->stubFor('list_invoices', $content), true);

        $this->assertSame('2 invoices listed; 1 overdue: INV-4471', $stub['summary']);
    }

    public function test_the_no_matches_sentence_falls_back_to_its_first_line(): void
    {
        $stub = json_decode($this->compactor->stubFor('list_invoices', 'No invoices match.'), true);

        $this->assertSame('No invoices match.', $stub['summary']);
    }

    public function test_only_successful_read_results_are_compactable(): void
    {
        $this->assertTrue($this->compactor->isCompactable('lookup_invoice', '{"ok":true,"found":false}'));
        $this->assertFalse($this->compactor->isCompactable('lookup_invoice', '{"ok":false,"error":"not permitted"}'));
        $this->assertFalse($this->compactor->isCompactable('open_ticket', '{"ok":true,"ticket":"T-10422"}'));
        $this->assertFalse($this->compactor->isCompactable('no_such_tool', 'x'));
        $this->assertFalse($this->compactor->isCompactable('lookup_invoice', '{"compacted":true,"tool":"lookup_invoice","summary":"x"}'));
    }

    public function test_a_high_risk_write_is_never_compactable(): void
    {
        $this->app->make(ToolRegistry::class)->add(new RefundTool);

        $this->assertFalse($this->compactor->isCompactable('issue_refund', '{"ok":true}'));
    }
}
