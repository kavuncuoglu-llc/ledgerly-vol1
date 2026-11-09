<?php

namespace Tests\Unit\Ai\Streaming;

use App\Ai\Streaming\DeltaBatcher;
use PHPUnit\Framework\TestCase;

class DeltaBatcherTest extends TestCase
{
    private array $flushed = [];

    private float $now = 0.0;

    public function test_push_holds_deltas_until_a_threshold_is_reached(): void
    {
        $batcher = $this->batcher();

        $batcher->push('a');
        $batcher->push('b');

        $this->assertSame([], $this->flushed);
    }

    public function test_push_flushes_the_joined_text_once_the_delta_count_is_reached(): void
    {
        $batcher = $this->batcher(maxDeltas: 3);

        $batcher->push('a');
        $batcher->push('b');
        $batcher->push('c');

        $this->assertSame(['abc'], $this->flushed);
    }

    public function test_push_flushes_when_the_time_window_has_passed(): void
    {
        $batcher = $this->batcher(maxMilliseconds: 50);

        $batcher->push('a');
        $this->now = 49.0;
        $batcher->push('b');
        $this->now = 51.0;
        $batcher->push('c');

        $this->assertSame(['abc'], $this->flushed);
    }

    public function test_the_time_window_restarts_after_a_flush(): void
    {
        $batcher = $this->batcher(maxMilliseconds: 50, maxDeltas: 2);

        $batcher->push('a');
        $this->now = 100.0;
        $batcher->push('b');
        $this->now = 140.0;
        $batcher->push('c');
        $this->now = 151.0;
        $batcher->push('d');

        $this->assertSame(['ab', 'cd'], $this->flushed);
    }

    public function test_flush_now_sends_the_remainder(): void
    {
        $batcher = $this->batcher();

        $batcher->push('tail');
        $batcher->flushNow();

        $this->assertSame(['tail'], $this->flushed);
    }

    public function test_flush_now_with_nothing_buffered_does_not_call_the_callback(): void
    {
        $batcher = $this->batcher();

        $batcher->flushNow();
        $batcher->flushNow();

        $this->assertSame([], $this->flushed);
    }

    public function test_a_hundred_deltas_become_five_frames_at_twenty_per_frame(): void
    {
        $batcher = $this->batcher(maxDeltas: 20);

        foreach (range(1, 100) as $number) {
            $batcher->push('x');
        }

        $batcher->flushNow();

        $this->assertCount(5, $this->flushed);
    }

    private function batcher(int $maxMilliseconds = 50, int $maxDeltas = 20): DeltaBatcher
    {
        $this->flushed = [];
        $this->now = 0.0;

        return new DeltaBatcher(
            flush: function (string $text): void {
                $this->flushed[] = $text;
            },
            maxMilliseconds: $maxMilliseconds,
            maxDeltas: $maxDeltas,
            clock: fn (): float => $this->now,
        );
    }
}
