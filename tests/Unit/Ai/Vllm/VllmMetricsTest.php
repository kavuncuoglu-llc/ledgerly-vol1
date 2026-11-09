<?php

namespace Tests\Unit\Ai\Vllm;

use App\Ai\Vllm\VllmMetrics;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeVllmServer;

class VllmMetricsTest extends TestCase
{
    public function test_it_reads_vllm_series_and_skips_comments_and_other_lines(): void
    {
        $metrics = VllmMetrics::parse("# HELP vllm:num_requests_running x\n# TYPE vllm:num_requests_running gauge\nvllm:num_requests_running{engine=\"0\",model_name=\"ledgerly-support\"} 3.0\npython_gc_objects_collected_total{generation=\"0\"} 9\n\n");

        $this->assertSame(3.0, $metrics->sum('num_requests_running'));
        $this->assertFalse($metrics->has('python_gc_objects_collected'));
    }

    public function test_the_total_suffix_of_a_counter_is_dropped_from_its_name(): void
    {
        $metrics = VllmMetrics::parse("vllm:prompt_tokens_total{engine=\"0\"} 5200.0\n");

        $this->assertSame(5200.0, $metrics->sum('prompt_tokens'));
        $this->assertFalse($metrics->has('prompt_tokens_total'));
    }

    public function test_series_with_different_labels_are_summed(): void
    {
        $metrics = VllmMetrics::parse("vllm:request_success_total{engine=\"0\",finished_reason=\"stop\"} 5\nvllm:request_success_total{engine=\"0\",finished_reason=\"length\"} 2\n");

        $this->assertSame(7.0, $metrics->sum('request_success'));
    }

    public function test_labels_survive_commas_and_escaped_quotes_inside_values(): void
    {
        $metrics = VllmMetrics::parse('vllm:lora_requests_info{max_lora="4",running_lora_adapters="a,b",waiting_lora_adapters="say \\"hi\\""} 1.0'."\n");

        $labels = $metrics->labels('lora_requests_info');

        $this->assertSame('4', $labels['max_lora']);
        $this->assertSame('a,b', $labels['running_lora_adapters']);
        $this->assertSame('say "hi"', $labels['waiting_lora_adapters']);
    }

    public function test_a_value_that_is_not_a_number_is_skipped(): void
    {
        $metrics = VllmMetrics::parse("vllm:kv_cache_usage_perc{engine=\"0\"} NaN\n");

        $this->assertTrue($metrics->isEmpty());
    }

    public function test_the_prefix_cache_ratio_is_hits_over_queries(): void
    {
        $metrics = VllmMetrics::parse(FakeVllmServer::metrics());

        $this->assertSame(0.9, $metrics->prefixCacheHitRatio());
    }

    public function test_the_ratio_is_zero_not_a_division_error_on_a_fresh_server(): void
    {
        $metrics = VllmMetrics::parse(FakeVllmServer::metrics(['series' => ['prefix_cache_queries_total' => 0, 'prefix_cache_hits_total' => 0]]));

        $this->assertSame(0.0, $metrics->prefixCacheHitRatio());
    }

    public function test_the_capacity_comes_from_the_cache_config_labels(): void
    {
        $metrics = VllmMetrics::parse(FakeVllmServer::metrics());

        $this->assertSame(212480, $metrics->kvCacheTokens());
        $this->assertSame(25.94, $metrics->kvCacheMaxConcurrency());
        $this->assertSame('on', $metrics->prefixCaching());
    }

    public function test_capacity_falls_back_to_blocks_times_block_size(): void
    {
        $metrics = VllmMetrics::parse(FakeVllmServer::metrics(['cache_config' => ['kv_cache_size_tokens' => 'None', 'kv_cache_max_concurrency' => 'None']]));

        $this->assertSame(13280 * 16, $metrics->kvCacheTokens());
        $this->assertSame(0.0, $metrics->kvCacheMaxConcurrency());
    }

    public function test_capacity_is_zero_when_the_cache_config_is_missing(): void
    {
        $metrics = VllmMetrics::parse("vllm:num_requests_running{engine=\"0\"} 0\n");

        $this->assertSame(0, $metrics->kvCacheTokens());
        $this->assertSame('unknown', $metrics->prefixCaching());
    }

    public function test_prefix_caching_reads_false_as_off(): void
    {
        $metrics = VllmMetrics::parse(FakeVllmServer::metrics(['cache_config' => ['enable_prefix_caching' => 'False']]));

        $this->assertSame('off', $metrics->prefixCaching());
    }
}
