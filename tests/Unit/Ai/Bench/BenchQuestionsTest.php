<?php

namespace Tests\Unit\Ai\Bench;

use App\Ai\Bench\BenchFixture;
use App\Ai\Bench\BenchQuestions;
use App\Ai\Exceptions\UnusableBenchmark;
use Tests\TestCase;

class BenchQuestionsTest extends TestCase
{
    public function test_the_fixture_holds_one_hundred_twenty_invoices_with_distinct_numbers_and_totals(): void
    {
        $fixture = BenchFixture::fromFile($this->fixturePath());

        $this->assertCount(120, $fixture->invoices);
        $this->assertCount(120, $fixture->invoices->pluck('number')->unique());
        $this->assertCount(120, $fixture->invoices->pluck('total')->unique());
    }

    public function test_for_asks_twenty_questions_with_expected_answers(): void
    {
        $fixture = BenchFixture::fromFile($this->fixturePath());

        $questions = $fixture->within(fn (): array => BenchQuestions::for($fixture->invoices));

        $this->assertCount(20, $questions);

        foreach ($questions as $question) {
            $this->assertNotSame('', $question['question']);
            $this->assertNotSame('', $question['expected']);
        }
    }

    public function test_the_shipped_questions_file_matches_the_questions_generated_for_the_full_fixture(): void
    {
        $fixture = BenchFixture::fromFile($this->fixturePath());

        $generated = $fixture->within(fn (): array => BenchQuestions::for($fixture->invoices));

        $shipped = json_decode((string) file_get_contents(storage_path('benchmarks/invoice-questions.json')), true);

        $this->assertSame($generated, $shipped);
    }

    public function test_for_computes_the_answers_from_the_rows_it_is_given(): void
    {
        $fixture = BenchFixture::fromFile($this->fixturePath());

        $few = $fixture->within(fn (): array => BenchQuestions::for($fixture->first(30)));
        $all = $fixture->within(fn (): array => BenchQuestions::for($fixture->invoices));

        $this->assertNotSame($few, $all);
        $this->assertSame('How many invoices are overdue?', $few[4]['question']);
        $this->assertLessThan((int) $all[4]['expected'], (int) $few[4]['expected']);
    }

    public function test_the_largest_total_answer_is_the_invoice_with_the_largest_total(): void
    {
        $fixture = BenchFixture::fromFile($this->fixturePath());

        $questions = $fixture->within(fn (): array => BenchQuestions::for($fixture->invoices));

        $largest = $fixture->invoices->sortByDesc(fn ($invoice): float => (float) $invoice->total)->first();

        $this->assertSame($largest->number, $questions[0]['expected']);
    }

    public function test_for_refuses_too_few_rows(): void
    {
        $fixture = BenchFixture::fromFile($this->fixturePath());

        $this->expectException(UnusableBenchmark::class);

        BenchQuestions::for($fixture->first(5));
    }

    public function test_for_refuses_rows_without_an_overdue_invoice(): void
    {
        $fixture = BenchFixture::fromFile($this->fixturePath());

        $paidOnly = $fixture->invoices->reject(fn ($invoice): bool => $invoice->status->value === 'overdue')->values();

        $this->expectException(UnusableBenchmark::class);

        BenchQuestions::for($paidOnly);
    }

    public function test_within_pins_the_clock_and_restores_it_afterwards(): void
    {
        $fixture = BenchFixture::fromFile($this->fixturePath());

        $inside = $fixture->within(fn (): string => now()->toDateString());

        $this->assertSame('2026-06-30', $inside);
        $this->assertNotSame('2026-06-30', now()->toDateString());
    }

    public function test_from_file_reports_a_missing_fixture(): void
    {
        $this->expectException(UnusableBenchmark::class);

        BenchFixture::fromFile('/nonexistent/invoices.json');
    }

    private function fixturePath(): string
    {
        return storage_path('benchmarks/invoices.json');
    }
}
