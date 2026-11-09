<?php

namespace App\Ai\Load;

final class KneeEstimator
{
    public function estimate(array $results, float $ceilingMs): KneeEstimate
    {
        usort($results, fn (BenchResult $a, BenchResult $b): int => $a->concurrency <=> $b->concurrency);

        $lastPassing = null;

        foreach ($results as $result) {
            if (! $this->passes($result, $ceilingMs)) {
                return $this->between($lastPassing, $result, $ceilingMs);
            }

            $lastPassing = $result;
        }

        return new KneeEstimate(
            concurrency: $lastPassing?->concurrency ?? 0,
            basis: KneeEstimate::ALL_LEVELS_PASS,
            ceilingMs: $ceilingMs,
        );
    }

    private function between(?BenchResult $passing, BenchResult $failing, float $ceilingMs): KneeEstimate
    {
        if ($passing === null) {
            return new KneeEstimate(0, KneeEstimate::NO_LEVEL_PASSES, $ceilingMs);
        }

        return new KneeEstimate(
            concurrency: $this->interpolated($passing, $failing, $ceilingMs),
            basis: KneeEstimate::WITHIN_TESTED_RANGE,
            ceilingMs: $ceilingMs,
        );
    }

    private function interpolated(BenchResult $passing, BenchResult $failing, float $ceilingMs): int
    {
        if ($failing->ttftP99 <= $ceilingMs) {
            return $passing->concurrency;
        }

        $rise = $failing->ttftP99 - $passing->ttftP99;

        if ($rise <= 0.0) {
            return $passing->concurrency;
        }

        $share = ($ceilingMs - $passing->ttftP99) / $rise;

        $span = $failing->concurrency - $passing->concurrency;

        return max($passing->concurrency, (int) floor($passing->concurrency + $share * $span));
    }

    private function passes(BenchResult $result, float $ceilingMs): bool
    {
        if ($result->failed > 0) {
            return false;
        }

        return $result->ttftP99 <= $ceilingMs;
    }
}
