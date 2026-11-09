<?php

namespace Tests\Unit\Ai\Tokens;

use App\Ai\Tokens\RatioCounter;
use PHPUnit\Framework\TestCase;

class RatioCounterTest extends TestCase
{
    public function test_count_empty_string_returns_zero(): void
    {
        $counter = new RatioCounter;

        $count = $counter->count('');

        $this->assertSame(0, $count);
    }

    public function test_count_rounds_up_to_whole_tokens(): void
    {
        $counter = new RatioCounter(charsPerToken: 4.0);

        $count = $counter->count('Invoice #4471 is overdue by 30 days.');

        $this->assertSame(9, $count);
    }

    public function test_count_uses_default_ratio_of_three_point_six(): void
    {
        $counter = new RatioCounter;

        $count = $counter->count(str_repeat('a', 36));

        $this->assertSame(10, $count);
    }

    public function test_count_measures_characters_not_bytes(): void
    {
        $counter = new RatioCounter(charsPerToken: 1.0);

        $count = $counter->count('日本語');

        $this->assertSame(3, $count);
    }
}
