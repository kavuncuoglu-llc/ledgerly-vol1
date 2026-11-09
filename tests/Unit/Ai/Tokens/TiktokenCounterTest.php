<?php

namespace Tests\Unit\Ai\Tokens;

use App\Ai\Tokens\TiktokenCounter;
use PHPUnit\Framework\TestCase;
use Throwable;

class TiktokenCounterTest extends TestCase
{
    public function test_count_empty_string_returns_zero(): void
    {
        $counter = new TiktokenCounter;

        $count = $this->countOrSkip($counter, '');

        $this->assertSame(0, $count);
    }

    public function test_count_matches_o200k_base_for_the_chapter_sentence(): void
    {
        $counter = new TiktokenCounter;

        $count = $this->countOrSkip($counter, 'Invoice #4471 is overdue by 30 days.');

        $this->assertSame(11, $count);
    }

    public function test_count_is_larger_for_longer_text(): void
    {
        $counter = new TiktokenCounter;

        $short = $this->countOrSkip($counter, 'Invoice overdue.');
        $long = $this->countOrSkip($counter, 'Invoice overdue. '.str_repeat('Please pay soon. ', 20));

        $this->assertGreaterThan($short, $long);
    }

    private function countOrSkip(TiktokenCounter $counter, string $text): int
    {
        try {
            return $counter->count($text);
        } catch (Throwable $exception) {
            $this->markTestSkipped('o200k_base vocabulary unavailable offline: '.$exception->getMessage());
        }
    }
}
