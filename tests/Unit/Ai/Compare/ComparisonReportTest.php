<?php

namespace Tests\Unit\Ai\Compare;

use App\Ai\Compare\CaseOutcome;
use App\Ai\Compare\ComparisonReport;
use App\Ai\Compare\Cost;
use PHPUnit\Framework\TestCase;

class ComparisonReportTest extends TestCase
{
    public function test_a_summary_averages_quality_and_tokens_and_takes_the_median_latency(): void
    {
        $report = new ComparisonReport([
            $this->outcome('a', 'local', 'howto', 1.0, 100, 10, 1000, usd: 0.0, basis: Cost::BASIS_FREE),
            $this->outcome('b', 'local', 'bug', 0.5, 200, 20, 3000, usd: 0.0, basis: Cost::BASIS_FREE),
            $this->outcome('c', 'local', 'bug', 0.0, 300, 30, 2000, usd: 0.0, basis: Cost::BASIS_FREE),
        ]);

        $summary = $report->summary('f', 'local');

        $this->assertSame(3, $summary['cases']);
        $this->assertEqualsWithDelta(0.5, $summary['quality'], 1e-9);
        $this->assertEqualsWithDelta(200, $summary['prompt_tokens'], 1e-9);
        $this->assertEqualsWithDelta(20, $summary['completion_tokens'], 1e-9);
        $this->assertSame(2000.0, $summary['p50_ms']);
        $this->assertSame(INF, $summary['quality_per_dollar']);
    }

    public function test_cost_per_thousand_requests_and_quality_per_dollar(): void
    {
        $report = new ComparisonReport([
            $this->outcome('a', 'frontier', 'howto', 0.9, 100, 10, 1000, usd: 0.01, basis: Cost::BASIS_RATE),
            $this->outcome('b', 'frontier', 'howto', 0.9, 100, 10, 1000, usd: 0.03, basis: Cost::BASIS_RATE),
        ]);

        $summary = $report->summary('f', 'frontier');

        $this->assertEqualsWithDelta(20.0, $summary['cost_per_1k'], 1e-9);
        $this->assertEqualsWithDelta(0.045, $summary['quality_per_dollar'], 1e-9);
    }

    public function test_a_provider_with_no_price_has_no_cost_and_no_quality_per_dollar(): void
    {
        $report = new ComparisonReport([
            $this->outcome('a', 'x', 'howto', 1.0, 100, 10, 1000, usd: 0.0, basis: Cost::BASIS_UNKNOWN),
        ]);

        $summary = $report->summary('f', 'x');

        $this->assertNull($summary['cost_per_1k']);
        $this->assertNull($summary['quality_per_dollar']);
    }

    public function test_failures_count_as_zero_quality_but_not_toward_tokens_or_latency(): void
    {
        $report = new ComparisonReport([
            $this->outcome('a', 'p', 'howto', 1.0, 100, 10, 1000, usd: 0.0, basis: Cost::BASIS_FREE),
            new CaseOutcome('b', 'f', 'howto', 'p', 0.0, error: 'down'),
        ]);

        $summary = $report->summary('f', 'p');

        $this->assertSame(1, $summary['errors']);
        $this->assertEqualsWithDelta(0.5, $summary['quality'], 1e-9);
        $this->assertEqualsWithDelta(100, $summary['prompt_tokens'], 1e-9);
        $this->assertSame(1000.0, $summary['p50_ms']);
    }

    public function test_quality_by_category_and_the_json_form_survive_an_infinite_ratio(): void
    {
        $report = new ComparisonReport([
            $this->outcome('a', 'p', 'howto', 1.0, 1, 1, 1, usd: 0.0, basis: Cost::BASIS_FREE),
            $this->outcome('b', 'p', 'bug', 0.0, 1, 1, 1, usd: 0.0, basis: Cost::BASIS_FREE),
        ]);

        $this->assertSame(['bug' => 0.0, 'howto' => 1.0], $report->qualityByCategory('f', 'p'));
        $this->assertSame(['bug', 'howto'], $report->categories('f'));
        $encoded = json_decode((string) json_encode($report->toArray()), true);
        $this->assertSame('infinite', $encoded['features']['f']['providers'][0]['quality_per_dollar']);
    }

    private function outcome(string $case, string $provider, string $category, float $quality, int $in, int $out, float $ms, float $usd, string $basis): CaseOutcome
    {
        return new CaseOutcome($case, 'f', $category, $provider, $quality, promptTokens: $in, completionTokens: $out, latencyMs: $ms, cost: new Cost($usd, $basis));
    }
}
