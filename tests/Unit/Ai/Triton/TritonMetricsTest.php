<?php

namespace Tests\Unit\Ai\Triton;

use App\Ai\Triton\TritonMetrics;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeTritonServer;

class TritonMetricsTest extends TestCase
{
    public function test_it_reads_per_model_counts_and_derives_the_batch_size_and_queue_time(): void
    {
        $metrics = TritonMetrics::parse(FakeTritonServer::metrics());

        $this->assertSame(['reranker'], $metrics->models());
        $this->assertSame(48213, $metrics->successes('reranker'));
        $this->assertSame(0, $metrics->failures('reranker'));
        $this->assertEqualsWithDelta(15.54, $metrics->averageBatchSize('reranker'), 0.01);
        $this->assertEqualsWithDelta(1.896, $metrics->averageQueueMilliseconds('reranker'), 0.001);
    }

    public function test_batch_size_uses_inferences_per_execution_so_a_request_that_carries_a_batch_counts_every_row(): void
    {
        $metrics = TritonMetrics::parse(FakeTritonServer::metrics([
            'nv_inference_request_success{model="reranker",version="1"}' => 100,
            'nv_inference_count{model="reranker",version="1"}' => 2000,
            'nv_inference_exec_count{model="reranker",version="1"}' => 100,
        ]));

        $this->assertSame(20.0, $metrics->averageBatchSize('reranker'));
    }

    public function test_a_model_with_no_executions_has_a_zero_batch_size_and_no_division_error(): void
    {
        $metrics = TritonMetrics::parse("nv_inference_request_success{model=\"chat\",version=\"1\"} 0\n");

        $this->assertSame(0.0, $metrics->averageBatchSize('chat'));
        $this->assertSame(0.0, $metrics->averageQueueMilliseconds('chat'));
    }

    public function test_it_sums_a_model_across_versions(): void
    {
        $metrics = TritonMetrics::parse(implode("\n", [
            'nv_inference_request_success{model="embedder",version="1"} 10',
            'nv_inference_request_success{model="embedder",version="2"} 5',
        ]));

        $this->assertSame(15, $metrics->successes('embedder'));
    }

    public function test_it_reads_the_gpu_figures(): void
    {
        $metrics = TritonMetrics::parse(FakeTritonServer::metrics());

        $this->assertTrue($metrics->hasGpu());
        $this->assertEqualsWithDelta(0.71, $metrics->gpuUtilization(), 0.001);
        $this->assertEqualsWithDelta(0.75, $metrics->gpuMemoryUsedFraction(), 0.001);
    }

    public function test_help_lines_and_foreign_series_are_ignored(): void
    {
        $metrics = TritonMetrics::parse("# HELP nv_x help\n# TYPE nv_x counter\nvllm:num_requests_running 3\n");

        $this->assertTrue($metrics->isEmpty());
        $this->assertFalse($metrics->hasGpu());
        $this->assertSame(0.0, $metrics->gpuUtilization());
    }

    public function test_label_values_may_contain_escaped_quotes(): void
    {
        $metrics = TritonMetrics::parse('nv_inference_request_success{model="odd\"name",version="1"} 4'."\n");

        $this->assertSame(['odd"name'], $metrics->models());
    }
}
