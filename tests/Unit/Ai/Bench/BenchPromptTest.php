<?php

namespace Tests\Unit\Ai\Bench;

use App\Ai\Bench\BenchPrompt;
use PHPUnit\Framework\TestCase;

class BenchPromptTest extends TestCase
{
    public function test_messages_put_the_data_and_the_question_in_the_user_turn(): void
    {
        $messages = BenchPrompt::messages('DATA', 'Which invoice is largest?');

        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame("DATA\n\nQuestion: Which invoice is largest?", $messages[1]['content']);
    }

    public function test_matches_finds_an_invoice_number_inside_a_sentence(): void
    {
        $this->assertTrue(BenchPrompt::matches('The largest is INV-4532.', 'INV-4532'));
        $this->assertTrue(BenchPrompt::matches('inv-4532', 'INV-4532'));
    }

    public function test_matches_does_not_accept_a_longer_number_that_merely_contains_the_expected_one(): void
    {
        $this->assertFalse(BenchPrompt::matches('INV-45321', 'INV-4532'));
    }

    public function test_matches_compares_numbers_by_value_not_spelling(): void
    {
        $this->assertTrue(BenchPrompt::matches('$32,312.51', '32312.51'));
        $this->assertTrue(BenchPrompt::matches('There are 21 overdue invoices.', '21'));
        $this->assertTrue(BenchPrompt::matches('640.00', '640'));
    }

    public function test_matches_ignores_numbers_that_belong_to_dates_and_invoice_numbers(): void
    {
        $this->assertFalse(BenchPrompt::matches('INV-4532 due 2026-06-30', '30'));
        $this->assertFalse(BenchPrompt::matches('INV-21', '21'));
    }

    public function test_matches_rejects_a_wrong_number(): void
    {
        $this->assertFalse(BenchPrompt::matches('There are 12 overdue invoices.', '21'));
    }

    public function test_matches_finds_a_date_and_a_status(): void
    {
        $this->assertTrue(BenchPrompt::matches('Due 2026-07-27', '2026-07-27'));
        $this->assertTrue(BenchPrompt::matches('Status: overdue', 'overdue'));
        $this->assertFalse(BenchPrompt::matches('paid', 'overdue'));
    }
}
