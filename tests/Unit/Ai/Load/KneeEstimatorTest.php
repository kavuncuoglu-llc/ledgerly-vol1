<?php

namespace Tests\Unit\Ai\Load;

use App\Ai\Load\BenchResult;
use App\Ai\Load\KneeEstimate;
use App\Ai\Load\KneeEstimator;
use PHPUnit\Framework\TestCase;

class KneeEstimatorTest extends TestCase
{
    public function test_it_interpolates_between_the_last_level_under_the_ceiling_and_the_first_over_it(): void
    {
        $knee = (new KneeEstimator)->estimate([
            $this->level(1, 320),
            $this->level(16, 720),
            $this->level(32, 1900),
            $this->level(64, 6200),
        ], 1000);

        $this->assertSame(19, $knee->concurrency);
        $this->assertSame(KneeEstimate::WITHIN_TESTED_RANGE, $knee->basis);
    }

    public function test_the_order_the_levels_were_given_in_does_not_matter(): void
    {
        $knee = (new KneeEstimator)->estimate([
            $this->level(64, 6200),
            $this->level(1, 320),
            $this->level(32, 1900),
            $this->level(16, 720),
        ], 1000);

        $this->assertSame(19, $knee->concurrency);
    }

    public function test_every_level_under_the_ceiling_means_the_knee_was_not_reached(): void
    {
        $knee = (new KneeEstimator)->estimate([$this->level(1, 320), $this->level(8, 450)], 1000);

        $this->assertSame(8, $knee->concurrency);
        $this->assertSame(KneeEstimate::ALL_LEVELS_PASS, $knee->basis);
        $this->assertStringContainsString('knee not reached', $knee->line());
    }

    public function test_the_lowest_level_over_the_ceiling_means_no_knee(): void
    {
        $knee = (new KneeEstimator)->estimate([$this->level(1, 1500), $this->level(8, 2500)], 1000);

        $this->assertSame(0, $knee->concurrency);
        $this->assertSame(KneeEstimate::NO_LEVEL_PASSES, $knee->basis);
        $this->assertStringContainsString('already above 1000 ms', $knee->line());
    }

    public function test_a_level_with_failed_requests_does_not_pass_even_when_its_latency_does(): void
    {
        $knee = (new KneeEstimator)->estimate([
            $this->level(1, 320),
            $this->level(8, 450, failed: 2),
        ], 1000);

        $this->assertSame(1, $knee->concurrency);
        $this->assertSame(KneeEstimate::WITHIN_TESTED_RANGE, $knee->basis);
    }

    public function test_the_line_says_what_to_set_the_queue_to(): void
    {
        $knee = (new KneeEstimator)->estimate([$this->level(16, 720), $this->level(32, 1900)], 1000);

        $this->assertSame('knee ≈ 19 concurrent for TTFT p99 < 1000 ms: set AI_CONCURRENCY=19 for the ai queue', $knee->line());
    }

    public function test_a_flat_or_falling_latency_curve_does_not_divide_by_zero(): void
    {
        $knee = (new KneeEstimator)->estimate([$this->level(8, 900), $this->level(16, 900, failed: 1)], 1000);

        $this->assertSame(8, $knee->concurrency);
    }

    private function level(int $concurrency, float $ttftP99, int $failed = 0): BenchResult
    {
        return new BenchResult(
            concurrency: $concurrency,
            completed: 32,
            failed: $failed,
            requestsPerSecond: 1.0,
            outputTokensPerSecond: 100.0,
            ttftP50: $ttftP99 / 2,
            ttftP99: $ttftP99,
            itlP50: 10.0,
            itlP99: 20.0,
        );
    }
}
