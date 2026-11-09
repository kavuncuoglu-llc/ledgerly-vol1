<?php

namespace Tests\Unit\Ai\Profile;

use App\Ai\Profile\MemorySample;
use App\Ai\Profile\ProfileReport;
use App\Ai\Profile\ProfileTurn;
use Tests\TestCase;

class ProfileReportTest extends TestCase
{
    public function test_a_turn_derives_prefill_and_decode_rates_from_its_timestamps(): void
    {
        $turn = $this->turn(index: 1, cold: false, start: 10.0, first: 10.5, end: 12.5, prompt: 400, completion: 41);

        $this->assertSame(0.5, $turn->timeToFirstToken());
        $this->assertSame(800.0, $turn->prefillTokensPerSecond());
        $this->assertSame(20.0, $turn->decodeTokensPerSecond());
    }

    public function test_a_cold_turn_has_no_prefill_rate_because_the_wait_includes_loading(): void
    {
        $turn = $this->turn(index: 0, cold: true, start: 0.0, first: 4.0, end: 6.0, prompt: 400, completion: 21);

        $this->assertSame(0.0, $turn->prefillTokensPerSecond());
        $this->assertSame(4.0, $turn->timeToFirstToken());
    }

    public function test_without_usage_the_decode_rate_falls_back_to_counting_deltas(): void
    {
        $turn = new ProfileTurn(0, false, 0.0, 1.0, 3.0, 0, 0, [1.0, 1.5, 2.0, 2.5, 3.0]);

        $this->assertSame(2.0, $turn->decodeTokensPerSecond());
    }

    public function test_rows_name_the_phase_of_each_sample(): void
    {
        $report = new ProfileReport(
            [$this->turn(0, true, 100.0, 104.0, 108.0, 50, 41), $this->turn(1, false, 108.5, 109.0, 111.0, 300, 41)],
            [
                $this->sample(99.5, 100, 0),
                $this->sample(101.0, 400, 0),
                $this->sample(106.0, 9_000, 8_000),
                $this->sample(108.2, 9_000, 8_000),
                $this->sample(108.8, 9_000, 8_000),
                $this->sample(110.0, 9_000, 8_000),
            ],
            startedAt: 99.0,
        );

        $phases = array_column($report->rows(), 'phase');

        $this->assertSame(['idle', 'load', 'decode', 'idle', 'prefill', 'decode'], $phases);
    }

    public function test_rows_carry_rates_and_a_growing_context_during_decode(): void
    {
        $turn = new ProfileTurn(0, false, 0.0, 1.0, 5.0, 100, 40, array_map(fn (int $i): float => 1.0 + $i * 0.1, range(0, 39)));
        $report = new ProfileReport([$turn], [$this->sample(1.5, 1, 1), $this->sample(4.5, 1, 1)], startedAt: 0.0);

        [$early, $late] = $report->rows();

        $this->assertSame(1.5, $early['second']);
        $this->assertSame(9.75, round($early['decode_tokens_per_second'], 2));
        $this->assertGreaterThan(100, $early['context_tokens']);
        $this->assertGreaterThan($early['context_tokens'], $late['context_tokens']);
        $this->assertSame(136, $late['context_tokens']);
    }

    public function test_the_summary_uses_medians_ignores_the_cold_prefill_and_compares_with_the_ceiling(): void
    {
        $turns = [
            $this->turn(0, true, 0.0, 6.0, 10.0, 50, 41),
            $this->turn(1, false, 10.0, 10.5, 14.5, 400, 41),
            $this->turn(2, false, 15.0, 15.2, 17.2, 600, 21),
            $this->turn(3, false, 18.0, 18.1, 20.1, 600, 41),
        ];
        $report = new ProfileReport($turns, [$this->sample(5.0, 10_000, 8_000), $this->sample(12.0, 12_000, 9_000, spilled: 500)], startedAt: 0.0);

        $summary = $report->summary(weightsBytes: 5_000_000_000, bandwidthGbps: 200);

        $this->assertSame(6.0, $summary['load_to_first_token']);
        $this->assertEqualsWithDelta(3000.0, $summary['prefill_tokens_per_second'], 0.001);
        $this->assertSame(10.0, $summary['decode_tokens_per_second']);
        $this->assertSame(40.0, $summary['decode_ceiling_tokens_per_second']);
        $this->assertSame(0.25, $summary['decode_share_of_ceiling']);
        $this->assertSame(12_000, $summary['peak_resident_bytes']);
        $this->assertSame(9_000, $summary['peak_gpu_bytes']);
        $this->assertSame(500, $summary['spilled_bytes']);
    }

    public function test_without_a_bandwidth_there_is_no_ceiling(): void
    {
        $report = new ProfileReport([$this->turn(0, false, 0.0, 1.0, 3.0, 10, 21)], [], startedAt: 0.0);

        $summary = $report->summary(weightsBytes: 5_000_000_000, bandwidthGbps: 0);

        $this->assertSame(0.0, $summary['decode_ceiling_tokens_per_second']);
        $this->assertSame(0.0, $summary['decode_share_of_ceiling']);
    }

    public function test_a_failed_turn_is_counted_and_has_no_rates(): void
    {
        $failed = new ProfileTurn(1, false, 5.0, 0.0, 6.0, 0, 0, [], error: 'boom');
        $report = new ProfileReport([$this->turn(0, false, 0.0, 1.0, 3.0, 10, 21), $failed], [], startedAt: 0.0);

        $summary = $report->summary(weightsBytes: 0, bandwidthGbps: 0);

        $this->assertSame(1, $summary['failed']);
        $this->assertSame(2, $summary['turns']);
        $this->assertSame(10.0, $summary['decode_tokens_per_second']);
    }

    private function turn(int $index, bool $cold, float $start, float $first, float $end, int $prompt, int $completion): ProfileTurn
    {
        $deltas = $completion > 0
            ? array_map(fn (int $i): float => $first + ($end - $first) * $i / max(1, $completion - 1), range(0, $completion - 1))
            : [];

        return new ProfileTurn($index, $cold, $start, $first, $end, $prompt, $completion, $deltas);
    }

    private function sample(float $at, int $resident, int $gpu, int $spilled = 0): MemorySample
    {
        return new MemorySample($at, $resident, $gpu, $spilled, $gpu > 0);
    }
}
