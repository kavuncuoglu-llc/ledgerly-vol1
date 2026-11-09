<?php

namespace Tests\Unit\Ai\Llama;

use App\Ai\Llama\LlamaMetrics;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeLlamaServer;

class LlamaMetricsTest extends TestCase
{
    public function test_it_reads_prefixed_series_and_skips_comments_and_other_lines(): void
    {
        $metrics = LlamaMetrics::parse("# HELP llamacpp:requests_processing x\n# TYPE llamacpp:requests_processing gauge\nllamacpp:requests_processing 3\nprocess_cpu_seconds_total 9\n\n");

        $this->assertSame(3.0, $metrics->value('requests_processing'));
        $this->assertFalse($metrics->has('process_cpu_seconds_total'));
    }

    public function test_labelled_series_are_summed_under_their_name(): void
    {
        $metrics = LlamaMetrics::parse("llamacpp:spec_decode_num_accepted_tokens_per_pos_total{position=\"0\"} 5\nllamacpp:spec_decode_num_accepted_tokens_per_pos_total{position=\"1\"} 3\n");

        $this->assertSame(8.0, $metrics->value('spec_decode_num_accepted_tokens_per_pos_total'));
    }

    public function test_the_prefix_cache_ratio_is_cached_over_all_prompt_tokens(): void
    {
        $metrics = LlamaMetrics::parse(FakeLlamaServer::metrics(cached: 900, uncached: 100));

        $this->assertSame(0.9, $metrics->promptCacheHitRatio());
    }

    public function test_the_ratios_are_zero_not_a_division_error_on_a_fresh_server(): void
    {
        $metrics = LlamaMetrics::parse(FakeLlamaServer::metrics(cached: 0, uncached: 0));

        $this->assertSame(0.0, $metrics->promptCacheHitRatio());
        $this->assertSame(0.0, $metrics->draftAcceptanceRatio());
    }

    public function test_the_draft_acceptance_ratio_is_accepted_over_drafted(): void
    {
        $metrics = LlamaMetrics::parse(FakeLlamaServer::metrics(drafted: 200, accepted: 150));

        $this->assertSame(0.75, $metrics->draftAcceptanceRatio());
    }

    public function test_an_unrelated_body_is_empty(): void
    {
        $this->assertTrue(LlamaMetrics::parse('<html>not metrics</html>')->isEmpty());
    }
}
